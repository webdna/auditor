<?php

namespace webdna\auditor\services;

use Craft;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\elements\User;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\models\UserGroup;
use DateInterval;
use webdna\auditor\db\Table;
use webdna\auditor\models\Event;
use webdna\auditor\models\Filter;
use webdna\auditor\watchers\AuthWatcher;
use webdna\auditor\watchers\ElementWatcher;
use yii\base\Component;
use yii\helpers\Inflector;

/**
 * Reads the log back: a page of rows at a time, one row, and the words each row is shown in.
 *
 * Nothing here writes. Values reach the templates as plain strings, and the templates escape them
 * (BR-30).
 *
 * @since 1.0.0
 */
class Events extends Component
{
    /**
     * Rows per page of the log.
     */
    public const PAGE_SIZE = 50;

    /**
     * Every event each family writes, in the order the filter lists them.
     */
    public const EVENTS = [
        Event::FAMILY_ELEMENT => [
            ElementWatcher::EVENT_CREATED,
            ElementWatcher::EVENT_SAVED,
            ElementWatcher::EVENT_DELETED,
            ElementWatcher::EVENT_RESTORED,
            ElementWatcher::EVENT_MOVED,
            ElementWatcher::EVENT_BULK,
        ],
        Event::FAMILY_AUTH => [
            AuthWatcher::EVENT_LOGIN,
            AuthWatcher::EVENT_LOGIN_FAILED,
            AuthWatcher::EVENT_LOGOUT,
            AuthWatcher::EVENT_IMPERSONATED,
            AuthWatcher::EVENT_LOCKED,
            AuthWatcher::EVENT_UNLOCKED,
            AuthWatcher::EVENT_SUSPENDED,
            AuthWatcher::EVENT_UNSUSPENDED,
            AuthWatcher::EVENT_ACTIVATED,
            AuthWatcher::EVENT_DEACTIVATED,
            AuthWatcher::EVENT_EMAIL_CHANGED,
            AuthWatcher::EVENT_EMAIL_VERIFIED,
            AuthWatcher::EVENT_PASSWORD_CHANGED,
            AuthWatcher::EVENT_PASSWORD_RESET_REQUIRED,
            AuthWatcher::EVENT_MFA_ENABLED,
            AuthWatcher::EVENT_MFA_DISABLED,
            AuthWatcher::EVENT_PASSKEY_ADDED,
            AuthWatcher::EVENT_PASSKEY_REMOVED,
        ],
        Event::FAMILY_ACCESS => [
            AuthWatcher::EVENT_ADMIN_CHANGED,
            AuthWatcher::EVENT_GROUPS_CHANGED,
            AuthWatcher::EVENT_PERMISSIONS_CHANGED,
            AuthWatcher::EVENT_GROUP_SAVED,
            AuthWatcher::EVENT_GROUP_DELETED,
        ],
    ];

    /**
     * Each context, as the log names it.
     */
    public const CONTEXTS = [
        'cp' => 'Control panel',
        'site' => 'Site',
        'console' => 'Command',
        'queue' => 'Background job',
    ];

    private const FAMILY_LABELS = [
        Event::FAMILY_ELEMENT => 'Content',
        Event::FAMILY_AUTH => 'Sign-in',
        Event::FAMILY_ACCESS => 'Access',
    ];

    private const EVENT_LABELS = [
        ElementWatcher::EVENT_CREATED => 'Created',
        ElementWatcher::EVENT_SAVED => 'Saved',
        ElementWatcher::EVENT_DELETED => 'Deleted',
        ElementWatcher::EVENT_RESTORED => 'Restored',
        ElementWatcher::EVENT_MOVED => 'Moved',
        ElementWatcher::EVENT_BULK => 'Batch',
        AuthWatcher::EVENT_LOGIN => 'Signed in',
        AuthWatcher::EVENT_LOGIN_FAILED => 'Failed sign-in',
        AuthWatcher::EVENT_LOGOUT => 'Signed out',
        AuthWatcher::EVENT_IMPERSONATED => 'Signed in as user',
        AuthWatcher::EVENT_LOCKED => 'Locked',
        AuthWatcher::EVENT_UNLOCKED => 'Unlocked',
        AuthWatcher::EVENT_SUSPENDED => 'Suspended',
        AuthWatcher::EVENT_UNSUSPENDED => 'Unsuspended',
        AuthWatcher::EVENT_ACTIVATED => 'Activated',
        AuthWatcher::EVENT_DEACTIVATED => 'Deactivated',
        AuthWatcher::EVENT_EMAIL_CHANGED => 'Email changed',
        AuthWatcher::EVENT_EMAIL_VERIFIED => 'Email verified',
        AuthWatcher::EVENT_PASSWORD_CHANGED => 'Password changed',
        AuthWatcher::EVENT_PASSWORD_RESET_REQUIRED => 'Password reset required',
        AuthWatcher::EVENT_MFA_ENABLED => 'Two-step verification added',
        AuthWatcher::EVENT_MFA_DISABLED => 'Two-step verification removed',
        AuthWatcher::EVENT_PASSKEY_ADDED => 'Passkey added',
        AuthWatcher::EVENT_PASSKEY_REMOVED => 'Passkey removed',
        AuthWatcher::EVENT_ADMIN_CHANGED => 'Admin changed',
        AuthWatcher::EVENT_GROUPS_CHANGED => 'Groups changed',
        AuthWatcher::EVENT_PERMISSIONS_CHANGED => 'Permissions changed',
        AuthWatcher::EVENT_GROUP_SAVED => 'User group saved',
        AuthWatcher::EVENT_GROUP_DELETED => 'User group deleted',
    ];

    /**
     * Payload keys shown under a name of their own; any other key is its words, sentence case.
     */
    private const KEY_LABELS = [
        'action' => 'Move',
        'targetLabel' => 'Next to',
        'operation' => 'Operation',
        'count' => 'Elements',
        'elementType' => 'Element type',
        'username' => 'Username tried',
        'reason' => 'Reason',
        'isNew' => 'New group',
        'partial' => 'Partial',
        'method' => 'Method',
        'name' => 'Name',
        'group' => 'Group',
    ];

    /**
     * Payload keys that only repeat what a label beside them says better.
     */
    private const HIDDEN_KEYS = ['structureId', 'targetId'];

    /**
     * Payload keys whose value names a class — an element type, a two-step method, an action or a
     * job — shown by that class's own name while it exists.
     */
    private const CLASS_KEYS = ['elementType', 'method', 'operation'];

    /**
     * A batch's operation when it is one of the element service's own (BR-14).
     */
    private const OPERATION_LABELS = [
        'resave' => 'Re-save',
        'create' => 'Create',
        'save' => 'Save',
        'delete' => 'Delete',
        'restore' => 'Restore',
        'move' => 'Move',
    ];

    /**
     * A structure move's action, `Structures::ACTION_*`.
     */
    private const MOVE_LABELS = [
        'prepend' => 'To the start',
        'append' => 'To the end',
        'placeBefore' => 'Before',
        'placeAfter' => 'After',
    ];

    /**
     * One page of the log, newest first, and the key of the next page — null when there is none.
     *
     * Paged by key, never by offset and never counted (BR-29): the cost of a page does not grow
     * with the table.
     *
     * @return array{events: list<Event>, after: int|null}
     */
    public function page(Filter $filter, ?int $after = null, int $limit = self::PAGE_SIZE): array
    {
        $query = $this->query($filter)
            ->orderBy(['id' => SORT_DESC])
            ->limit($limit + 1);

        if ($after !== null) {
            $query->andWhere(['<', 'id', $after]);
        }

        $rows = $query->all();
        $more = count($rows) > $limit;
        $events = array_map(fn(array $row) => $this->hydrate($row), array_slice($rows, 0, $limit));
        $last = end($events);

        return [
            'events' => $events,
            'after' => $more && $last ? $last->id : null,
        ];
    }

    public function get(int $id): ?Event
    {
        $row = (new Query())->from(Table::EVENTS)->where(['id' => $id])->one();

        return $row ? $this->hydrate($row) : null;
    }

    /**
     * The query behind a page, narrowed by the filter.
     *
     * @return Query<int, array<string, mixed>>
     */
    public function query(Filter $filter): Query
    {
        $query = (new Query())->from(Table::EVENTS);

        foreach (['family', 'event', 'elementType', 'actorId', 'context', 'siteId'] as $column) {
            if ($filter->$column !== null) {
                $query->andWhere([$column => $filter->$column]);
            }
        }

        if ($filter->request !== null) {
            $query->andWhere(['requestId' => $filter->request]);
        }

        if ($filter->from !== null) {
            $query->andWhere(['>=', 'dateCreated', Db::prepareDateForDb($filter->from)]);
        }

        if ($filter->to !== null) {
            // The last day is shown whole: everything before the start of the next.
            $end = (clone $filter->to)->add(new DateInterval('P1D'));
            $query->andWhere(['<', 'dateCreated', Db::prepareDateForDb($end)]);
        }

        if ($filter->search !== null) {
            // Labels only (BR-29) — never the logged values, which may hold what was redacted elsewhere.
            $query->andWhere([
                'or',
                ['like', 'elementLabel', $filter->search],
                ['like', 'actorLabel', $filter->search],
            ]);
        }

        return $query;
    }

    public function eventLabel(string $event): string
    {
        return Craft::t('auditor', self::EVENT_LABELS[$event] ?? $event);
    }

    public function familyLabel(string $family): string
    {
        return Craft::t('auditor', self::FAMILY_LABELS[$family] ?? $family);
    }

    public function contextLabel(?string $context): string
    {
        return $context === null ? '' : Craft::t('auditor', self::CONTEXTS[$context] ?? $context);
    }

    /**
     * An element type's name, or a user group's, or the class's own name when it is gone.
     */
    public function typeLabel(?string $type): string
    {
        if ($type === null || $type === '') {
            return '';
        }
        if ($type === UserGroup::class) {
            return Craft::t('auditor', 'User group');
        }

        return $this->displayName($type) ?? $type;
    }

    public function siteLabel(?int $siteId): string
    {
        $site = $siteId ? Craft::$app->getSites()->getSiteById($siteId, true) : null;

        return $site ? Craft::t('site', $site->getName()) : '';
    }

    /**
     * The filter's options, each list headed by an empty "All" choice.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function filterOptions(): array
    {
        $all = ['label' => Craft::t('auditor', 'All'), 'value' => ''];

        $families = [$all];
        $events = [$all];
        foreach (self::EVENTS as $family => $names) {
            $families[] = ['label' => $this->familyLabel($family), 'value' => $family];
            $events[] = ['optgroup' => $this->familyLabel($family)];
            foreach ($names as $name) {
                $events[] = ['label' => $this->eventLabel($name), 'value' => $name];
            }
        }

        $contexts = [$all];
        foreach (array_keys(self::CONTEXTS) as $context) {
            $contexts[] = ['label' => $this->contextLabel($context), 'value' => $context];
        }

        $types = [];
        foreach (Craft::$app->getElements()->getAllElementTypes() as $type) {
            $types[] = ['label' => $type::pluralDisplayName(), 'value' => $type];
        }
        $types[] = ['label' => Craft::t('auditor', 'User groups'), 'value' => UserGroup::class];
        usort($types, fn($a, $b) => strcasecmp($a['label'], $b['label']));

        $sites = [$all];
        foreach (Craft::$app->getSites()->getAllSites(true) as $site) {
            $sites[] = ['label' => Craft::t('site', $site->getName()), 'value' => $site->id];
        }

        return [
            'family' => $families,
            'event' => $events,
            'context' => $contexts,
            'elementType' => [$all, ...$types],
            'siteId' => $sites,
        ];
    }

    /**
     * The control panel address of each row's subject that still exists and the viewer may see,
     * keyed by row id: one query per element type on the page. A deleted subject has none, and
     * its row shows only the label it was recorded with (TN-5).
     *
     * @param list<Event> $events
     * @return array<int, string>
     */
    public function subjectUrls(array $events, ?User $viewer): array
    {
        $ids = [];
        foreach ($events as $event) {
            if ($event->elementId !== null && $event->elementType !== null && $this->isElementType($event->elementType)) {
                $ids[$event->elementType][$event->elementId] = true;
            }
        }

        $urls = [];
        foreach ($ids as $type => $elementIds) {
            /** @var class-string<ElementInterface> $type */
            $elements = $type::find()
                ->id(array_keys($elementIds))
                ->site('*')
                ->unique()
                ->status(null)
                ->all();

            foreach ($elements as $element) {
                if ($viewer === null || !Craft::$app->getElements()->canView($element, $viewer)) {
                    continue;
                }
                $url = $element->getCpEditUrl();
                if ($url !== null) {
                    $urls[$type][(int)$element->id] = $url;
                }
            }
        }

        $byEvent = [];
        foreach ($events as $event) {
            $url = $urls[$event->elementType][$event->elementId] ?? null;
            if ($url !== null && $event->id !== null) {
                $byEvent[$event->id] = $url;
            }
        }

        return $byEvent;
    }

    /**
     * Who a row's impersonator was, by their address now, or by id once they are gone.
     */
    public function impersonatorLabel(Event $event): ?string
    {
        if ($event->impersonatorId === null) {
            return null;
        }

        $user = User::find()->id($event->impersonatorId)->status(null)->one();

        return $user->email ?? Craft::t('auditor', 'User #{id}', ['id' => $event->impersonatorId]);
    }

    /**
     * What the slideout shows for a row's data: `changes`, each with a before and an after, and
     * `details`, each a single value. Every value is already a string.
     *
     * A save's or a creation's fields are changes, keyed by handle; any other payload is flattened
     * — an `{old, new}` pair anywhere in it is a change, and everything else a detail.
     *
     * @return array{
     *     changes: list<array{key: string, label: string, old: string, new: string, redacted: bool}>,
     *     details: list<array{key: string, label: string, value: string, redacted: bool}>,
     * }
     */
    public function describe(Event $event): array
    {
        $described = ['changes' => [], 'details' => []];
        $data = $event->data ?? [];

        if ($event->family === Event::FAMILY_ELEMENT && in_array($event->event, [ElementWatcher::EVENT_SAVED, ElementWatcher::EVENT_CREATED], true)) {
            foreach ($data as $key => $entry) {
                $entry = is_array($entry) ? $entry : [];
                $described['changes'][] = [
                    'key' => (string)$key,
                    'label' => (string)($entry['label'] ?? $key),
                    'old' => $this->format($entry['old'] ?? null),
                    'new' => $this->format($entry['new'] ?? null),
                    'redacted' => ($entry['redacted'] ?? false) === true,
                ];
            }

            return $described;
        }

        if ($event->event === AuthWatcher::EVENT_PERMISSIONS_CHANGED) {
            $data = $this->permissionChanges($data);
        }

        $this->flatten($data, '', '', $described);

        return $described;
    }

    /**
     * A value as one line of text, or pretty-printed JSON for anything structured.
     */
    public function format(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? Craft::t('auditor', 'Yes') : Craft::t('auditor', 'No');
        }
        if (is_scalar($value)) {
            return (string)$value;
        }
        if (is_array($value) && array_is_list($value) && array_filter($value, fn($item) => !is_scalar($item) && $item !== null) === []) {
            return implode(', ', array_map(fn($item) => $this->format($item), $value));
        }

        return Json::encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * A permissions row as what was granted and what was taken away, rather than two long lists;
     * a `partial` row, whose old set was not readable, as the new set alone.
     *
     * @param array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    private function permissionChanges(array $data): array
    {
        $new = is_array($data['new'] ?? null) ? $data['new'] : [];
        if (!is_array($data['old'] ?? null)) {
            return ['permissions' => $new, 'partial' => true];
        }
        $old = $data['old'];

        return [
            'added' => array_values(array_diff($new, $old)),
            'removed' => array_values(array_diff($old, $new)),
        ];
    }

    /**
     * @param array<array-key, mixed> $data
     * @param array{changes: list<array<string, mixed>>, details: list<array<string, mixed>>} $described
     */
    private function flatten(array $data, string $prefix, string $keyPrefix, array &$described): void
    {
        foreach ($data as $key => $value) {
            $key = (string)$key;
            if (in_array($key, self::HIDDEN_KEYS, true)) {
                continue;
            }
            $label = $prefix === '' ? $this->keyLabel($key) : $prefix . ' ' . mb_strtolower($this->keyLabel($key));
            $path = $keyPrefix . $key;

            if (is_array($value) && ($value['redacted'] ?? false) === true) {
                $described['details'][] = ['key' => $path, 'label' => $label, 'value' => '', 'redacted' => true];
            } elseif (is_array($value) && $this->isChange($value)) {
                $described['changes'][] = [
                    'key' => $path,
                    'label' => $label,
                    'old' => $this->format($value['old'] ?? null),
                    'new' => $this->format($value['new'] ?? null),
                    'redacted' => false,
                ];
            } elseif ($key === 'group' && is_array($value) && isset($value['name'])) {
                $name = (string)$value['name'];
                $described['details'][] = [
                    'key' => $path,
                    'label' => $label,
                    'value' => isset($value['handle']) ? "$name ({$value['handle']})" : $name,
                    'redacted' => false,
                ];
            } elseif (is_array($value) && $value !== [] && !array_is_list($value)) {
                // A group's `changes` are its own fields; any other nested map is named by its key.
                $this->flatten($value, $key === 'changes' ? $prefix : $label, "$path.", $described);
            } else {
                $text = match (true) {
                    $key === 'operation' && is_string($value) && isset(self::OPERATION_LABELS[$value]) => Craft::t('auditor', self::OPERATION_LABELS[$value]),
                    $key === 'action' && is_string($value) && isset(self::MOVE_LABELS[$value]) => Craft::t('auditor', self::MOVE_LABELS[$value]),
                    is_string($value) && in_array($key, self::CLASS_KEYS, true) => $this->displayName($value) ?? $value,
                    default => $this->format($value),
                };
                $described['details'][] = ['key' => $path, 'label' => $label, 'value' => $text, 'redacted' => false];
            }
        }
    }

    /**
     * @param array<array-key, mixed> $value
     */
    private function isChange(array $value): bool
    {
        return $value !== []
            && (array_key_exists('old', $value) || array_key_exists('new', $value))
            && array_diff(array_keys($value), ['old', 'new']) === [];
    }

    private function keyLabel(string $key): string
    {
        return Craft::t('auditor', self::KEY_LABELS[$key] ?? ucfirst(mb_strtolower(Inflector::camel2words($key, false))));
    }

    /**
     * A class's display name, when the string names a class that has one — an element type, or a
     * two-step method.
     */
    private function displayName(string $class): ?string
    {
        if (!str_contains($class, '\\') || !class_exists($class) || !method_exists($class, 'displayName')) {
            return null;
        }

        return (string)$class::displayName();
    }

    private function isElementType(string $type): bool
    {
        return class_exists($type) && is_subclass_of($type, ElementInterface::class);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Event
    {
        $event = new Event();
        foreach (['id', 'elementId', 'siteId', 'ownerId', 'actorId', 'impersonatorId'] as $column) {
            $event->$column = isset($row[$column]) ? (int)$row[$column] : null;
        }
        foreach (['family', 'event'] as $column) {
            $event->$column = (string)$row[$column];
        }
        foreach (['elementType', 'elementLabel', 'actorLabel', 'context', 'contextDetail', 'ip', 'userAgent', 'requestId', 'uid'] as $column) {
            $event->$column = isset($row[$column]) ? (string)$row[$column] : null;
        }

        $data = $row['data'] ?? null;
        $data = is_string($data) ? Json::decodeIfJson($data) : $data;
        $event->data = is_array($data) ? $data : null;
        $event->dateCreated = DateTimeHelper::toDateTime($row['dateCreated']) ?: null;

        return $event;
    }
}
