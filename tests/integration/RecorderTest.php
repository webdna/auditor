<?php

namespace webdna\auditor\tests\integration;

use Codeception\Test\Unit;
use Craft;
use craft\console\Request as ConsoleRequest;
use craft\db\Connection;
use craft\helpers\Json;
use craft\queue\Queue;
use RuntimeException;
use webdna\auditor\Auditor;
use webdna\auditor\db\Table;
use webdna\auditor\events\RecordEvent;
use webdna\auditor\migrations\m260930_000000_create_tables;
use webdna\auditor\models\Event;
use webdna\auditor\models\Settings;
use webdna\auditor\services\Recorder;
use webdna\auditor\tests\integration\jobs\RecordingJob;
use Yii;
use yii\base\Application;
use yii\log\Logger;

/**
 * The recorder buffers rows, follows the outermost transaction and writes once per flush, and never
 * throws (BR-1 to BR-5; TS-6, TN-1, TN-2, TN-11, TN-12).
 *
 * The recorder under test runs on a second connection. The harness wraps every test in a
 * transaction on the main one, so a recorder there would hold every row pending for ever; the
 * second connection has no transaction but the ones a test opens, and its commits are real — so
 * each test deletes what it wrote.
 */
class RecorderTest extends Unit
{
    use SecondConnection;
    use WebRequests;

    private Connection $db;

    private Recorder $recorder;

    protected function _before(): void
    {
        $this->db = $this->secondConnection();
        $this->db->createCommand()->delete(Table::EVENTS)->execute();

        $this->recorder = new Recorder(['db' => $this->db, 'settings' => new Settings()]);
    }

    protected function _after(): void
    {
        $this->db->createCommand()->delete(Table::EVENTS)->execute();
        $this->db->close();
    }

    /**
     * TN-1: a console save with nobody signed in is recorded as "System", with no address.
     */
    public function testARowOutsideATransactionIsWrittenOnFlushAsSystemFromTheConsole(): void
    {
        $this->recorder->record($this->row(['data' => ['title' => ['label' => 'Title', 'old' => 'A', 'new' => 'B']]]));

        $this->assertCount(1, $this->recorder->getReady());
        $this->assertSame([], $this->rows(), 'Recording writes nothing; only a flush does (BR-3).');

        $this->recorder->flush();

        $rows = $this->rows();
        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame('element', $row['family']);
        $this->assertSame('saved', $row['event']);
        $this->assertNull($row['actorId']);
        $this->assertSame(Event::SYSTEM, $row['actorLabel']);
        $this->assertNull($row['impersonatorId']);
        $this->assertSame(Settings::CONTEXT_CONSOLE, $row['context']);
        $this->assertNull($row['ip']);
        $this->assertNull($row['userAgent']);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $row['requestId']);
        $this->assertNotEmpty($row['dateCreated']);
        $this->assertNotEmpty($row['uid']);
        // Equal, not identical: MySQL stores a JSON object with its keys reordered.
        $this->assertEquals(['title' => ['label' => 'Title', 'old' => 'A', 'new' => 'B']], Json::decode($row['data']));
        $this->assertSame([], $this->recorder->getReady());
    }

    /**
     * BR-3: one flush writes every ready row, all under the request's one id.
     */
    public function testOneFlushWritesEveryReadyRowUnderOneRequestId(): void
    {
        foreach (['one', 'two', 'three'] as $label) {
            $this->recorder->record($this->row(['elementLabel' => $label]));
        }
        $this->recorder->flush();

        $rows = $this->rows();
        $this->assertSame(['one', 'two', 'three'], array_column($rows, 'elementLabel'));
        $this->assertCount(1, array_unique(array_column($rows, 'requestId')));

        // Flushing again writes nothing more.
        $this->recorder->flush();
        $this->assertCount(3, $this->rows());
    }

    public function testLongValuesAreTruncatedToTheirColumns(): void
    {
        $this->recorder->record($this->row(['elementLabel' => str_repeat('é', 300)]));
        $this->recorder->flush();

        $this->assertSame(255, mb_strlen($this->rows()[0]['elementLabel']));
    }

    /**
     * TS-6 step 2 (AC-7): a row recorded inside a transaction that rolls back is never written.
     */
    public function testARowRecordedInATransactionThatRollsBackIsDiscarded(): void
    {
        $transaction = $this->db->beginTransaction();
        $this->recorder->record($this->row());
        $this->assertCount(1, $this->recorder->getPending());
        $this->assertSame([], $this->recorder->getReady());
        $transaction->rollBack();

        $this->assertSame([], $this->recorder->getPending());
        $this->recorder->flush();
        $this->assertSame([], $this->rows());
    }

    /**
     * TS-6 step 3: a row recorded inside a transaction that commits is written.
     */
    public function testARowRecordedInATransactionThatCommitsIsWritten(): void
    {
        $transaction = $this->db->beginTransaction();
        $this->recorder->record($this->row());
        $transaction->commit();

        $this->assertSame([], $this->recorder->getPending());
        $this->assertCount(1, $this->recorder->getReady());
        $this->recorder->flush();
        $this->assertCount(1, $this->rows());
    }

    /**
     * BR-4: only the outermost commit releases a row — an inner one is a savepoint release.
     */
    public function testARowWaitsForTheOutermostCommit(): void
    {
        $outer = $this->db->beginTransaction();
        $inner = $this->db->beginTransaction();
        $this->recorder->record($this->row());
        $inner->commit();

        $this->assertCount(1, $this->recorder->getPending());
        $this->assertSame([], $this->recorder->getReady());

        $outer->commit();
        $this->assertCount(1, $this->recorder->getReady());
    }

    /**
     * BR-4: an outermost rollback discards a row even when the savepoint it was recorded in committed.
     */
    public function testTheOutermostRollbackDiscardsARowWhoseSavepointCommitted(): void
    {
        $outer = $this->db->beginTransaction();
        $inner = $this->db->beginTransaction();
        $this->recorder->record($this->row());
        $inner->commit();
        $outer->rollBack();

        $this->recorder->flush();
        $this->assertSame([], $this->rows());
    }

    /**
     * TN-12: Yii raises no event for a savepoint rolled back inside a committed transaction, so the
     * row is written. The documented BR-4 limitation, pinned so a change to it is noticed.
     */
    public function testARowInARolledBackSavepointOfACommittedTransactionIsWritten(): void
    {
        $outer = $this->db->beginTransaction();
        $inner = $this->db->beginTransaction();
        $this->recorder->record($this->row());
        $inner->rollBack();
        $outer->commit();

        $this->recorder->flush();
        $this->assertCount(1, $this->rows());
    }

    /**
     * A flush with a transaction open writes nothing — the insert would join that transaction — and
     * the rows wait for the next flush.
     */
    public function testAFlushWithATransactionOpenWaits(): void
    {
        $this->recorder->record($this->row());

        $transaction = $this->db->beginTransaction();
        $this->recorder->flush();
        $transaction->rollBack();

        $this->assertCount(1, $this->recorder->getReady());
        $this->recorder->flush();
        $this->assertCount(1, $this->rows());
    }

    /**
     * TN-2 (BR-2): with the table missing mid-deploy, a flush logs the failure and throws nothing.
     */
    public function testAMissingTableIsLoggedAndNeverThrown(): void
    {
        // The harness's logger keeps only a few lines of text; a plain one keeps every message.
        $harnessLogger = Yii::getLogger();
        $logger = new Logger(['flushInterval' => PHP_INT_MAX]);
        Yii::setLogger($logger);

        try {
            $this->db->createCommand()->dropTable(Table::EVENT_ELEMENTS)->execute();
            $this->db->createCommand()->dropTable(Table::EVENTS)->execute();

            $this->recorder->record($this->row());
            $this->recorder->flush();

            $this->assertSame([], $this->recorder->getReady(), 'A failed insert is not retried.');
            $this->assertCount(1, $this->auditorErrors($logger));
        } finally {
            Yii::setLogger($harnessLogger);
            (new m260930_000000_create_tables(['db' => $this->db]))->up();
        }
    }

    /**
     * BR-2: a throwing handler drops its row and throws nothing.
     */
    public function testAThrowingHandlerDropsTheRowAndThrowsNothing(): void
    {
        $this->recorder->on(Recorder::EVENT_BEFORE_RECORD, function() {
            throw new RuntimeException('A handler failed.');
        });

        $this->recorder->record($this->row());

        $this->assertSame([], $this->recorder->getReady());
    }

    public function testAHandlerCanChangeARowOrSkipIt(): void
    {
        $this->recorder->on(Recorder::EVENT_BEFORE_RECORD, function(RecordEvent $event) {
            if ($event->row->elementLabel === 'skip') {
                $event->isValid = false;
            }
            $event->row->contextDetail = 'changed';
        });

        $this->recorder->record($this->row(['elementLabel' => 'skip']));
        $this->recorder->record($this->row(['elementLabel' => 'keep']));
        $this->recorder->flush();

        $rows = $this->rows();
        $this->assertSame(['keep'], array_column($rows, 'elementLabel'));
        $this->assertSame('changed', $rows[0]['contextDetail']);
    }

    /**
     * BR-5: the master switch and the context list.
     */
    public function testNothingIsRecordedWhenOffOrInAContextLeftOut(): void
    {
        $this->recorder->settings = new Settings(['enabled' => false]);
        $this->recorder->record($this->row());
        $this->assertSame([], $this->recorder->getReady());

        $this->recorder->settings = new Settings(['contexts' => [Settings::CONTEXT_CP, Settings::CONTEXT_SITE]]);
        $this->recorder->record($this->row());
        $this->assertSame([], $this->recorder->getReady());

        $this->recorder->settings = new Settings(['contexts' => [Settings::CONTEXT_CONSOLE]]);
        $this->recorder->record($this->row());
        $this->assertCount(1, $this->recorder->getReady());
    }

    /**
     * BR-5 (#6): a script that boots the console app itself is named by its file, never by its
     * first argument; a command run through `craft` is named by the command.
     */
    public function testAConsoleRowNamesItsCommandOrItsScript(): void
    {
        $request = Craft::$app->getRequest();
        $route = Craft::$app->requestedRoute;
        $argv = $_SERVER['argv'] ?? null;
        $console = new ConsoleRequest();
        Craft::$app->set('request', $console);

        try {
            $_SERVER['argv'] = ['/var/www/scripts/import-orders.php', '--check'];
            // A script that never runs the app leaves the route unset, whatever Yii's type says.
            Craft::$app->requestedRoute = null; // @phpstan-ignore assign.propertyType
            $this->recorder->record($this->row());

            $console->setParams(['resave/entries', '--elementId=1,2']);
            Craft::$app->requestedRoute = 'resave/entries';
            $this->recorder->record($this->row());
        } finally {
            Craft::$app->set('request', $request);
            Craft::$app->requestedRoute = $route;
            $_SERVER['argv'] = $argv;
        }

        $rows = $this->recorder->getReady();
        $this->assertSame([Settings::CONTEXT_CONSOLE, Settings::CONTEXT_CONSOLE], array_map(fn(Event $row) => $row->context, $rows));
        $this->assertSame(['import-orders.php', 'resave/entries'], array_map(fn(Event $row) => $row->contextDetail, $rows));
    }

    /**
     * §4 `actorLabel` (#6): a site request with nobody signed in is a visitor, so a form a visitor
     * posts reads apart from console and queue work; a control-panel request with nobody signed in,
     * and a command, stay "System".
     */
    public function testASiteRequestWithNobodySignedInIsAVisitor(): void
    {
        $this->asWebRequest(fn() => $this->recorder->record($this->row()));
        $this->asWebRequest(fn() => $this->recorder->record($this->row()), true);
        $this->recorder->record($this->row());

        $rows = $this->recorder->getReady();
        $this->assertSame(
            [[Settings::CONTEXT_SITE, Event::VISITOR], [Settings::CONTEXT_CP, Event::SYSTEM], [Settings::CONTEXT_CONSOLE, Event::SYSTEM]],
            array_map(fn(Event $row) => [$row->context, $row->actorLabel], $rows),
        );
        $this->assertSame([null, null, null], array_map(fn(Event $row) => $row->actorId, $rows));
        $this->assertSame('192.0.2.1', $rows[0]->ip, 'A visitor still has an address');
        $this->assertSame('Visitor', Event::VISITOR, 'Stored in English, like "System"');
    }

    /**
     * TN-11 (BR-3, BR-4), through a real queue run and the plugin's own flush points: a job that
     * fails after recording inside its own transaction leaves nothing, and the rows of the job
     * before it — flushed as that job ended — stay. Each job's rows are tagged with it.
     */
    public function testAFailedJobsRowsGoWithItsRollbackAndEarlierJobsStay(): void
    {
        $plugin = Auditor::getInstance();
        $plugin->set('recorder', $this->recorder);

        try {
            $queue = Craft::$app->getQueue();
            $this->assertInstanceOf(Queue::class, $queue);
            $queue->push(new RecordingJob(['label' => 'first', 'rows' => 1]));
            $queue->push(new RecordingJob(['label' => 'failed', 'rows' => 2, 'fail' => true]));
            $queue->push(new RecordingJob(['label' => 'third', 'rows' => 1]));
            $queue->run();
        } finally {
            $plugin->set('recorder', Recorder::class);
        }

        $rows = $this->rows();
        $this->assertSame(['first', 'third'], array_column($rows, 'elementLabel'));
        $this->assertSame([Settings::CONTEXT_QUEUE, Settings::CONTEXT_QUEUE], array_column($rows, 'context'));
        $this->assertSame([RecordingJob::class, RecordingJob::class], array_column($rows, 'contextDetail'));
        $this->assertCount(2, array_unique(array_column($rows, 'requestId')), 'Each job is a request of its own.');
        $this->assertSame([], $this->recorder->getPending());
        $this->assertSame([], $this->recorder->getReady());
    }

    /**
     * BR-3: the plugin flushes at the end of a request. BR-34: when nothing created the recorder,
     * the end of the request does not create one.
     */
    public function testTheEndOfARequestFlushesAndCreatesNoRecorderWhenNothingWasRecorded(): void
    {
        $plugin = Auditor::getInstance();

        try {
            $plugin->set('recorder', Recorder::class);
            Craft::$app->trigger(Application::EVENT_AFTER_REQUEST);
            $this->assertFalse($plugin->has('recorder', true));

            $plugin->set('recorder', $this->recorder);
            $plugin->getRecorder()->record($this->row());
            Craft::$app->trigger(Application::EVENT_AFTER_REQUEST);
        } finally {
            $plugin->set('recorder', Recorder::class);
        }

        $this->assertCount(1, $this->rows());
    }

    /**
     * @param array<string, mixed> $config
     */
    private function row(array $config = []): Event
    {
        return new Event($config + ['family' => Event::FAMILY_ELEMENT, 'event' => 'saved']);
    }

    /**
     * @return array<int, array<string, mixed>>
     * @phpstan-impure
     */
    private function rows(): array
    {
        return $this->db->createCommand('SELECT * FROM ' . Table::EVENTS . ' ORDER BY [[id]]')->queryAll();
    }

    /**
     * @return array<int, mixed>
     */
    private function auditorErrors(Logger $logger): array
    {
        return array_values(array_filter(
            $logger->messages,
            fn(array $message) => $message[1] === Logger::LEVEL_ERROR && $message[2] === Auditor::HANDLE,
        ));
    }
}
