<?php

namespace webdna\auditor\tests\integration;

use Closure;
use Codeception\Test\Unit;
use Craft;
use craft\base\ElementInterface;
use craft\db\Connection;
use craft\elements\actions\Delete;
use craft\elements\Entry;
use craft\elements\User;
use craft\events\ElementActionEvent;
use craft\queue\jobs\ResaveElements;
use craft\queue\Queue;
use craft\services\Elements;
use craft\web\Request;
use webdna\auditor\Auditor;
use webdna\auditor\db\Table;
use webdna\auditor\models\Event;
use webdna\auditor\models\Settings;
use webdna\auditor\services\Differ;
use webdna\auditor\services\Recorder;
use webdna\auditor\tests\Support\CountingDiffer;
use webdna\auditor\tests\Support\TestSite;
use webdna\auditor\watchers\ElementWatcher;
use yii\base\Application;

/**
 * Batches (BR-13, BR-14, BR-15): a resave, an index action on several elements, a run of a batched
 * job, and a bulk operation over more than the threshold's top-level elements are each one `bulk`
 * row listing what they covered (TS-7, TN-13) — and none of them snapshots or diffs its elements.
 *
 * As in the watcher's own test, the recorder is swapped for one on a second connection, so rows
 * are ready at once and a flush really writes them. A counting differ says how many snapshots the
 * operation paid for.
 */
class BatchesTest extends Unit
{
    use SecondConnection;

    private TestSite $site;

    private Auditor $plugin;

    private Recorder $recorder;

    private Connection $db;

    private CountingDiffer $differ;

    /**
     * @var array<string, mixed>
     */
    private array $settings;

    /**
     * @var string[] uids of rows written to the table, removed afterwards (their lists go with them)
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
        $this->differ = new CountingDiffer();
        $this->plugin->set('differ', $this->differ);
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
     * TS-7 steps 1-2: re-saving a section's 25 entries is one batch row — operation, count and type,
     * from the console — listing all 25 in the side table once flushed, with no snapshot taken.
     */
    public function testAResaveIsOneBatchRowListingEveryEntry(): void
    {
        $entries = $this->entries(25);

        Craft::$app->getElements()->resaveElements(Entry::find()->sectionId($this->site->pages->id)->status(null));

        $rows = $this->recorder->getReady();
        $this->assertCount(1, $rows, 'One row for the whole resave');
        $row = $rows[0];
        $this->assertSame(ElementWatcher::EVENT_BULK, $row->event);
        $this->assertSame(Event::FAMILY_ELEMENT, $row->family);
        $this->assertNull($row->elementId, 'A batch row has no single subject');
        $this->assertSame(['operation' => 'resave', 'count' => 25, 'elementType' => Entry::class], $row->data);
        $this->assertSame(Settings::CONTEXT_CONSOLE, $row->context);
        $this->assertSame(0, $this->differ->snapshots, 'A batch takes no before-snapshots (B3)');

        $this->flush();
        $stored = $this->stored();
        $this->assertCount(1, $stored);
        $this->assertSame('bulk', $stored[0]['event']);
        $this->assertEqualsCanonicalizing($this->ids($entries), $this->listed((int)$stored[0]['id']), 'Every entry is listed');
    }

    /**
     * TN-13 and TS-7 step 4: ten top-level saves in one bulk operation are ten rows; eleven are one
     * batch row, and the ten rows already recorded for it are folded into it.
     */
    public function testTenSavesInOneBulkOperationAreTenRowsAndElevenAreOneBatch(): void
    {
        $entries = $this->entries(21);

        $this->inBulkOp(array_slice($entries, 0, 10), fn(Entry $entry) => $this->change($entry));
        $rows = $this->recorder->getReady();
        $this->assertCount(10, $rows);
        $this->assertSame([ElementWatcher::EVENT_SAVED], array_values(array_unique(array_column($rows, 'event'))));
        $this->clear();

        $eleven = array_slice($entries, 10, 11);
        $this->inBulkOp($eleven, fn(Entry $entry) => $this->change($entry));
        $rows = $this->recorder->getReady();
        $this->assertCount(1, $rows, 'No per-element rows once the threshold is crossed');
        $this->assertSame(ElementWatcher::EVENT_BULK, $rows[0]->event);
        $this->assertSame(['operation' => 'save', 'count' => 11, 'elementType' => Entry::class], $rows[0]->data);
        $this->assertEqualsCanonicalizing($this->ids($eleven), array_column($rows[0]->elements, 0));
    }

    /**
     * BR-14: once an operation is a batch, the rest of its elements are not snapshotted. Fifteen
     * saves pay for the eleven taken before the threshold was crossed, and no more.
     */
    public function testOnceABatchTheRestAreNotSnapshotted(): void
    {
        $entries = $this->entries(15);
        $this->differ->snapshots = 0;

        $this->inBulkOp($entries, fn(Entry $entry) => $this->change($entry));

        $this->assertSame(11, $this->differ->snapshots);
        $this->assertCount(1, $this->recorder->getReady());
    }

    /**
     * BR-13: nested entries count toward their owner. Six owners with two blocks each are eighteen
     * elements but six top-level ones, so they record as usual; eleven owners are a batch, which
     * lists the owners only.
     */
    public function testNestedEntriesCountTowardTheirOwner(): void
    {
        Craft::$app->getElements()->ensureBulkOp(function() {
            for ($i = 0; $i < 6; $i++) {
                $this->owner("Owner $i", 2);
            }
        });
        $rows = $this->recorder->getReady();
        $this->assertCount(18, $rows, 'Six owners and twelve blocks, each its own row');
        $this->assertNotContains(ElementWatcher::EVENT_BULK, array_column($rows, 'event'));
        $this->clear();

        $owners = [];
        Craft::$app->getElements()->ensureBulkOp(function() use (&$owners) {
            for ($i = 0; $i < 11; $i++) {
                $owners[] = $this->owner("Owner $i", 1);
            }
        });
        $rows = $this->recorder->getReady();
        $this->assertCount(1, $rows);
        $this->assertSame(['operation' => 'create', 'count' => 11, 'elementType' => Entry::class], $rows[0]->data);
        $this->assertEqualsCanonicalizing($this->ids($owners), array_column($rows[0]->elements, 0), 'Blocks are listed through their owner');
        $this->clear();

        // Re-saving one field's nested entries alone, as `resave/entries --field` does, is a batch
        // over their owners — not an empty one.
        Craft::$app->getElements()->resaveElements(Entry::find()->fieldId($this->site->fields['blocks']->id)->ownerId($this->ids($owners))->status(null));
        $rows = $this->recorder->getReady();
        $this->assertCount(1, $rows);
        $this->assertSame(['operation' => 'resave', 'count' => 11, 'elementType' => Entry::class], $rows[0]->data);
        $this->assertEqualsCanonicalizing($this->ids($owners), array_column($rows[0]->elements, 0));
    }

    /**
     * BR-13 (#6): a block in a block counts toward the top-level element it belongs to, not the
     * block that holds it. Re-saving two pages with blocks two levels deep, as
     * `resave/entries --elementId=A,B` does, is a re-save of 2 entries; and saving one page with
     * eleven blocks that each hold a block is that page's own rows, not a batch of 12.
     */
    public function testBlocksInBlocksCountTowardTheTopLevelElement(): void
    {
        $pages = [$this->deepOwner('Page A', 3, 2), $this->deepOwner('Page B', 3, 2)];
        $this->clear();

        Craft::$app->getElements()->resaveElements(Entry::find()->id($this->ids($pages))->status(null));
        $rows = $this->recorder->getReady();
        $this->assertCount(1, $rows);
        $this->assertSame(['operation' => 'resave', 'count' => 2, 'elementType' => Entry::class], $rows[0]->data);
        $this->assertEqualsCanonicalizing($this->ids($pages), array_column($rows[0]->elements, 0), 'Listed through the pages, never a block');
        $this->clear();

        Craft::$app->getElements()->ensureBulkOp(fn() => $this->deepOwner('Page C', 11, 1));
        $rows = $this->recorder->getReady();
        $this->assertNotContains(ElementWatcher::EVENT_BULK, array_column($rows, 'event'));
        $this->assertCount(23, $rows, 'The page, its eleven blocks and their eleven blocks, each its own row');
    }

    /**
     * BR-13: deleting more than the threshold in one bulk operation is a batch named for the delete.
     */
    public function testElevenDeletionsInOneBulkOperationAreOneBatch(): void
    {
        $entries = $this->entries(11);

        $this->inBulkOp($entries, fn(Entry $entry) => Craft::$app->getElements()->deleteElement($entry));

        $rows = $this->recorder->getReady();
        $this->assertCount(1, $rows);
        $this->assertSame(['operation' => 'delete', 'count' => 11, 'elementType' => Entry::class], $rows[0]->data);
    }

    /**
     * BR-13: an element-index action on several elements is a batch whatever the count, recorded as
     * the person who ran it; on one element it is that element's own row.
     */
    public function testAnIndexActionOnSeveralElementsIsOneBatch(): void
    {
        $admin = $this->site->user('admin', true);
        $entries = $this->entries(4);

        $this->asCpRequest($admin, fn() => $this->performDelete(array_slice($entries, 0, 3)));
        $rows = $this->recorder->getReady();
        $this->assertCount(1, $rows);
        $this->assertSame(ElementWatcher::EVENT_BULK, $rows[0]->event);
        $this->assertSame(['operation' => Delete::class, 'count' => 3, 'elementType' => Entry::class], $rows[0]->data);
        $this->assertSame(Settings::CONTEXT_CP, $rows[0]->context);
        $this->assertSame((int)$admin->id, $rows[0]->actorId);
        $this->clear();

        $this->asCpRequest($admin, fn() => $this->performDelete([$entries[3]]));
        $rows = $this->recorder->getReady();
        $this->assertCount(1, $rows);
        $this->assertSame(ElementWatcher::EVENT_DELETED, $rows[0]->event);
        $this->assertSame((int)$entries[3]->id, $rows[0]->elementId);
    }

    /**
     * An action whose after event never comes — a handler cancelled it, or it failed — still records
     * its batch as the request ends, in time for that request's flush.
     */
    public function testAnActionLeftOpenIsRecordedAsTheRequestEnds(): void
    {
        $admin = $this->site->user('admin', true);
        $entries = $this->entries(2);

        $this->asCpRequest($admin, function() use ($entries) {
            $query = Entry::find()->id($this->ids($entries))->status(null);
            $action = new Delete(['elementType' => Entry::class]);
            Craft::$app->getElements()->trigger(Elements::EVENT_BEFORE_PERFORM_ACTION, new ElementActionEvent(['action' => $action, 'criteria' => $query]));
            $action->performAction($query);
        });
        $this->assertSame([], $this->recorder->getReady(), 'Nothing yet: the batch is still open');

        Craft::$app->trigger(Application::EVENT_AFTER_REQUEST);

        $stored = $this->stored();
        $this->assertCount(1, $stored);
        $this->assertCount(2, $this->listed((int)$stored[0]['id']));
    }

    /**
     * BR-13, through a real queue run: a batched element job is a batch in each of its runs, in the
     * queue context — Craft's resave job named a re-save, as the command is (#6), with the job's
     * class as the context's detail. Craft splits 25 entries at a batch size of 10 into three
     * runs, so three rows, which between them list all 25.
     */
    public function testABatchedJobIsOneBatchRowPerRun(): void
    {
        $entries = $this->entries(25);

        $queue = Craft::$app->getQueue();
        $this->assertInstanceOf(Queue::class, $queue);
        $queue->push(new ResaveElements([
            'elementType' => Entry::class,
            'criteria' => ['sectionId' => $this->site->pages->id, 'status' => null],
            'batchSize' => 10,
        ]));
        $queue->run();

        $stored = $this->stored();
        $this->assertCount(3, $stored);
        $this->assertSame([10, 10, 5], array_map(fn(array $row) => json_decode($row['data'], true)['count'], $stored));
        $this->assertSame(['resave'], array_values(array_unique(array_map(fn(array $row) => json_decode($row['data'], true)['operation'], $stored))));
        $this->assertSame([Settings::CONTEXT_QUEUE], array_values(array_unique(array_column($stored, 'context'))));
        $this->assertSame([ResaveElements::class], array_values(array_unique(array_column($stored, 'contextDetail'))));
        $listed = array_merge(...array_map(fn(array $row) => $this->listed((int)$row['id']), $stored));
        $this->assertEqualsCanonicalizing($this->ids($entries), $listed);
        $this->assertSame(0, $this->differ->snapshots);
    }

    /**
     * BR-14: with `recordBulkDiffs` on, a batch records its elements one by one, with their diffs.
     */
    public function testRecordBulkDiffsRecordsEachElementInstead(): void
    {
        $this->plugin->getSettings()->recordBulkDiffs = true;
        $entries = $this->entries(11);

        $this->inBulkOp($entries, fn(Entry $entry) => $this->change($entry));

        $rows = $this->recorder->getReady();
        $this->assertCount(11, $rows);
        $this->assertSame([ElementWatcher::EVENT_SAVED], array_values(array_unique(array_column($rows, 'event'))));
        $this->assertSame(['plainText'], array_keys($rows[0]->data ?? []));
    }

    /**
     * BR-4 holds for a batch row: recorded inside a transaction that rolls back, it goes with it.
     */
    public function testABatchInsideARolledBackTransactionLeavesNothing(): void
    {
        $entries = $this->entries(11);

        $transaction = $this->db->beginTransaction();
        $this->inBulkOp($entries, fn(Entry $entry) => $this->change($entry));
        $this->assertCount(1, $this->recorder->getPending());
        $transaction->rollBack();

        $this->assertSame([], $this->recorder->getPending());
        $this->assertSame([], $this->recorder->getReady());
    }

    /**
     * Saved entries in `pages`, their creation rows already written away.
     *
     * @return Entry[]
     */
    private function entries(int $count): array
    {
        $entries = [];
        for ($i = 1; $i <= $count; $i++) {
            $entries[] = $this->site->entry("Entry $i", ['plainText' => 'Before']);
        }
        $this->clear();
        $this->differ->snapshots = 0;

        return $entries;
    }

    private function owner(string $title, int $blocks): Entry
    {
        $values = [];
        for ($i = 1; $i <= $blocks; $i++) {
            $values["new$i"] = ['type' => 'block', 'title' => "Block $i", 'fields' => ['blockText' => "Text $i"]];
        }

        return $this->site->entry($title, ['blocks' => $values]);
    }

    /**
     * A page whose blocks each hold blocks of their own: two levels deep.
     */
    private function deepOwner(string $title, int $sections, int $blocks): Entry
    {
        $values = [];
        for ($i = 1; $i <= $sections; $i++) {
            $inner = [];
            for ($j = 1; $j <= $blocks; $j++) {
                $inner["new$j"] = ['type' => 'block', 'title' => "Block $i.$j", 'fields' => ['blockText' => "Text $i.$j"]];
            }
            $values["new$i"] = ['type' => 'sectionBlock', 'title' => "Section $i", 'fields' => ['innerBlocks' => $inner]];
        }

        return $this->site->entry($title, ['blocks' => $values]);
    }

    private function change(Entry $entry): void
    {
        $entry->setFieldValue('plainText', 'After ' . $entry->id);
        $this->site->save($entry);
    }

    /**
     * Runs the callback for each element inside one Craft bulk operation, as code that saves many
     * elements at once does.
     *
     * @param ElementInterface[] $elements
     */
    private function inBulkOp(array $elements, Closure $callback): void
    {
        Craft::$app->getElements()->ensureBulkOp(function() use ($elements, $callback) {
            foreach ($elements as $element) {
                $callback($element);
            }
        });
    }

    /**
     * The element-index controller's part of an action: the two events around `performAction()`.
     *
     * @param Entry[] $entries
     */
    private function performDelete(array $entries): void
    {
        $elements = Craft::$app->getElements();
        $query = Entry::find()->id($this->ids($entries))->status(null);
        $action = new Delete(['elementType' => Entry::class]);

        $elements->trigger(Elements::EVENT_BEFORE_PERFORM_ACTION, new ElementActionEvent(['action' => $action, 'criteria' => $query]));
        $this->assertTrue($action->performAction($query));
        $elements->trigger(Elements::EVENT_AFTER_PERFORM_ACTION, new ElementActionEvent(['action' => $action, 'criteria' => $query]));
    }

    private function asCpRequest(User $user, Closure $callback): void
    {
        /** @var Request $request */
        $request = Craft::$app->getRequest();
        $path = $request->getPathInfo();
        $console = $request->getIsConsoleRequest();
        $request->setIsConsoleRequest(false);
        $request->setIsCpRequest(true);
        $request->setPathInfo('admin/entries/pages');
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

    /**
     * @param ElementInterface[] $elements
     * @return int[]
     */
    private function ids(array $elements): array
    {
        return array_map(fn(ElementInterface $element) => (int)$element->id, $elements);
    }

    /**
     * The batch rows written to the table since the test began, oldest first, remembered for removal.
     *
     * @return array<int, array<string, mixed>>
     */
    private function stored(): array
    {
        $rows = (new \craft\db\Query())
            ->from(Table::EVENTS)
            ->where(['event' => ElementWatcher::EVENT_BULK])
            ->andWhere(['not', ['uid' => $this->written ?: ['']]])
            ->orderBy(['id' => SORT_ASC])
            ->all($this->db);
        foreach ($rows as $row) {
            $this->written[] = (string)$row['uid'];
        }

        return $rows;
    }

    /**
     * @return int[] the element ids a batch row lists
     */
    private function listed(int $eventId): array
    {
        return array_map('intval', (new \craft\db\Query())
            ->select(['elementId'])
            ->from(Table::EVENT_ELEMENTS)
            ->where(['eventId' => $eventId])
            ->column($this->db));
    }

    private function flush(): void
    {
        $this->recorder->flush();
    }

    /**
     * Writes away the rows recorded so far — the test's own setting-up — and remembers them.
     */
    private function clear(): void
    {
        foreach ($this->recorder->getReady() as $row) {
            $this->written[] = (string)$row->uid;
        }
        $this->recorder->flush();
    }
}
