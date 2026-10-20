<?php

namespace webdna\auditor\tests\integration;

use Codeception\Test\Unit;
use Craft;
use craft\db\Connection;
use craft\db\Query;
use craft\elements\Address;
use craft\elements\Entry;
use craft\elements\User;
use craft\helpers\Json;
use craft\services\Gc;
use DateInterval;
use DateTime;
use webdna\auditor\Auditor;
use webdna\auditor\console\controllers\AnonymiseActorController;
use webdna\auditor\console\controllers\PruneController;
use webdna\auditor\db\Table;
use webdna\auditor\models\Event;
use webdna\auditor\models\Settings;
use webdna\auditor\services\Recorder;
use webdna\auditor\services\Retention;
use webdna\auditor\tests\Support\TestSite;
use webdna\auditor\watchers\AuthWatcher;
use webdna\auditor\watchers\ElementWatcher;
use yii\console\ExitCode;

/**
 * Rows go by age and a deleted user's name goes from their rows, and nothing else ever changes
 * a written row (BR-1, BR-25, BR-26; TS-8, TS-11).
 *
 * Both the recorder and the retention service run on a second connection, whose commits are
 * real, so rows can be written, pruned and rewritten as they would be on a site; every test
 * empties the table before and after. The users and entries are built inside the harness's
 * transaction on Craft's connection, as usual.
 */
class RetentionTest extends Unit
{
    use SecondConnection;

    private Auditor $plugin;

    private Connection $db;

    private Recorder $recorder;

    private Retention $retention;

    /**
     * @var array<string, mixed>
     */
    private array $settings;

    protected function _before(): void
    {
        $this->plugin = Auditor::getInstance();
        $this->settings = $this->plugin->getSettings()->toArray();

        $this->db = $this->secondConnection();
        $this->db->createCommand()->delete(Table::EVENTS)->execute();

        $this->recorder = new Recorder(['db' => $this->db]);
        $this->retention = new Retention(['db' => $this->db]);
        $this->plugin->set('recorder', $this->recorder);
        $this->plugin->set('retention', $this->retention);
    }

    protected function _after(): void
    {
        Craft::$app->getUser()->setIdentity(null);
        $this->db->createCommand()->delete(Table::EVENTS)->execute();
        $this->db->close();
        $this->plugin->set('recorder', Recorder::class);
        $this->plugin->set('retention', Retention::class);
        $this->plugin->getSettings()->setAttributes($this->settings, false);
        TestSite::removeFiles();
    }

    /**
     * TS-11: with `retentionDays` 365, `auditor/prune` removes the 400-day row, keeps the 10-day
     * one, and says it removed one.
     */
    public function testPruneRemovesOnlyRowsPastRetentionAndPrintsTheCount(): void
    {
        $this->plugin->getSettings()->retentionDays = 365;
        $old = $this->write(['elementLabel' => 'Old'], 400);
        $new = $this->write(['elementLabel' => 'New'], 10);

        [$code, $out] = $this->prune();

        $this->assertSame(ExitCode::OK, $code);
        $this->assertSame("1 rows older than 365 days removed.\n", $out);
        $this->assertSame([$new], $this->ids(), 'The 400-day row is gone and the 10-day row kept');
        $this->assertNotContains($old, $this->ids());
    }

    /**
     * BR-25: a backlog larger than one batch is removed in several deletes, all of it, and a batch
     * row's list of elements goes with it.
     */
    public function testPruneWorksThroughMoreThanOneBatchAndTakesABatchRowsElementsWithIt(): void
    {
        $date = (new DateTime())->sub(new DateInterval('P400D'))->format('Y-m-d H:i:s');
        $rows = [];
        for ($i = 0; $i < Retention::BATCH_SIZE + 1; $i++) {
            $rows[] = ['element', 'saved', 'Old', 'System', 'console', 'r', $date, sprintf('%08d-0000-0000-0000-000000000000', $i)];
        }
        foreach (array_chunk($rows, 2000) as $chunk) {
            $this->db->createCommand()->batchInsert(
                Table::EVENTS,
                ['family', 'event', 'elementLabel', 'actorLabel', 'context', 'requestId', 'dateCreated', 'uid'],
                $chunk,
            )->execute();
        }
        $batch = $this->write(['family' => Event::FAMILY_ELEMENT, 'event' => 'bulk'], 400);
        $this->db->createCommand()->batchInsert(Table::EVENT_ELEMENTS, ['eventId', 'elementId', 'siteId'], [
            [$batch, 1, 1],
            [$batch, 2, 1],
        ])->execute();
        $kept = $this->write([], 1);

        $before = $this->deletesSent();
        $count = $this->retention->prune(365);

        $this->assertSame(Retention::BATCH_SIZE + 2, $count);
        $this->assertSame(2, $this->deletesSent() - $before, 'Two deletes of at most 10,000 rows each');
        $this->assertSame([$kept], $this->ids());
        $this->assertSame(0, (int)(new Query())->from(Table::EVENT_ELEMENTS)->count('*', $this->db));
    }

    /**
     * `--dry-run` counts what would go and removes nothing; `--days` overrides the setting.
     */
    public function testADryRunCountsAndRemovesNothing(): void
    {
        $this->write([], 400);
        $this->write([], 40);

        [$code, $out] = $this->prune(['days' => 30, 'dryRun' => true]);

        $this->assertSame(ExitCode::OK, $code);
        $this->assertSame("2 rows older than 30 days would be removed.\n", $out);
        $this->assertCount(2, $this->ids());
    }

    /**
     * BR-25: 0 keeps every row, from the command and from garbage collection alike; `--days=0`
     * is refused rather than read as "remove everything" or "keep everything".
     */
    public function testRetentionZeroKeepsEveryRowAndDaysZeroIsRefused(): void
    {
        $this->plugin->getSettings()->retentionDays = 0;
        $this->write([], 4000);

        [$code, $out] = $this->prune();
        $this->assertSame(ExitCode::OK, $code);
        $this->assertStringContainsString('every row is kept', $out);
        $this->assertSame(0, $this->retention->prune());

        [$code] = $this->prune(['days' => 0]);
        $this->assertSame(ExitCode::USAGE, $code);
        $this->assertCount(1, $this->ids());
    }

    /**
     * BR-25: Craft's garbage collection prunes with the setting.
     */
    public function testGarbageCollectionPrunes(): void
    {
        $this->plugin->getSettings()->retentionDays = 30;
        $this->write([], 31);
        $kept = $this->write([], 29);

        Craft::$app->getGc()->trigger(Gc::EVENT_RUN);

        $this->assertSame([$kept], $this->ids());
    }

    /**
     * TS-8: `editor` saves an entry and an address of their own, an admin changes their email
     * address, and they are deleted, in the same request. The rows they made read "Deleted user
     * #<id>" with the id kept. The rows about them — their creation, the admin's save, the email
     * change, a failed sign-in, their deletion — read "Deleted user #<id>" too, every key in
     * `data` kept and every value `redacted: true`; their address's rows read "Address #<id>",
     * redacted alike. Neither address, nor their name or street, is left anywhere in the table,
     * while the admin's name and the entry's title stay.
     */
    public function testDeletingAUserTakesTheirNameOutOfTheirRows(): void
    {
        $site = TestSite::build();
        $editor = $site->user('editor');
        $editor->firstName = 'Edna';
        $site->save($editor);
        $admin = $site->user('admin', true);
        $failure = $this->write([
            'family' => Event::FAMILY_AUTH,
            'event' => AuthWatcher::EVENT_LOGIN_FAILED,
            'elementId' => $editor->id,
            'elementType' => User::class,
            'elementLabel' => 'editor@example.com',
            'actorId' => $editor->id,
            'actorLabel' => 'editor@example.com',
            'data' => ['username' => 'editor@example.com', 'reason' => 'invalid_credentials'],
        ], 2);

        Craft::$app->getUser()->setIdentity($editor);
        $entry = $site->entry('Theirs', [], $editor);
        $address = new Address([
            'ownerId' => $editor->id,
            'title' => 'Home',
            'fullName' => 'Edna Editor',
            'addressLine1' => '1 Secret Street',
            'locality' => 'London',
            'countryCode' => 'GB',
        ]);
        $site->save($address);

        Craft::$app->getUser()->setIdentity($admin);
        $editor->email = 'edna@example.org';
        $site->save($editor);
        $this->assertTrue(Craft::$app->getElements()->deleteElement($editor));
        $this->recorder->flush();

        $label = 'Deleted user #' . $editor->id;
        $created = $this->row(['event' => ElementWatcher::EVENT_CREATED, 'elementId' => $entry->id]);
        $this->assertSame($label, $created['actorLabel']);
        $this->assertSame((int)$editor->id, (int)$created['actorId'], 'The id stays');
        $this->assertSame('Theirs', $created['elementLabel'], 'Not a row about them');

        $failed = $this->row(['id' => $failure]);
        $this->assertSame($label, $failed['actorLabel']);
        $this->assertSame($label, $failed['elementLabel']);
        $this->assertEquals(['username' => ['redacted' => true], 'reason' => ['redacted' => true]], Json::decode($failed['data']));

        $aboutThem = (new Query())->from(Table::EVENTS)->where(['elementId' => $editor->id])->all($this->db);
        $events = array_column($aboutThem, 'event');
        foreach ([ElementWatcher::EVENT_CREATED, ElementWatcher::EVENT_SAVED, AuthWatcher::EVENT_EMAIL_CHANGED, ElementWatcher::EVENT_DELETED] as $event) {
            $this->assertContains($event, $events);
        }
        foreach ($aboutThem as $row) {
            $this->assertSame($label, $row['elementLabel'], $row['event']);
            $this->assertRedacted($row);
        }
        $saved = $this->row(['event' => ElementWatcher::EVENT_SAVED, 'elementId' => $editor->id, 'actorId' => $admin->id]);
        $this->assertArrayHasKey('email', Json::decode($saved['data']), 'Which fields changed stays');
        $this->assertSame('admin@example.com', $saved['actorLabel'], 'Recorded, by whoever changed them');
        $deleted = $this->row(['event' => ElementWatcher::EVENT_DELETED, 'elementId' => $editor->id]);
        $this->assertSame('admin@example.com', $deleted['actorLabel'], 'Recorded, by whoever deleted them');

        $theirAddress = (new Query())->from(Table::EVENTS)->where(['elementId' => $address->id])->all($this->db);
        $this->assertNotSame([], $theirAddress);
        foreach ($theirAddress as $row) {
            $this->assertSame('Address #' . $address->id, $row['elementLabel'], $row['event']);
            $this->assertRedacted($row);
        }

        $table = Json::encode(array_map(
            fn(array $row) => array_diff_key($row, ['ip' => true, 'userAgent' => true]),
            (new Query())->from(Table::EVENTS)->all($this->db),
        ));
        foreach (['editor@example.com', 'edna@example.org', 'Edna', 'Secret Street'] as $trace) {
            $this->assertStringNotContainsString($trace, $table);
        }
        $this->assertStringContainsString('admin@example.com', $table);
        $this->assertSame($entry->title, Entry::find()->id($entry->id)->status(null)->trashed(null)->one()?->title);
    }

    /**
     * BR-26 waits for the deletion's transaction: a deletion rolled back rewrites nothing, one
     * committed rewrites once the flush comes.
     */
    public function testTheRewriteFollowsTheDeletionsTransaction(): void
    {
        $row = $this->write(['actorId' => 9001, 'actorLabel' => 'gone@example.com'], 1);

        $transaction = $this->db->beginTransaction();
        $this->recorder->anonymiseActor(9001);
        $transaction->rollBack();
        $this->recorder->flush();
        $this->assertSame('gone@example.com', $this->row(['id' => $row])['actorLabel']);

        $transaction = $this->db->beginTransaction();
        $this->recorder->anonymiseActor(9001);
        $this->recorder->flush();
        $this->assertSame('gone@example.com', $this->row(['id' => $row])['actorLabel'], 'Nothing while the transaction is open');
        $transaction->commit();
        $this->recorder->flush();
        $this->assertSame('Deleted user #9001', $this->row(['id' => $row])['actorLabel']);
    }

    /**
     * `auditor/anonymise-actor` counts each row rewritten once, whether its actor, its subject or
     * both are the user — a failed sign-in they made against their own account is one row — and
     * a row owned by them (their address) counts too; a second run rewrites nothing.
     */
    public function testAnonymiseActorCountsEachRowOnceAndIsSafeToRepeat(): void
    {
        $this->write(['actorId' => 9002, 'actorLabel' => 'gone@example.com'], 3);
        $this->write([
            'family' => Event::FAMILY_AUTH,
            'event' => AuthWatcher::EVENT_LOGIN_FAILED,
            'elementId' => 9002,
            'actorId' => 9002,
            'actorLabel' => 'gone@example.com',
            'data' => ['username' => 'gone@example.com', 'reason' => 'invalid_credentials'],
        ], 2);
        $this->write([
            'family' => Event::FAMILY_AUTH,
            'event' => AuthWatcher::EVENT_LOGIN_FAILED,
            'elementId' => 9002,
            'data' => ['username' => null, 'reason' => 'invalid_credentials'],
        ], 1);
        $this->write([
            'elementId' => 9002,
            'elementType' => User::class,
            'elementLabel' => 'gone@example.com',
            'data' => ['email' => ['label' => 'Email', 'old' => 'was@example.com', 'new' => 'gone@example.com']],
        ], 1);
        $owned = $this->write([
            'elementId' => 9004,
            'elementType' => Address::class,
            'elementLabel' => 'Home',
            'ownerId' => 9002,
            'data' => ['addressLine1' => ['label' => 'Address Line 1', 'old' => null, 'new' => '1 Secret Street']],
        ], 1);
        $other = $this->write(['actorId' => 9003, 'actorLabel' => 'stays@example.com', 'elementId' => 9003, 'elementLabel' => 'stays@example.com'], 1);

        [$code, $out] = $this->anonymise(9002);
        $this->assertSame(ExitCode::OK, $code);
        $this->assertSame("5 rows rewritten for user #9002.\n", $out);
        $this->assertSame(
            ['addressLine1' => ['label' => 'Address Line 1', 'redacted' => true]],
            Json::decode($this->row(['id' => $owned])['data']),
        );
        $this->assertSame('Address #9004', $this->row(['id' => $owned])['elementLabel']);
        $this->assertSame('stays@example.com', $this->row(['id' => $other])['actorLabel']);
        $this->assertSame('stays@example.com', $this->row(['id' => $other])['elementLabel']);

        [, $out] = $this->anonymise(9002);
        $this->assertSame("0 rows rewritten for user #9002.\n", $out);
    }

    /**
     * The rewrite cannot be undone, so the command refuses a user who still exists.
     */
    public function testAnonymiseActorRefusesALiveUser(): void
    {
        $user = TestSite::build()->user('live');
        $row = $this->write(['actorId' => $user->id, 'actorLabel' => 'live@example.com'], 1);

        [$code] = $this->anonymise((int)$user->id);

        $this->assertSame(ExitCode::UNSPECIFIED_ERROR, $code);
        $this->assertSame('live@example.com', $this->row(['id' => $row])['actorLabel']);
        $this->assertSame(ExitCode::USAGE, $this->anonymise(null)[0]);
    }

    /**
     * Writes one row dated so many days ago through the recorder, and returns its id.
     *
     * @param array<string, mixed> $values
     */
    private function write(array $values, int $daysAgo): int
    {
        $recorder = new Recorder(['db' => $this->db, 'settings' => new Settings()]);
        $recorder->record(new Event(array_merge([
            'family' => Event::FAMILY_ELEMENT,
            'event' => ElementWatcher::EVENT_SAVED,
            'elementLabel' => 'Row',
            'actorLabel' => Event::SYSTEM,
            'dateCreated' => (new DateTime())->sub(new DateInterval("P{$daysAgo}D")),
        ], $values)));
        $recorder->flush();

        return (int)(new Query())->from(Table::EVENTS)->max('id', $this->db);
    }

    /**
     * @return int[]
     */
    private function ids(): array
    {
        return array_map('intval', (new Query())->select('id')->from(Table::EVENTS)->orderBy('id')->column($this->db));
    }

    /**
     * Every key in a row's `data` holds `{redacted: true}`, with its label where it had one.
     *
     * @param array<string, mixed> $row
     */
    private function assertRedacted(array $row): void
    {
        $data = $row['data'] === null ? [] : Json::decode($row['data']);
        foreach ($data as $key => $value) {
            $this->assertSame(true, $value['redacted'] ?? null, "{$row['event']}: $key");
            $this->assertSame([], array_diff(array_keys($value), ['label', 'redacted']), "{$row['event']}: $key");
        }
    }

    /**
     * @param array<string, mixed> $where
     * @return array<string, mixed>
     */
    private function row(array $where): array
    {
        $row = (new Query())->from(Table::EVENTS)->where($where)->one($this->db);
        $this->assertIsArray($row, 'Row ' . Json::encode($where));

        return $row;
    }

    /**
     * The DELETE statements this connection has sent, from MySQL's own counter for the session.
     */
    private function deletesSent(): int
    {
        $row = $this->db->createCommand("SHOW SESSION STATUS LIKE 'Com_delete'")->queryOne();

        return (int)($row['Value'] ?? 0);
    }

    /**
     * @param array<string, mixed> $options
     * @return array{int, string}
     */
    private function prune(array $options = []): array
    {
        $controller = new class('prune', $this->plugin) extends PruneController {
            public string $out = '';

            /**
             * @return int
             */
            public function stdout($string)
            {
                $this->out .= $string;
                return strlen($string);
            }

            /**
             * @return int
             */
            public function stderr($string)
            {
                return $this->stdout($string);
            }
        };
        foreach ($options as $name => $value) {
            $controller->$name = $value;
        }

        return [$controller->actionIndex(), $controller->out];
    }

    /**
     * @return array{int, string}
     */
    private function anonymise(?int $userId): array
    {
        $controller = new class('anonymise-actor', $this->plugin) extends AnonymiseActorController {
            public string $out = '';

            /**
             * @return int
             */
            public function stdout($string)
            {
                $this->out .= $string;
                return strlen($string);
            }

            /**
             * @return int
             */
            public function stderr($string)
            {
                return $this->stdout($string);
            }
        };
        $controller->user = $userId;

        return [$controller->actionIndex(), $controller->out];
    }
}
