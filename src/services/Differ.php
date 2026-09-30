<?php

namespace webdna\auditor\services;

use Closure;
use Craft;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\db\Connection;
use craft\db\Query;
use craft\db\Table as CraftTable;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\Tag;
use craft\elements\User;
use craft\fields\BaseRelationField;
use craft\helpers\Db;
use craft\helpers\Json;
use DateTimeInterface;
use Throwable;
use webdna\auditor\Auditor;
use webdna\auditor\events\DefineTrackedAttributesEvent;
use webdna\auditor\models\Settings;
use webdna\auditor\models\Snapshot;
use yii\base\Component;

/**
 * Works out what a save actually changed.
 *
 * A diff compares **candidates** only: the element's dirty fields plus its type's tracked
 * attributes (BR-7, BR-9). "Dirty" in Craft means a value was set, not that it changed — a site
 * form that posts every field marks every field dirty — so a candidate whose stored and new values
 * are equal is dropped.
 *
 * The before-state is read from the stored rows and never by loading the element (BR-8):
 * {@see snapshot()} runs one query over the element's content row joined to its attribute tables,
 * plus one per attribute kept elsewhere (an entry's authors), and only when there are candidates.
 * Call it before the save writes those rows; call {@see diff()} after.
 *
 * Craft stores each field as `serializeValueForDb()` produced it, keyed by the field's layout
 * element, and MySQL's JSON type reorders object keys; so the new value is serialised the same way
 * and both sides are compared in a canonical form (key order ignored, empty values equal). A field
 * whose stored form still differs is given a second chance: the stored value is normalised through
 * the field and serialised again, which settles a value stored in an older shape (BR-8's fallback).
 *
 * Fields with no column of their own (Matrix, Content Block, Addresses — `dbType()` null) are not
 * compared on the owner: their nested elements are saved, and recorded, themselves (BR-12).
 *
 * Names in the `redact` setting are recorded as `{label, redacted: true}` wherever they appear —
 * created rows, save diffs and event payloads alike (BR-10).
 *
 * @since 1.0.0
 */
class Differ extends Component
{
    /**
     * Raised with a {@see DefineTrackedAttributesEvent} the first time an element type is diffed.
     */
    public const EVENT_DEFINE_TRACKED_ATTRIBUTES = 'defineTrackedAttributes';

    /**
     * Null means Craft's own connection — the one the element's rows were written on.
     */
    public ?Connection $db = null;

    /**
     * Null means the plugin's settings.
     */
    public ?Settings $settings = null;

    /**
     * @var array<class-string<ElementInterface>, array<string, string|Closure>>
     */
    private array $tracked = [];

    /**
     * The element's stored state for its candidates, read before the save writes it. Null for an
     * element not yet saved, which has no stored state and is recorded as created instead (BR-11).
     */
    public function snapshot(ElementInterface $element): ?Snapshot
    {
        if (!$element->id || !$element->siteId) {
            return null;
        }

        $fields = [];
        foreach ($this->comparableFields($element) as $field) {
            if ($element->isFieldDirty($field->handle)) {
                $fields[$field->handle] = $field;
            }
        }

        $attributes = $this->trackedAttributes($element::class);
        $columns = array_filter($attributes, 'is_string');
        $storedFields = [];
        $storedAttributes = [];

        if ($fields || $columns) {
            $row = $this->storedRow($element, $columns, (bool)$fields) ?? [];

            if ($fields) {
                $content = $row['__content'] ?? null;
                $content = is_string($content) && $content !== '' ? Json::decode($content) : [];
                foreach ($fields as $handle => $field) {
                    $uid = $field->layoutElement?->uid;
                    if ($uid !== null && is_array($content) && array_key_exists($uid, $content)) {
                        $storedFields[$handle] = $content[$uid];
                    }
                }
            }

            foreach (array_keys($columns) as $name) {
                $storedAttributes[$name] = $row[$name] ?? null;
            }

            $storedFields += $this->storedRelations($element, array_diff_key($fields, $storedFields));
        }

        foreach ($attributes as $name => $source) {
            if ($source instanceof Closure) {
                $storedAttributes[$name] = $source($element);
            }
        }

        return new Snapshot($element->id, $element->siteId, array_keys($fields), $storedFields, $storedAttributes);
    }

    /**
     * What the save changed, as `{name: {label, old, new}}` — or `{label, redacted: true}` for a
     * redacted name. Empty when nothing changed.
     *
     * It needs nothing but the snapshot and the element's values, so it may run after Craft has
     * marked the element clean.
     *
     * @return array<string, array<string, mixed>>
     */
    public function diff(ElementInterface $element, Snapshot $before): array
    {
        $changes = [];

        foreach ($before->storedAttributes as $name => $stored) {
            $new = $this->attributeValue($element, $name);
            $old = $this->castLike($stored, $new);
            if ($this->canonical($old) !== $this->canonical($new)) {
                $changes[$name] = $this->entry($name, $element->getAttributeLabel($name), $old, $new);
            }
        }

        $layout = $element->getFieldLayout();
        foreach ($before->fields as $handle) {
            $field = $layout?->getFieldByHandle($handle);
            if ($field === null) {
                continue;
            }
            $old = $before->storedFields[$handle] ?? null;
            $new = $this->fieldValue($element, $field);
            if ($this->fieldChanged($element, $field, $old, $new)) {
                $changes[$handle] = $this->entry($handle, $this->fieldLabel($field), $old, $new);
            }
        }

        return $changes;
    }

    /**
     * A new element's non-empty field values and tracked attributes, as `{name: {label, old: null,
     * new}}` (BR-11), redacted as {@see diff()} is.
     *
     * @return array<string, array<string, mixed>>
     */
    public function created(ElementInterface $element): array
    {
        $values = [];

        foreach (array_keys($this->trackedAttributes($element::class)) as $name) {
            $new = $this->attributeValue($element, $name);
            if ($this->canonical($new) !== null) {
                $values[$name] = $this->entry($name, $element->getAttributeLabel($name), null, $new);
            }
        }

        foreach ($this->comparableFields($element) as $field) {
            // The field's own test first, as Craft's save uses: a Table's default row is empty.
            if ($field->isValueEmpty($element->getFieldValue($field->handle), $element)) {
                continue;
            }
            $new = $this->fieldValue($element, $field);
            if ($this->canonical($new) !== null) {
                $values[$field->handle] = $this->entry($field->handle, $this->fieldLabel($field), null, $new);
            }
        }

        return $values;
    }

    /**
     * An event payload with every redacted key's value replaced by `{redacted: true}`, at any depth
     * (BR-10).
     *
     * @param array<array-key, mixed> $payload
     * @return array<array-key, mixed>
     */
    public function redact(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (is_string($key) && $this->isRedacted($key)) {
                $payload[$key] = ['redacted' => true];
            } elseif (is_array($value)) {
                $payload[$key] = $this->redact($value);
            }
        }

        return $payload;
    }

    public function isRedacted(string $name): bool
    {
        return in_array($name, $this->getSettings()->redact, true);
    }

    /**
     * The tracked attributes of an element type and where each is stored (BR-9), after
     * {@see EVENT_DEFINE_TRACKED_ATTRIBUTES}.
     *
     * @param class-string<ElementInterface> $elementType
     * @return array<string, string|Closure>
     */
    public function trackedAttributes(string $elementType): array
    {
        if (!isset($this->tracked[$elementType])) {
            $event = new DefineTrackedAttributesEvent([
                'elementType' => $elementType,
                'attributes' => self::defaultTrackedAttributes($elementType),
            ]);
            if ($this->hasEventHandlers(self::EVENT_DEFINE_TRACKED_ATTRIBUTES)) {
                $this->trigger(self::EVENT_DEFINE_TRACKED_ATTRIBUTES, $event);
            }
            $this->tracked[$elementType] = $event->attributes;
        }

        return $this->tracked[$elementType];
    }

    /**
     * BR-9's defaults for Craft's own element types. Commerce's are added by its watcher (6.1).
     *
     * @param class-string<ElementInterface> $elementType
     * @return array<string, string|Closure>
     */
    public static function defaultTrackedAttributes(string $elementType): array
    {
        return match (true) {
            is_a($elementType, Entry::class, true) => [
                'title' => 'elements_sites.title',
                'slug' => 'elements_sites.slug',
                'enabled' => 'elements.enabled',
                'enabledForSite' => 'elements_sites.enabled',
                'postDate' => 'entries.postDate',
                'expiryDate' => 'entries.expiryDate',
                'authorIds' => static fn(ElementInterface $element): array => array_map('intval', (new Query())
                    ->select(['authorId'])
                    ->from(CraftTable::ENTRIES_AUTHORS)
                    ->where(['entryId' => $element->id])
                    ->orderBy(['sortOrder' => SORT_ASC])
                    ->column()),
                'typeId' => 'entries.typeId',
                'sectionId' => 'entries.sectionId',
            ],
            is_a($elementType, User::class, true) => [
                'email' => 'users.email',
                'username' => 'users.username',
                'fullName' => 'users.fullName',
                'firstName' => 'users.firstName',
                'lastName' => 'users.lastName',
                'admin' => 'users.admin',
                'photoId' => 'users.photoId',
            ],
            is_a($elementType, Asset::class, true) => [
                'title' => 'elements_sites.title',
                'filename' => 'assets.filename',
                'folderId' => 'assets.folderId',
                'volumeId' => 'assets.volumeId',
                // Per site when the volume translates it, otherwise on the asset.
                'alt' => static fn(ElementInterface $element): mixed => (new Query())
                    ->select(['alt' => 'COALESCE([[assets_sites.alt]], [[assets.alt]])'])
                    ->from(['assets' => CraftTable::ASSETS])
                    ->leftJoin(['assets_sites' => CraftTable::ASSETS_SITES], [
                        'and',
                        '[[assets_sites.assetId]] = [[assets.id]]',
                        ['assets_sites.siteId' => $element->siteId],
                    ])
                    ->where(['assets.id' => $element->id])
                    ->scalar(),
            ],
            is_a($elementType, Category::class, true), is_a($elementType, Tag::class, true) => [
                'title' => 'elements_sites.title',
                'slug' => 'elements_sites.slug',
                'enabled' => 'elements.enabled',
            ],
            default => [],
        };
    }

    /**
     * The one query for a snapshot: the element's site row, its element row, and every other table
     * a tracked column names, joined on the element id.
     *
     * @param array<string, string> $columns
     * @return array<string, mixed>|null
     */
    private function storedRow(ElementInterface $element, array $columns, bool $withContent): ?array
    {
        $query = (new Query())
            ->from(['elements_sites' => CraftTable::ELEMENTS_SITES])
            ->innerJoin(['elements' => CraftTable::ELEMENTS], '[[elements.id]] = [[elements_sites.elementId]]')
            ->where([
                'elements_sites.elementId' => $element->id,
                'elements_sites.siteId' => $element->siteId,
            ]);

        $select = $withContent ? ['__content' => 'elements_sites.content'] : [];
        $joined = ['elements' => true, 'elements_sites' => true];

        foreach ($columns as $name => $column) {
            [$table] = explode('.', $column, 2);
            if (!isset($joined[$table])) {
                $query->leftJoin([$table => "{{%$table}}"], "[[$table.id]] = [[elements.id]]");
                $joined[$table] = true;
            }
            $select[$name] = $column;
        }

        /** @var array<string, mixed>|null */
        return $query->select($select)->one($this->db) ?: null;
    }

    /**
     * The stored ids of relation fields that have nothing in the content row.
     *
     * Craft has mirrored relations into the content row only since 5.3, so an element not saved
     * since then — or since the field joined its layout — has no content value for the field, and
     * Craft reads the `relations` table instead (`BaseRelationField::normalizeValue()`). So does
     * this, in one query for all such fields, and before the save rewrites that table. As in Craft,
     * only the first instance of a field in the layout takes the table's value.
     *
     * @param array<string, FieldInterface> $fields Candidate fields with no stored content, by handle
     * @return array<string, int[]>
     */
    private function storedRelations(ElementInterface $element, array $fields): array
    {
        $handles = [];
        foreach ($fields as $handle => $field) {
            if ($field instanceof BaseRelationField && $field->id && !isset($handles[$field->id])) {
                $handles[$field->id] = $handle;
            }
        }
        if ($handles === []) {
            return [];
        }

        $rows = (new Query())
            ->select(['fieldId', 'targetId'])
            ->from(CraftTable::RELATIONS)
            ->where([
                'sourceId' => $element->id,
                'fieldId' => array_keys($handles),
            ])
            ->andWhere(['or', ['sourceSiteId' => null], ['sourceSiteId' => $element->siteId]])
            ->orderBy(['sortOrder' => SORT_ASC])
            ->all($this->db);

        $stored = array_fill_keys($handles, []);
        foreach ($rows as $row) {
            $stored[$handles[(int)$row['fieldId']]][] = (int)$row['targetId'];
        }

        return $stored;
    }

    /**
     * The layout fields a diff can compare: those with a stored value of their own.
     *
     * @return FieldInterface[]
     */
    private function comparableFields(ElementInterface $element): array
    {
        $fields = $element->getFieldLayout()?->getCustomFields() ?? [];

        return array_values(array_filter($fields, fn(FieldInterface $field) => $field::dbType() !== null));
    }

    /**
     * The field's new value exactly as Craft stores it: `serializeValueForDb()`, through JSON.
     */
    private function fieldValue(ElementInterface $element, FieldInterface $field): mixed
    {
        return $this->throughJson($field->serializeValueForDb($element->getFieldValue($field->handle), $element));
    }

    private function fieldChanged(ElementInterface $element, FieldInterface $field, mixed $old, mixed $new): bool
    {
        if ($this->canonical($old) === $this->canonical($new)) {
            return false;
        }

        // A relation's stored form is its ids and cannot be stale; normalising it would cost a query.
        if ($old === null || $field instanceof BaseRelationField) {
            return true;
        }

        try {
            $again = $this->throughJson($field->serializeValueForDb($field->normalizeValue($old, $element), $element));
        } catch (Throwable) {
            return true;
        }

        return $this->canonical($again) !== $this->canonical($new);
    }

    private function attributeValue(ElementInterface $element, string $name): mixed
    {
        // An attribute a plugin tracks on a type that lacks it reads as empty, not as an error.
        if (!$element->canGetProperty($name)) {
            return null;
        }
        $value = $element->$name;

        if ($value instanceof DateTimeInterface) {
            return Db::prepareDateForDb($value);
        }

        return is_object($value) ? $this->throughJson($value) : $value;
    }

    /**
     * A stored column in the new value's type — MySQL hands back `'1'` for a boolean and a string
     * for an id.
     */
    private function castLike(mixed $stored, mixed $new): mixed
    {
        if ($stored === null) {
            return null;
        }
        if (is_bool($new)) {
            return (bool)$stored;
        }
        if (is_int($new) && is_numeric($stored)) {
            return (int)$stored;
        }
        if (is_string($new) && is_scalar($stored)) {
            return (string)$stored;
        }
        // The new value is empty or a list: an id column still reads as an id.
        if (is_string($stored) && preg_match('/^-?[1-9]\d{0,17}$|^0$/', $stored)) {
            return (int)$stored;
        }

        return $stored;
    }

    /**
     * The comparison form: object keys sorted, whole floats as integers, and `null`, `''`, `[]` and an
     * array of nothing but empties all "empty".
     */
    private function canonical(mixed $value): mixed
    {
        if ($value === '' || $value === []) {
            return null;
        }
        if (is_float($value) && floor($value) === $value && abs($value) < 1e15) {
            return (int)$value;
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                $out[$key] = $this->canonical($item);
            }
            // A Table's blank default row is `[{col1: null, …}]`: nothing in it, so empty.
            if (array_filter($out, fn($item) => $item !== null) === []) {
                return null;
            }
            if (!array_is_list($out)) {
                ksort($out);
            }
            return $out;
        }

        return $value;
    }

    private function throughJson(mixed $value): mixed
    {
        return $value === null || is_scalar($value) ? $value : Json::decode(Json::encode($value));
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(string $name, string $label, mixed $old, mixed $new): array
    {
        if ($this->isRedacted($name)) {
            return ['label' => $label, 'redacted' => true];
        }

        return ['label' => $label, 'old' => $old, 'new' => $new];
    }

    private function fieldLabel(FieldInterface $field): string
    {
        return $field->layoutElement?->label() ?? $field->name ?? $field->handle;
    }

    private function getSettings(): Settings
    {
        return $this->settings ?? Auditor::getInstance()->getSettings();
    }
}
