<?php

namespace webdna\auditor\tests\integration;

use Closure;
use Codeception\Test\Unit;
use Craft;
use craft\base\ElementInterface;
use craft\db\Connection;
use craft\elements\Entry;
use craft\elements\User;
use craft\web\Request;
use RuntimeException;
use webdna\auditor\Auditor;
use webdna\auditor\db\Table;
use webdna\auditor\models\Event;
use webdna\auditor\models\Settings;
use webdna\auditor\models\Snapshot;
use webdna\auditor\services\Differ;
use webdna\auditor\services\Recorder;
use webdna\auditor\tests\Support\TestSite;
use webdna\auditor\watchers\ElementWatcher;
use Yii;
use yii\log\Logger;

/**
 * The element watcher against real saves: a CP publish and a site form each record exactly what
 * changed (TS-1, TS-2); drafts, revisions and propagated copies are never recorded (BR-6, TN-8,
 * TN-9); a new element records its values (BR-11); a nested entry names its owner (BR-12); a
 * failed save records nothing (TS-6 step 1); and a failure inside the watcher never stops a save
 * (BR-2).
 *
 * The saves run inside the harness's per-test transaction on Craft's connection. The recorder is
 * swapped for one on a second connection, which has no transaction open, so the rows a save
 * records are ready at once and can be read straight from the buffer.
 */
class ElementWatcherTest extends Unit
{
    use SecondConnection;

    private TestSite $site;

    private Auditor $plugin;

    private Recorder $recorder;

    private Connection $db;

    /**
     * @var array<string, mixed>
     */
    private array $settings;

    /**
     * @var string[] uids of rows a test flushed to the table, removed afterwards
     */
    private array $written = [];

    protected function _before(): void
    {
        $this->plugin = Auditor::getInstance();
        $this->settings = $this->plugin->getSettings()->toArray();
        $this->site = TestSite::build();

        $this->db = $this->secondConnection();
        $this->recorder = new Recorder(['db' => $this->db]);
        $this->plugin->set('recorder', $this->recorder);
    }

    protected function _after(): void
    {
        if ($this->written) {
            $this->db->createCommand()->delete(Table::EVENTS, ['uid' => $this->written])->execute();
        }
        $this->db->close();
        $this->plugin->set('recorder', Recorder::class);
        $this->plugin->set('differ', Differ::class);
        $this->plugin->getSettings()->setAttributes($this->settings, false);
        TestSite::removeFiles();
    }

    /**
     * TS-1: an editor changes an entry's title and turns it off in the CP. Their provisional draft
     * autosaves (no row, TN-8), the publish records one row with exactly those two, and deleting
     * the draft on publish records nothing (TN-8).
     */
    public function testACpPublishRecordsOneRowWithTheTwoChanges(): void
    {
        $editor = $this->site->user('editor');
        $entry = $this->site->entry('Before', ['plainText' => 'Untouched'], $editor);
        $this->clear();

        $this->asRequest(true, $editor, function() use ($entry, $editor) {
            $drafts = Craft::$app->getDrafts();
            /** @var Entry $draft */
            $draft = $drafts->createDraft($entry, $editor->id, null, null, [], true);
            $draft->title = 'After';
            $draft->enabled = false;
            $this->site->save($draft);
            $this->assertSame([], $this->recorder->getReady(), 'An autosaved draft is not recorded');

            $drafts->applyDraft($draft);
        });

        $rows = $this->recorder->getReady();
        $this->assertCount(1, $rows, 'One row: no draft, revision or draft deletion');
        $row = $rows[0];
        $this->assertSame(ElementWatcher::EVENT_SAVED, $row->event);
        $this->assertSame((int)$entry->id, $row->elementId);
        $this->assertSame(Entry::class, $row->elementType);
        $this->assertSame((int)$entry->siteId, $row->siteId);
        $this->assertSame('After', $row->elementLabel);
        $this->assertSame((int)$editor->id, $row->actorId);
        $this->assertSame('editor@example.com', $row->actorLabel);
        $this->assertSame(Settings::CONTEXT_CP, $row->context);

        $this->assertSame(['enabled', 'title'], $this->keys($row));
        $this->assertSame(['label' => 'Title', 'old' => 'Before', 'new' => 'After'], $row->data['title'] ?? null);
        $this->assertSame(true, $row->data['enabled']['old'] ?? null);
        $this->assertSame(false, $row->data['enabled']['new'] ?? null);

        // And the row reaches the table as it was buffered.
        $this->recorder->flush();
        $this->written[] = (string)$row->uid;
        $stored = (new \craft\db\Query())->from(Table::EVENTS)->where(['uid' => $row->uid])->one($this->db);
        $this->assertIsArray($stored);
        $this->assertSame('saved', $stored['event']);
        $this->assertSame('cp', $stored['context']);
        $data = is_string($stored['data']) ? json_decode($stored['data'], true) : null;
        $this->assertIsArray($data);
        $this->assertSame(['enabled', 'title'], $this->sorted(array_keys($data)));
    }

    /**
     * TS-2: a member posts a site form re-sending every field, one changed. Only that one is recorded.
     */
    public function testASiteFormRepostingEveryFieldRecordsOnlyTheChangedOne(): void
    {
        $member = $this->site->user('member');
        $other = $this->site->entry('Other');
        $values = [
            'plainText' => 'Same',
            'number' => '3',
            'dropdown' => 'red',
            'multiSelect' => ['one', 'two'],
            'lightswitch' => '1',
            'related' => [(string)$other->id],
            'table' => [['col1' => 'Alpha', 'col2' => '1']],
            'secret' => 'hunter2',
        ];
        $entry = $this->site->entry('Form', $values, $member);
        $this->clear();

        $this->asRequest(false, $member, function() use ($entry, $values) {
            $posted = $this->fresh($entry);
            $posted->title = 'Form';
            Craft::$app->getRequest()->setBodyParams(['fields' => ['plainText' => 'Changed'] + $values]);
            $posted->setFieldValuesFromRequest('fields');
            $this->assertTrue($posted->isFieldDirty('number'), 'Every posted field is dirty');
            $this->site->save($posted);
            Craft::$app->getRequest()->setBodyParams([]);
        });

        $rows = $this->recorder->getReady();
        $this->assertCount(1, $rows);
        $this->assertSame(Settings::CONTEXT_SITE, $rows[0]->context);
        $this->assertSame((int)$member->id, $rows[0]->actorId);
        $this->assertSame(['plainText'], $this->keys($rows[0]));
        $this->assertSame('Same', $rows[0]->data['plainText']['old'] ?? null);
        $this->assertSame('Changed', $rows[0]->data['plainText']['new'] ?? null);
    }

    /**
     * BR-11: a new element records `created` with its non-empty values, redacted as a diff is.
     */
    public function testANewElementRecordsItsValuesRedacted(): void
    {
        $this->plugin->getSettings()->redact = ['secret'];

        $entry = $this->site->entry('New', ['plainText' => 'Hello', 'secret' => 'hunter2']);

        $rows = $this->rowsFor($entry);
        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame(ElementWatcher::EVENT_CREATED, $row->event);
        $this->assertSame(Settings::CONTEXT_CONSOLE, $row->context);
        $this->assertSame(Event::SYSTEM, $row->actorLabel);
        $this->assertSame(['label' => 'Plain text', 'old' => null, 'new' => 'Hello'], $row->data['plainText'] ?? null);
        $this->assertSame(['label' => 'Secret', 'redacted' => true], $row->data['secret'] ?? null);
        $this->assertSame('New', $row->data['title']['new'] ?? null);
        $this->assertArrayNotHasKey('number', $row->data ?? [], 'An empty field is left out');
        $this->assertStringNotContainsString('hunter2', (string)json_encode($row->data));
    }

    /**
     * An entry made in the CP starts as an unpublished draft; publishing it is its creation — one
     * `created` row, and nothing for the draft's own saves (BR-6, BR-11).
     */
    public function testPublishingAnUnpublishedDraftIsACreation(): void
    {
        $editor = $this->site->user('editor');
        $this->clear();

        $this->asRequest(true, $editor, function() use ($editor) {
            $entry = new Entry(['sectionId' => $this->site->pages->id, 'typeId' => $this->site->page->id, 'title' => 'Fresh']);
            $entry->setAuthor($editor);
            $entry->setFieldValue('plainText', 'Draft text');
            if (!Craft::$app->getDrafts()->saveElementAsDraft($entry, $editor->id, null, null, false)) {
                throw new RuntimeException('Draft did not save');
            }
            $this->assertSame([], $this->recorder->getReady(), 'An unpublished draft is not recorded');
            Craft::$app->getDrafts()->applyDraft($entry);
        });

        $rows = $this->recorder->getReady();
        $this->assertCount(1, $rows);
        $this->assertSame(ElementWatcher::EVENT_CREATED, $rows[0]->event);
        $this->assertSame('Fresh', $rows[0]->elementLabel);
        $this->assertSame('Draft text', $rows[0]->data['plainText']['new'] ?? null);
    }

    /**
     * A save that changes nothing writes no row, unless `recordNoChangeSaves` asks for one (BR-7).
     */
    public function testASaveThatChangesNothingIsRecordedOnlyWhenAsked(): void
    {
        $entry = $this->site->entry('Still', ['plainText' => 'Same']);
        $this->clear();

        $same = $this->fresh($entry);
        $same->setFieldValue('plainText', 'Same');
        $this->site->save($same);
        $this->assertSame([], $this->recorder->getReady());

        $this->plugin->getSettings()->recordNoChangeSaves = true;
        $again = $this->fresh($entry);
        $this->site->save($again);
        $rows = $this->recorder->getReady();
        $this->assertCount(1, $rows);
        $this->assertSame(ElementWatcher::EVENT_SAVED, $rows[0]->event);
        $this->assertNull($rows[0]->data);
    }

    /**
     * TS-6 step 1: a save that fails validation leaves no row.
     */
    public function testASaveThatFailsValidationRecordsNothing(): void
    {
        $entry = $this->site->entry('Valid');
        $this->clear();

        $invalid = $this->fresh($entry);
        $invalid->title = '';
        $this->assertFalse(Craft::$app->getElements()->saveElement($invalid));
        $this->assertSame([], $this->recorder->getReady());
    }

    /**
     * TN-9: a save in one site that propagates to two others is one row, for the edited site.
     */
    public function testAPropagatedSaveIsOneRowForTheEditedSite(): void
    {
        $localised = $this->site->localised();
        $entry = new Entry(['sectionId' => $localised->id, 'typeId' => $this->site->page->id, 'title' => 'Everywhere']);
        $this->site->save($entry);

        $rows = $this->recorder->getReady();
        $this->assertCount(1, $rows, 'The copies Craft saves in the other sites are not recorded');
        $this->assertSame((int)Craft::$app->getSites()->getPrimarySite()->id, $rows[0]->siteId);
        $this->assertSame(3, (int)Entry::find()->id($entry->id)->site('*')->status(null)->count(), 'It did propagate');
        $this->clear();

        $changed = $this->fresh($entry);
        $changed->setFieldValue('plainText', 'Now set');
        $this->site->save($changed);
        $rows = $this->recorder->getReady();
        $this->assertCount(1, $rows);
        $this->assertSame(['plainText'], $this->keys($rows[0]));
    }

    /**
     * BR-12: a nested entry's rows carry its owner and share the owner's request id.
     */
    public function testANestedEntryNamesItsOwner(): void
    {
        $owner = $this->site->entry('Owner', ['blocks' => [
            'new1' => ['type' => 'block', 'title' => 'Block', 'fields' => ['blockText' => 'One']],
        ]]);

        $rows = $this->recorder->getReady();
        $this->assertCount(2, $rows);
        [$nested, $ownerRow] = $rows[0]->ownerId !== null ? [$rows[0], $rows[1]] : [$rows[1], $rows[0]];
        $this->assertSame((int)$owner->id, $ownerRow->elementId);
        $this->assertNull($ownerRow->ownerId);
        $this->assertSame(ElementWatcher::EVENT_CREATED, $nested->event);
        $this->assertSame((int)$owner->id, $nested->ownerId);
        $this->assertSame($ownerRow->requestId, $nested->requestId);
        $this->assertSame('One', $nested->data['blockText']['new'] ?? null);
        $this->clear();

        $block = Entry::find()->ownerId($owner->id)->fieldId($this->site->fields['blocks']->id)->one();
        $this->assertInstanceOf(Entry::class, $block);
        $block->setFieldValue('blockText', 'Two');
        $this->site->save($block);

        $rows = $this->recorder->getReady();
        $this->assertCount(1, $rows);
        $this->assertSame((int)$owner->id, $rows[0]->ownerId);
        $this->assertSame(['blockText'], $this->keys($rows[0]));
        $this->assertSame('Block', $rows[0]->elementLabel, 'A titled block keeps its title');
    }

    /**
     * BR-12: a nested entry with no title is named by its type and its owner, whether it is saved
     * through its owner (which it already has) or on its own (which reads it).
     */
    public function testAnUntitledNestedEntryIsNamedByItsTypeAndOwner(): void
    {
        $owner = $this->site->entry('The spring collection', ['blocks' => [
            'new1' => ['type' => 'textBlock', 'fields' => ['blockText' => 'One']],
        ]]);

        $nested = array_values(array_filter($this->recorder->getReady(), fn(Event $row) => $row->ownerId !== null));
        $this->assertCount(1, $nested);
        $this->assertSame('Text block in The spring collection', $nested[0]->elementLabel);
        $this->clear();

        $block = Entry::find()->ownerId($owner->id)->fieldId($this->site->fields['blocks']->id)->one();
        $this->assertInstanceOf(Entry::class, $block);
        $block->setFieldValue('blockText', 'Two');
        $this->site->save($block);

        $rows = $this->recorder->getReady();
        $this->assertCount(1, $rows);
        $this->assertSame('Text block in The spring collection', $rows[0]->elementLabel);
    }

    /**
     * Deleting and restoring are recorded with the label kept, so the row still reads once the
     * subject is gone (TN-5); a draft's deletion is not (TN-8).
     */
    public function testDeletionAndRestoreAreRecordedWithTheLabel(): void
    {
        $entry = $this->site->entry('Doomed');
        $draft = Craft::$app->getDrafts()->createDraft($entry, null, 'A draft');
        $this->clear();

        Craft::$app->getElements()->deleteElement($draft, true);
        $this->assertSame([], $this->recorder->getReady(), 'A draft deletion is not recorded');

        Craft::$app->getElements()->deleteElement($entry);
        $this->assertNull(Entry::find()->id($entry->id)->one(), 'The subject is gone');
        Craft::$app->getElements()->restoreElement($entry);

        $rows = $this->recorder->getReady();
        $this->assertSame([ElementWatcher::EVENT_DELETED, ElementWatcher::EVENT_RESTORED], array_map(fn(Event $row) => $row->event, $rows));
        $this->assertSame('Doomed', $rows[0]->elementLabel);
        $this->assertSame((int)$entry->id, $rows[0]->elementId);
        $this->assertNull($rows[0]->data, 'A soft delete');
    }

    /**
     * A move in a structure is recorded, naming the target; placing a new entry is not a move.
     */
    public function testAMoveInAStructureIsRecorded(): void
    {
        $first = $this->node('First');
        $second = $this->node('Second');
        $this->assertSame([ElementWatcher::EVENT_CREATED, ElementWatcher::EVENT_CREATED], array_map(fn(Event $row) => $row->event, $this->recorder->getReady()));
        $this->clear();

        Craft::$app->getStructures()->moveBefore((int)$second->structureId, $second, $first);

        $rows = $this->recorder->getReady();
        $this->assertCount(1, $rows);
        $this->assertSame(ElementWatcher::EVENT_MOVED, $rows[0]->event);
        $this->assertSame((int)$second->id, $rows[0]->elementId);
        // Craft's own name for the action.
        $this->assertSame('placeBefore', $rows[0]->data['action'] ?? null);
        $this->assertSame((int)$first->id, $rows[0]->data['targetId'] ?? null);
        $this->assertSame('First', $rows[0]->data['targetLabel'] ?? null);
    }

    /**
     * Publishing a draft of a structure entry is a save, not a move.
     */
    public function testPublishingADraftInAStructureIsNotAMove(): void
    {
        $node = $this->node('Node');
        $this->clear();

        $draft = Craft::$app->getDrafts()->createDraft($node);
        $draft->title = 'Renamed';
        $this->site->save($draft);
        Craft::$app->getDrafts()->applyDraft($draft);

        $this->assertSame([ElementWatcher::EVENT_SAVED], array_map(fn(Event $row) => $row->event, $this->recorder->getReady()));
    }

    /**
     * BR-5: an excluded type, a context not recorded and the master switch each record nothing — and
     * with recording off, no recorder is even created.
     */
    public function testExcludedTypesContextsAndTheSwitchRecordNothing(): void
    {
        $settings = $this->plugin->getSettings();

        $settings->excludeElementTypes = [User::class];
        $this->site->user('ignored');
        $this->assertSame([], $this->recorder->getReady());

        $settings->excludeElementTypes = [];
        $settings->contexts = [Settings::CONTEXT_CP];
        $this->site->entry('Console save');
        $this->assertCount(0, $this->recorder->getReady());

        $settings->contexts = Settings::CONTEXTS;
        $settings->enabled = false;
        $this->plugin->set('recorder', Recorder::class);
        $this->site->entry('Switched off');
        $this->assertFalse($this->plugin->has('recorder', true), 'Nothing created while recording is off');
    }

    /**
     * BR-2: a failure inside the watcher is logged and the save goes ahead.
     */
    public function testAFailureInTheWatcherNeverStopsTheSave(): void
    {
        $entry = $this->site->entry('Sturdy');
        $this->plugin->set('differ', new class() extends Differ {
            public function snapshot(ElementInterface $element): ?Snapshot
            {
                throw new RuntimeException('snapshot broke');
            }
        });

        $harness = Yii::getLogger();
        $logger = new Logger();
        Yii::setLogger($logger);
        try {
            $changed = $this->fresh($entry);
            $changed->title = 'Still saved';
            $this->assertTrue(Craft::$app->getElements()->saveElement($changed));
        } finally {
            Yii::setLogger($harness);
        }

        $this->assertSame('Still saved', Entry::find()->id($entry->id)->one()?->title);
        $logged = array_filter($logger->messages, fn(array $m) => $m[2] === Auditor::HANDLE && str_contains((string)$m[0], 'snapshot broke'));
        $this->assertNotEmpty($logged);
    }

    /**
     * Runs the callback as a web request — CP or site — with the user signed in.
     */
    private function asRequest(bool $cp, User $user, Closure $callback): void
    {
        /** @var Request $request */
        $request = Craft::$app->getRequest();
        $path = $request->getPathInfo();
        $console = $request->getIsConsoleRequest();
        $request->setIsConsoleRequest(false);
        $request->setIsCpRequest($cp);
        $request->setPathInfo($cp ? 'admin/entries/pages' : 'form');
        Craft::$app->getUser()->setIdentity($user);

        try {
            $callback();
        } finally {
            Craft::$app->getUser()->setIdentity(null);
            $request->setIsCpRequest(null);
            $request->setIsConsoleRequest($console);
            $request->setPathInfo($path);
        }
    }

    private function node(string $title): Entry
    {
        $node = new Entry(['sectionId' => $this->site->tree->id, 'typeId' => $this->site->tree->getEntryTypes()[0]->id, 'title' => $title]);
        $this->site->save($node);

        return $node;
    }

    private function fresh(Entry $entry): Entry
    {
        $fresh = Entry::find()->id($entry->id)->siteId($entry->siteId)->status(null)->one();
        $this->assertInstanceOf(Entry::class, $fresh);

        return $fresh;
    }

    /**
     * @return Event[]
     */
    private function rowsFor(ElementInterface $element): array
    {
        return array_values(array_filter($this->recorder->getReady(), fn(Event $row) => $row->elementId === (int)$element->id));
    }

    /**
     * The row's changed names, sorted.
     *
     * @return string[]
     */
    private function keys(Event $row): array
    {
        return $this->sorted(array_keys($row->data ?? []));
    }

    /**
     * @param string[] $names
     * @return string[]
     */
    private function sorted(array $names): array
    {
        sort($names);

        return $names;
    }

    /**
     * Forgets the rows recorded so far — the test's own setting-up.
     */
    private function clear(): void
    {
        // flush() writes them to the table; remember them so _after() removes them.
        foreach ($this->recorder->getReady() as $row) {
            $this->written[] = (string)$row->uid;
        }
        $this->recorder->flush();
    }
}
