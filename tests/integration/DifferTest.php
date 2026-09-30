<?php

namespace webdna\auditor\tests\integration;

use Closure;
use Codeception\Test\Unit;
use Craft;
use craft\base\ElementInterface;
use craft\db\Table as CraftTable;
use craft\elements\Entry;
use craft\elements\User;
use craft\events\ElementEvent;
use craft\helpers\Json;
use craft\services\Elements;
use DateTime;
use DateTimeZone;
use webdna\auditor\events\DefineTrackedAttributesEvent;
use webdna\auditor\models\Settings;
use webdna\auditor\models\Snapshot;
use webdna\auditor\services\Differ;
use webdna\auditor\tests\Support\TestSite;
use Yii;
use yii\base\Event;
use yii\log\Logger;

/**
 * The differ against real saves: every core field type round-trips through its stored form, so an
 * unchanged value is never a change and a changed one always is (BR-7, BR-8); tracked attributes are
 * compared explicitly (BR-9); redacted names never carry their value (BR-10, TS-5).
 *
 * The saves run on Craft's own connection, inside the harness's per-test transaction, so the stored
 * rows the differ reads are the test's own and are rolled back with it.
 */
class DifferTest extends Unit
{
    private TestSite $site;

    private Differ $differ;

    protected function _before(): void
    {
        $this->site = TestSite::build();
        $this->differ = new Differ(['settings' => new Settings(['redact' => ['secret', 'password']])]);
    }

    protected function _after(): void
    {
        TestSite::removeFiles();
    }

    /**
     * @return array<string, array{string, Closure(TestSite, Entry, int): array{mixed, mixed}}>
     */
    public static function fieldTypes(): array
    {
        return [
            'plain text' => ['plainText', fn() => ['Before', 'After']],
            'plain text with markup and emoji' => ['plainText', fn() => ['<p>Café 🎉</p>', '<p>Café 🎈</p>']],
            'number' => ['number', fn() => [5, 7.25]],
            'dropdown' => ['dropdown', fn() => ['red', 'blue']],
            'multi-select' => ['multiSelect', fn() => [['one', 'two'], ['one', 'three']]],
            'lightswitch' => ['lightswitch', fn() => [true, false]],
            'date' => ['date', fn() => [
                new DateTime('2026-01-02 10:30:00', new DateTimeZone('UTC')),
                new DateTime('2026-03-04 16:00:00', new DateTimeZone('UTC')),
            ]],
            'entries relation' => ['related', fn(TestSite $site, Entry $other) => [[$other->id], []]],
            'assets relation' => ['files', fn(TestSite $site, Entry $other, int $assetId) => [[$assetId], []]],
            'money' => ['money', fn() => [['value' => '12.50', 'currency' => 'GBP'], ['value' => '99.00', 'currency' => 'GBP']]],
            'link' => ['website', fn() => [
                ['type' => 'url', 'value' => 'https://before.example.com'],
                ['type' => 'url', 'value' => 'https://after.example.com'],
            ]],
            'table' => ['table', fn() => [
                [['col1' => 'Alpha', 'col2' => 1]],
                [['col1' => 'Alpha', 'col2' => 2], ['col1' => 'Beta', 'col2' => 3]],
            ]],
        ];
    }

    /**
     * Setting every field of a stored entry to the value it already holds changes nothing — the
     * site-form case of AC-2 — and setting a new value is recorded with both values.
     *
     * @param Closure(TestSite, Entry, int): array{mixed, mixed} $values
     * @dataProvider fieldTypes
     */
    public function testAFieldTypeRoundTripsThroughItsStoredForm(string $handle, Closure $values): void
    {
        $other = $this->site->entry('Other');
        $asset = $this->site->asset('file.txt');
        [$before, $after] = $values($this->site, $other, (int)$asset->id);

        $entry = $this->site->entry('Subject', [$handle => $before]);

        $same = $this->fresh($entry);
        $same->setFieldValue($handle, $before);
        $this->assertTrue($same->isFieldDirty($handle));
        $this->assertSame([], $this->saveAndDiff($same), 'An unchanged value must not be a change');

        $changed = $this->fresh($entry);
        $changed->setFieldValue($handle, $after);
        $diff = $this->saveAndDiff($changed);

        $this->assertSame([$handle], array_keys($diff));
        $this->assertNotEquals($diff[$handle]['old'], $diff[$handle]['new']);
        $this->assertSame($this->site->fields[$handle]->name, $diff[$handle]['label']);

        // The recorded old value is the one stored before; saving it back again is no change.
        $restored = $this->fresh($entry);
        $restored->setFieldValue($handle, $after);
        $this->assertSame([], $this->saveAndDiff($restored));
    }

    /**
     * A form re-posting every field with one changed records that field only (AC-2).
     */
    public function testEveryFieldDirtyButOneChangedRecordsOnlyThatOne(): void
    {
        $values = ['plainText' => 'Same', 'number' => 3, 'dropdown' => 'red', 'lightswitch' => true, 'multiSelect' => ['two']];
        $entry = $this->site->entry('Subject', $values);

        $posted = $this->fresh($entry);
        $posted->setFieldValues(['dropdown' => 'blue'] + $values);
        $diff = $this->saveAndDiff($posted);

        $this->assertSame(['dropdown'], array_keys($diff));
        $this->assertSame(['label' => 'Dropdown', 'old' => 'red', 'new' => 'blue'], $diff['dropdown']);
    }

    /**
     * Title and enabled, changed together, are the whole diff (AC-1's diff, without the CP).
     */
    public function testTrackedAttributesAreComparedExplicitly(): void
    {
        $entry = $this->site->entry('Old title', ['plainText' => 'Kept']);

        $edited = $this->fresh($entry);
        $edited->title = 'New title';
        $edited->enabled = false;
        $diff = $this->saveAndDiff($edited);

        $this->assertEqualsCanonicalizing(['title', 'enabled'], array_keys($diff));
        $this->assertSame(['label' => 'Title', 'old' => 'Old title', 'new' => 'New title'], $diff['title']);
        $this->assertSame(['label' => 'Enabled', 'old' => true, 'new' => false], $diff['enabled']);
    }

    /**
     * Post date and authors are tracked though Craft marks neither dirty.
     */
    public function testPostDateAndAuthorsAreTracked(): void
    {
        $first = $this->site->user('first');
        $second = $this->site->user('second');
        $entry = $this->site->entry('Dated', [], $first);

        $edited = $this->fresh($entry);
        $edited->postDate = new DateTime('2030-06-01 09:00:00', new DateTimeZone('UTC'));
        $edited->setAuthorIds([$first->id, $second->id]);
        $diff = $this->saveAndDiff($edited);

        $this->assertEqualsCanonicalizing(['postDate', 'authorIds'], array_keys($diff));
        $this->assertSame('2030-06-01 09:00:00', $diff['postDate']['new']);
        $this->assertSame([$first->id], $diff['authorIds']['old']);
        $this->assertSame([$first->id, $second->id], $diff['authorIds']['new']);
    }

    /**
     * A user's email and admin flag are compared from the users table.
     */
    public function testUserAttributesAreTracked(): void
    {
        $user = $this->site->user('person');

        $edited = Craft::$app->getUsers()->getUserById((int)$user->id);
        $this->assertInstanceOf(User::class, $edited);
        $edited->email = 'changed@example.com';
        $edited->admin = true;
        $diff = $this->saveAndDiff($edited);

        $this->assertEqualsCanonicalizing(['email', 'admin'], array_keys($diff));
        $this->assertSame(['label' => 'Email', 'old' => 'person@example.com', 'new' => 'changed@example.com'], $diff['email']);
        $this->assertSame(false, $diff['admin']['old']);
        $this->assertSame(true, $diff['admin']['new']);
    }

    /**
     * A Matrix field has no stored value on its owner; its nested entries are recorded themselves.
     */
    public function testANestedEntryFieldIsNotDiffedOnItsOwner(): void
    {
        $entry = $this->site->entry('Owner');

        $edited = $this->fresh($entry);
        $edited->setFieldValue('blocks', [
            'new1' => ['type' => 'block', 'fields' => ['blockText' => 'Inside']],
        ]);
        $snapshot = $this->differ->snapshot($edited);
        $this->assertInstanceOf(Snapshot::class, $snapshot);
        $this->assertNotContains('blocks', $snapshot->fields);
    }

    /**
     * Applying a draft saves the canonical entry with the draft's changes as its dirty list; the
     * diff of that save is exactly what the draft changed (the trap of profile §5).
     */
    public function testTheCanonicalSaveOfADraftApplyDiffsTheDraftsChanges(): void
    {
        $entry = $this->site->entry('Published', ['plainText' => 'Before', 'number' => 1]);

        $draft = Craft::$app->getDrafts()->createDraft($entry, (int)Craft::$app->getUsers()->getUserByUsernameOrEmail('admin')?->id ?: 1);
        $draft->title = 'Retitled';
        $draft->setFieldValue('plainText', 'After');
        $this->site->save($draft);

        $snapshots = [];
        $diffs = [];
        $before = function(ElementEvent $event) use (&$snapshots) {
            if ($event->element instanceof Entry && !$event->element->getIsDraft() && !$event->element->getIsRevision()) {
                $snapshots[] = $this->differ->snapshot($event->element);
            }
        };
        $after = function(ElementEvent $event) use (&$snapshots, &$diffs) {
            $snapshot = array_shift($snapshots);
            if ($snapshot instanceof Snapshot && $event->element instanceof Entry) {
                $diffs[] = $this->differ->diff($event->element, $snapshot);
            }
        };
        Event::on(Elements::class, Elements::EVENT_BEFORE_SAVE_ELEMENT, $before);
        Event::on(Elements::class, Elements::EVENT_AFTER_SAVE_ELEMENT, $after);
        try {
            Craft::$app->getDrafts()->applyDraft($draft);
        } finally {
            Event::off(Elements::class, Elements::EVENT_BEFORE_SAVE_ELEMENT, $before);
            Event::off(Elements::class, Elements::EVENT_AFTER_SAVE_ELEMENT, $after);
        }

        $this->assertCount(1, $diffs, 'One canonical save');
        $this->assertEqualsCanonicalizing(['title', 'plainText'], array_keys($diffs[0]));
        $this->assertSame(['label' => 'Plain text', 'old' => 'Before', 'new' => 'After'], $diffs[0]['plainText']);
    }

    /**
     * A new element's row holds only its non-empty values (BR-11).
     */
    public function testCreatedHoldsOnlyNonEmptyValues(): void
    {
        $entry = $this->site->entry('Fresh', ['plainText' => 'Hello', 'lightswitch' => true]);

        $values = $this->differ->created($entry);

        $this->assertSame(['label' => 'Plain text', 'old' => null, 'new' => 'Hello'], $values['plainText']);
        $this->assertSame(true, $values['lightswitch']['new']);
        $this->assertSame('Fresh', $values['title']['new']);
        foreach (['number', 'dropdown', 'related', 'files', 'money', 'website', 'table', 'expiryDate'] as $empty) {
            $this->assertArrayNotHasKey($empty, $values, $empty);
        }
    }

    /**
     * TS-5: a redacted field is recorded as changed, and its values appear nowhere — in a save's diff
     * or a new element's row (BR-10).
     */
    public function testARedactedFieldNeverCarriesItsValue(): void
    {
        $entry = $this->site->entry('Guarded', ['secret' => 'first-secret-value']);
        $created = Json::encode($this->differ->created($entry));

        $edited = $this->fresh($entry);
        $edited->setFieldValue('secret', 'second-secret-value');
        $diff = $this->saveAndDiff($edited);

        $this->assertSame(['label' => 'Secret', 'redacted' => true], $diff['secret']);
        $recorded = $created . Json::encode($diff);
        $this->assertStringNotContainsString('first-secret-value', $recorded);
        $this->assertStringNotContainsString('second-secret-value', $recorded);
    }

    /**
     * An unchanged redacted field is still no change: redaction hides values, not the comparison.
     */
    public function testAnUnchangedRedactedFieldIsNoChange(): void
    {
        $entry = $this->site->entry('Guarded', ['secret' => 'same']);

        $edited = $this->fresh($entry);
        $edited->setFieldValue('secret', 'same');

        $this->assertSame([], $this->saveAndDiff($edited));
    }

    /**
     * A plugin adds a tracked attribute with its own stored-value reader, or removes one (BR-9).
     */
    public function testTheEventAddsAndRemovesTrackedAttributes(): void
    {
        $this->differ->on(Differ::EVENT_DEFINE_TRACKED_ATTRIBUTES, function(DefineTrackedAttributesEvent $event) {
            if ($event->elementType === Entry::class) {
                unset($event->attributes['title']);
                $event->attributes['archived'] = 'elements.archived';
            }
        });
        $entry = $this->site->entry('Named');

        $edited = $this->fresh($entry);
        $edited->title = 'Renamed';
        $edited->archived = true;
        $diff = $this->saveAndDiff($edited);

        $this->assertArrayNotHasKey('title', $diff);
        $this->assertSame([false, true], [$diff['archived']['old'] ?? 'missing', $diff['archived']['new'] ?? 'missing']);
    }

    /**
     * A snapshot of an element never saved is null: it is recorded as created instead.
     */
    public function testAnUnsavedElementHasNoSnapshot(): void
    {
        $this->assertNull($this->differ->snapshot(new Entry(['sectionId' => $this->site->pages->id])));
    }

    /**
     * BR-8's fallback: a value stored in an older shape than the field now writes — here a date as a
     * bare string rather than `{date}` — is normalised through the field and is not a change.
     */
    public function testAValueStoredInAnOlderShapeIsNotAChange(): void
    {
        $date = new DateTime('2026-05-06 07:08:09', new DateTimeZone('UTC'));
        $entry = $this->site->entry('Dated', ['date' => $date]);
        $uid = $this->site->page->getFieldLayout()->getFieldByHandle('date')?->layoutElement?->uid;
        $this->assertIsString($uid);
        Craft::$app->getDb()->createCommand()->update(
            CraftTable::ELEMENTS_SITES,
            ['content' => [$uid => '2026-05-06 07:08:09']],
            ['elementId' => $entry->id],
        )->execute();

        $edited = $this->fresh($entry);
        $edited->setFieldValue('date', $date);
        $this->assertSame([], $this->saveAndDiff($edited));

        $moved = $this->fresh($entry);
        $moved->setFieldValue('date', new DateTime('2026-05-07 07:08:09', new DateTimeZone('UTC')));
        $this->assertSame(['date'], array_keys($this->saveAndDiff($moved)));
    }

    /**
     * BR-35: an entry's snapshot is one read of its rows plus one of its authors; a user's is one;
     * an element with no candidates reads nothing.
     */
    public function testASnapshotStaysWithinItsQueryBudget(): void
    {
        $entry = $this->fresh($this->site->entry('Counted', ['plainText' => 'x']));
        $entry->setFieldValue('plainText', 'y');
        $this->assertSame(2, $this->queriesDuring(fn() => $this->differ->snapshot($entry)));

        $user = Craft::$app->getUsers()->getUserById((int)$this->site->user('counted')->id);
        $this->assertInstanceOf(User::class, $user);
        $this->assertSame(1, $this->queriesDuring(fn() => $this->differ->snapshot($user)));

        // A differ of its own: the first has already defined the entry type's attributes.
        $untracked = new Differ(['settings' => new Settings()]);
        $untracked->on(Differ::EVENT_DEFINE_TRACKED_ATTRIBUTES, function(DefineTrackedAttributesEvent $event) {
            $event->attributes = [];
        });
        $bare = $this->fresh($this->site->entry('Bare'));
        $this->assertSame(0, $this->queriesDuring(fn() => $untracked->snapshot($bare)));
    }

    private function queriesDuring(Closure $run): int
    {
        $harness = Yii::getLogger();
        $logger = new Logger();
        Yii::setLogger($logger);
        try {
            $run();
        } finally {
            Yii::setLogger($harness);
        }

        $queries = array_filter($logger->messages, fn(array $message) => $message[1] === Logger::LEVEL_PROFILE_BEGIN
            && str_starts_with((string)$message[2], 'yii\db\Command::'));

        return count($queries);
    }

    private function fresh(Entry $entry): Entry
    {
        $fresh = Entry::find()->id($entry->id)->status(null)->one();
        $this->assertInstanceOf(Entry::class, $fresh);

        return $fresh;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function saveAndDiff(ElementInterface $element): array
    {
        $snapshot = $this->differ->snapshot($element);
        $this->assertInstanceOf(Snapshot::class, $snapshot);
        $this->site->save($element);

        return $this->differ->diff($element, $snapshot);
    }
}
