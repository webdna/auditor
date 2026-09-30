<?php

namespace webdna\auditor\services;

use Craft;
use craft\db\Connection;
use craft\elements\User;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use DateTime;
use Throwable;
use webdna\auditor\Auditor;
use webdna\auditor\db\Table;
use webdna\auditor\events\RecordEvent;
use webdna\auditor\models\Event;
use webdna\auditor\models\Settings;
use yii\base\Component;

/**
 * Collects the log's rows while a request runs and writes them together at the end.
 *
 * A row is **ready** or **pending** (BR-4). Recorded with no transaction open, it is ready at once.
 * Recorded inside one, it is pending until the outermost commit makes it ready, or the outermost
 * rollback throws it away. Yii raises those two events at the outermost level only, so a savepoint
 * rolled back inside a committed transaction goes unnoticed and its rows are written.
 *
 * {@see flush()} writes every ready row in one batch insert (BR-3). The plugin calls it at the end
 * of each request and after each queue job; the first row recorded also registers a shutdown
 * function as a backstop. A request that records nothing never touches the database (BR-34).
 *
 * Nothing here throws (BR-2): a failure is logged to the `auditor` category and the row dropped.
 *
 * @since 1.0.0
 */
class Recorder extends Component
{
    /**
     * Raised for each row before it is buffered, with a {@see RecordEvent}. A handler may change the
     * row, or set `isValid` to false to skip it.
     */
    public const EVENT_BEFORE_RECORD = 'beforeRecord';

    /**
     * The connection whose transactions the rows follow, and which they are written to. Null means
     * Craft's own — the one element saves run in.
     */
    public ?Connection $db = null;

    /**
     * Null means the plugin's settings.
     */
    public ?Settings $settings = null;

    /**
     * @var Event[] Rows waiting for the outermost transaction to commit.
     */
    private array $pending = [];

    /**
     * @var Event[] Rows the next flush writes.
     */
    private array $ready = [];

    private ?string $requestId = null;

    /**
     * The request's id while a job it runs has one of its own.
     */
    private ?string $outerRequestId = null;

    /**
     * The class of the queue job running now, if any.
     */
    private ?string $job = null;

    /**
     * @var int[] Deleted users whose rows wait for the outermost transaction to commit (BR-26).
     */
    private array $pendingActors = [];

    /**
     * @var int[] Deleted users whose rows the next flush rewrites.
     */
    private array $readyActors = [];

    private bool $listening = false;

    private bool $backstop = false;

    /**
     * Buffers a row, filling in who, where from and when for whatever the caller left unset.
     *
     * A row is not written here: see {@see flush()}.
     */
    public function record(Event $row): void
    {
        try {
            $context = $row->context ?? $this->context();
            if (!$this->getSettings()->records($context)) {
                return;
            }

            $this->complete($row, $context);

            if ($this->hasEventHandlers(self::EVENT_BEFORE_RECORD)) {
                $event = new RecordEvent(['row' => $row]);
                $this->trigger(self::EVENT_BEFORE_RECORD, $event);
                if (!$event->isValid) {
                    return;
                }
            }

            $this->buffer($row);
        } catch (Throwable $e) {
            $this->fail('Could not record an event', $e);
        }
    }

    /**
     * Whether a row recorded now would be kept (BR-5) — asked by a watcher before it spends a query
     * on a row the context would drop anyway.
     */
    public function isRecording(): bool
    {
        try {
            return $this->getSettings()->records($this->context());
        } catch (Throwable $e) {
            $this->fail('Could not tell whether to record', $e);
            return false;
        }
    }

    /**
     * Forgets the rows recorded for a candidate batch that turned out to be one: the batch row
     * stands for them (BR-14).
     */
    public function discard(string $batch): void
    {
        $keep = fn(Event $row) => $row->batch !== $batch;
        $this->pending = array_values(array_filter($this->pending, $keep));
        $this->ready = array_values(array_filter($this->ready, $keep));
    }

    /**
     * Writes every ready row in one insert, and forgets them whether or not the insert worked.
     *
     * A batch row is the exception: it is inserted on its own, because its id is needed for the list
     * of elements it covered, which follows it in the same transaction (BR-15).
     *
     * With a transaction still open nothing is written, because the insert would become part of
     * that transaction and go if it rolled back; the rows wait for the next flush.
     */
    public function flush(): void
    {
        if ($this->ready === [] && $this->readyActors === []) {
            return;
        }

        try {
            $db = $this->getDb();
            if ($db->getTransaction() !== null) {
                return;
            }

            $rows = $this->ready;
            $this->ready = [];

            $batches = array_filter($rows, fn(Event $row) => $row->elements !== []);
            if ($batches === []) {
                $this->insertRows($db, $rows);
            } else {
                $db->transaction(function() use ($db, $rows, $batches) {
                    $this->insertRows($db, array_filter($rows, fn(Event $row) => $row->elements === []));
                    foreach ($batches as $row) {
                        $this->insertBatch($db, $row);
                    }
                });
            }
        } catch (Throwable $e) {
            $this->ready = [];
            $this->fail('Could not write the log’s rows', $e);
        }

        $this->rewriteActors();
    }

    /**
     * Takes a deleted user's name out of the log (BR-26) once the deletion commits, and after the
     * rows this request recorded are written — the user's own among them — so none escapes.
     *
     * Not bound by the recording settings: a user deleted where nothing is recorded still has
     * their name removed from the rows recorded elsewhere.
     */
    public function anonymiseActor(int $userId): void
    {
        try {
            $this->backstop();
            $db = $this->getDb();
            if ($db->getTransaction() === null) {
                $this->readyActors[] = $userId;
                return;
            }
            $this->listen($db);
            $this->pendingActors[] = $userId;
        } catch (Throwable $e) {
            $this->fail('Could not take a deleted user out of the log', $e);
        }
    }

    /**
     * Called as a queue job starts: its rows are tagged as the job's, under a request id of their own.
     */
    public function beginJob(object $job): void
    {
        // Anything left over belongs to whatever ran before the job.
        $this->flush();
        $this->job = $job::class;
        $this->outerRequestId = $this->requestId;
        $this->requestId = null;
    }

    /**
     * Called as a queue job ends, whether it succeeded or failed.
     */
    public function endJob(): void
    {
        $this->flush();
        $this->job = null;
        // A job run inside a web request hands the request its own id back.
        $this->requestId = $this->outerRequestId;
        $this->outerRequestId = null;
    }

    /**
     * Whether a row is still buffered, so a watcher can add to it what the same change reports in
     * a second event (a user group's permissions, saved after the group). A row already written
     * is never changed (BR-1).
     */
    public function holds(Event $row): bool
    {
        return in_array($row, $this->ready, true) || in_array($row, $this->pending, true);
    }

    /**
     * Rows held for a transaction that has not yet committed. For tests.
     *
     * @return Event[]
     * @phpstan-impure
     */
    public function getPending(): array
    {
        return $this->pending;
    }

    /**
     * Rows the next flush writes. For tests.
     *
     * @return Event[]
     * @phpstan-impure
     */
    public function getReady(): array
    {
        return $this->ready;
    }

    public function getDb(): Connection
    {
        return $this->db ?? Craft::$app->getDb();
    }

    public function getSettings(): Settings
    {
        return $this->settings ?? Auditor::getInstance()->getSettings();
    }

    /**
     * Where the request came from (BR-5).
     */
    private function context(): string
    {
        if ($this->job !== null) {
            return Settings::CONTEXT_QUEUE;
        }

        $request = Craft::$app->getRequest();
        if ($request->getIsConsoleRequest()) {
            return Settings::CONTEXT_CONSOLE;
        }

        /** @var \craft\web\Request $request */
        return $request->getIsCpRequest() ? Settings::CONTEXT_CP : Settings::CONTEXT_SITE;
    }

    /**
     * The route, command or job class that goes with the context.
     */
    private function contextDetail(string $context): ?string
    {
        if ($context === Settings::CONTEXT_QUEUE) {
            return $this->job;
        }

        $request = Craft::$app->getRequest();
        if ($request instanceof \craft\console\Request) {
            return $request->getParams()[0] ?? null;
        }

        if ($request->getIsConsoleRequest()) {
            return Craft::$app->requestedRoute ?: null;
        }

        /** @var \craft\web\Request $request */
        if ($request->getIsActionRequest()) {
            return 'actions/' . implode('/', $request->getActionSegments() ?? []);
        }

        return '/' . $request->getPathInfo();
    }

    private function complete(Event $row, string $context): void
    {
        $row->context = $context;
        $row->contextDetail ??= $this->contextDetail($context);
        $row->requestId ??= $this->requestId ??= StringHelper::UUID();
        $row->dateCreated ??= new DateTime();
        $row->uid ??= StringHelper::UUID();

        $request = Craft::$app->getRequest();
        $web = $context === Settings::CONTEXT_CP || $context === Settings::CONTEXT_SITE;

        if ($row->actorLabel === null) {
            $actor = $this->actor();
            $row->actorId = $actor?->id;
            $row->actorLabel = $actor ? $actor->email : Event::SYSTEM;
            // Asked only when someone is signed in: it reads the session and loads a user.
            if ($actor && $web) {
                $row->impersonatorId ??= Craft::$app->getUser()->getImpersonatorId();
            }
        }

        if ($web && $request instanceof \craft\web\Request) {
            $row->ip ??= $request->getUserIP();
            $row->userAgent ??= $request->getUserAgent();
        }
    }

    private function actor(): ?User
    {
        if (!Craft::$app->has('user')) {
            return null;
        }

        /** @var User|null */
        return Craft::$app->getUser()->getIdentity();
    }

    private function buffer(Event $row): void
    {
        $db = $this->getDb();
        $this->backstop();

        if ($db->getTransaction() === null) {
            $this->ready[] = $row;
            return;
        }

        $this->listen($db);
        $this->pending[] = $row;
    }

    private function backstop(): void
    {
        if (!$this->backstop) {
            $this->backstop = true;
            register_shutdown_function([$this, 'flush']);
        }
    }

    /**
     * Follows the outermost transaction: its commit readies what waited on it, its rollback
     * forgets it.
     */
    private function listen(Connection $db): void
    {
        if ($this->listening) {
            return;
        }

        $this->listening = true;
        $db->on(Connection::EVENT_COMMIT_TRANSACTION, function() {
            array_push($this->ready, ...$this->pending);
            array_push($this->readyActors, ...$this->pendingActors);
            $this->pending = [];
            $this->pendingActors = [];
        });
        $db->on(Connection::EVENT_ROLLBACK_TRANSACTION, function() {
            $this->pending = [];
            $this->pendingActors = [];
        });
    }

    private function rewriteActors(): void
    {
        $users = array_unique($this->readyActors);
        $this->readyActors = [];
        if ($users === []) {
            return;
        }

        try {
            $retention = $this->db !== null
                ? new Retention(['db' => $this->db])
                : Auditor::getInstance()->getRetention();
            foreach ($users as $userId) {
                $retention->anonymiseActor($userId);
            }
        } catch (Throwable $e) {
            $this->fail('Could not take a deleted user out of the log', $e);
        }
    }

    /**
     * @param Event[] $rows
     */
    private function insertRows(Connection $db, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $db->createCommand()
            ->batchInsert(Table::EVENTS, Event::COLUMNS, array_map($this->toRow(...), array_values($rows)))
            ->execute();
    }

    private function insertBatch(Connection $db, Event $row): void
    {
        $db->createCommand()
            ->insert(Table::EVENTS, array_combine(Event::COLUMNS, $this->toRow($row)))
            ->execute();
        $id = (int)$db->getLastInsertID();

        // Chunked: a resave of every entry on a large site lists tens of thousands.
        foreach (array_chunk($row->elements, 1000) as $chunk) {
            $db->createCommand()
                ->batchInsert(Table::EVENT_ELEMENTS, ['eventId', 'elementId', 'siteId'], array_map(
                    fn(array $element) => [$id, $element[0], $element[1]],
                    $chunk,
                ))
                ->execute();
        }
    }

    /**
     * @return array<int, mixed>
     */
    private function toRow(Event $row): array
    {
        return [
            $row->family,
            $row->event,
            $row->elementId,
            $row->elementType,
            $row->siteId,
            $this->truncate($row->elementLabel, 255),
            $row->ownerId,
            $row->actorId,
            $this->truncate($row->actorLabel, 255),
            $row->impersonatorId,
            $row->context,
            $this->truncate($row->contextDetail, 255),
            $this->truncate($row->ip, 45),
            $this->truncate($row->userAgent, 255),
            $row->requestId,
            // The query builder encodes a JSON column's value itself; a string would be encoded twice.
            $row->data,
            Db::prepareDateForDb($row->dateCreated),
            $row->uid,
        ];
    }

    private function truncate(?string $value, int $length): ?string
    {
        return $value === null ? null : mb_substr($value, 0, $length);
    }

    private function fail(string $message, Throwable $e): void
    {
        try {
            Craft::error(sprintf('%s: %s', $message, $e->getMessage()), Auditor::HANDLE);
        } catch (Throwable) {
            // Logging itself failed; there is nowhere left to report it, and BR-2 forbids throwing.
        }
    }
}
