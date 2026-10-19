<?php

namespace webdna\auditor\watchers;

use Closure;
use Craft;
use craft\base\ElementInterface;
use craft\base\NestedElementInterface;
use craft\elements\Entry;
use craft\events\BulkOpEvent;
use craft\events\ElementActionEvent;
use craft\events\ElementEvent;
use craft\events\MoveElementEvent;
use craft\helpers\ElementHelper;
use craft\queue\BaseBatchedElementJob;
use craft\services\Elements;
use craft\services\Structures;
use Throwable;
use WeakMap;
use webdna\auditor\Auditor;
use webdna\auditor\models\Batch;
use webdna\auditor\models\Event;
use webdna\auditor\models\Snapshot;
use yii\base\Event as YiiEvent;

/**
 * Turns Craft's element events into rows: `created`, `saved`, `deleted`, `restored` and `moved`.
 *
 * It writes nothing itself. A save is snapshotted before Craft writes it and diffed after, while
 * the element's dirty record still exists (Craft wipes it straight after `EVENT_AFTER_SAVE_ELEMENT`),
 * and the row goes to the recorder, which holds it until the save's transaction commits.
 *
 * Never recorded (BR-6): drafts, provisional drafts and revisions — or a nested element owned by
 * one — and the copies Craft saves while propagating a save to other sites. The canonical save made
 * by applying a draft is recorded: it is the publish. Applying an unpublished draft is a first save
 * of an existing element, so it is recorded as `created` (BR-11).
 *
 * A nested element's row carries its owner (BR-12); it shares the owner's request id because the
 * recorder gives every row of a request the same one.
 *
 * **Batches** (BR-13, BR-14, BR-15). An element-index action on more than one element and a run of a
 * batched element job are batches from the start: no element in them is snapshotted or diffed, and
 * one `bulk` row listing them is recorded when the operation ends. Every other outermost Craft bulk
 * operation is a candidate — Craft opens one around every single save — whose rows are recorded as
 * usual until one of its saves is a resave, or it touches more than `bulkThreshold` top-level
 * elements; then those rows are dropped and the batch row stands for them. A nested element counts
 * as its owner and is listed through it, however many of the owner's it saves. A job Craft splits over several queue runs is one
 * batch row per run. With `recordBulkDiffs` on there are no batches at all.
 *
 * Every listener is class-level, so the plugin creates nothing until an element event happens, and
 * every one swallows its own failures (BR-2).
 *
 * @since 1.0.0
 */
class ElementWatcher
{
    public const EVENT_CREATED = 'created';
    public const EVENT_SAVED = 'saved';
    public const EVENT_DELETED = 'deleted';
    public const EVENT_RESTORED = 'restored';
    public const EVENT_MOVED = 'moved';
    public const EVENT_BULK = 'bulk';

    /**
     * How many owners up an untitled nested element's label climbs: a block in a block in an
     * entry reads in full; anything deeper is named by Craft's own words.
     */
    private const LABEL_DEPTH = 3;

    /**
     * The operation a candidate batch is named after: what its first element went through.
     */
    private const OPERATIONS = [
        self::EVENT_CREATED => 'create',
        self::EVENT_SAVED => 'save',
        self::EVENT_DELETED => 'delete',
        self::EVENT_RESTORED => 'restore',
        self::EVENT_MOVED => 'move',
    ];

    /**
     * Snapshots of saves in progress, innermost last: an element can be saved again while its own
     * save is running.
     *
     * @var WeakMap<ElementInterface, list<Snapshot|null>>
     */
    private WeakMap $snapshots;

    /**
     * Open batches, innermost last. Only the innermost collects; a job run inside another operation
     * is the one batch that opens inside another.
     *
     * @var list<Batch>
     */
    private array $batches = [];

    /**
     * Tests another watcher adds for elements the log never holds, such as a shop's carts (BR-23).
     *
     * @var list<Closure(ElementInterface): bool>
     */
    private array $ignores = [];

    public function __construct(private readonly Auditor $plugin)
    {
        $this->snapshots = new WeakMap();
    }

    /**
     * Adds a test for elements whose events are never recorded. It is asked about every element
     * event before anything is read, so it must cost no query.
     *
     * @param Closure(ElementInterface): bool $test
     */
    public function ignore(Closure $test): void
    {
        $this->ignores[] = $test;
    }

    public function attach(): void
    {
        YiiEvent::on(Elements::class, Elements::EVENT_BEFORE_BULK_OP, [$this, 'beforeBulkOp']);
        YiiEvent::on(Elements::class, Elements::EVENT_AFTER_BULK_OP, [$this, 'afterBulkOp']);
        YiiEvent::on(Elements::class, Elements::EVENT_BEFORE_PERFORM_ACTION, [$this, 'beforeAction']);
        YiiEvent::on(Elements::class, Elements::EVENT_AFTER_PERFORM_ACTION, [$this, 'afterAction']);
        YiiEvent::on(Elements::class, Elements::EVENT_BEFORE_SAVE_ELEMENT, [$this, 'beforeSave']);
        YiiEvent::on(Elements::class, Elements::EVENT_AFTER_SAVE_ELEMENT, [$this, 'afterSave']);
        YiiEvent::on(Elements::class, Elements::EVENT_AFTER_DELETE_ELEMENT, [$this, 'afterDelete']);
        YiiEvent::on(Elements::class, Elements::EVENT_AFTER_RESTORE_ELEMENT, [$this, 'afterRestore']);
        // Craft 5.9 renamed the move event; before it, `afterMoveElement` fired for a move and
        // `afterInsertElement` for a first placement. Only one of the two fires on any version.
        YiiEvent::on(Structures::class, 'afterUpdateElement', [$this, 'afterMove']);
        YiiEvent::on(Structures::class, 'afterMoveElement', [$this, 'afterMove']);
    }

    /**
     * An outermost Craft bulk operation opens a candidate batch. A nested one — Craft begins one
     * inside another only when asked outright — belongs to the operation already open.
     */
    public function beforeBulkOp(BulkOpEvent $event): void
    {
        try {
            if ($this->batches === [] && $this->batching()) {
                $this->batches[] = new Batch($event->key);
            }
        } catch (Throwable $e) {
            $this->fail('Could not follow a bulk operation', $e);
        }
    }

    public function afterBulkOp(BulkOpEvent $event): void
    {
        $this->close($event->key);
    }

    /**
     * An element-index action on more than one element is a batch. On one it is recorded as that
     * element's own event, which says more than "a batch of one".
     */
    public function beforeAction(ElementActionEvent $event): void
    {
        try {
            if ($this->batches === [] && $this->batching() && count((array)$event->criteria->id) > 1) {
                $this->batches[] = new Batch(Batch::ACTION, $event->action::class, true);
            }
        } catch (Throwable $e) {
            $this->fail('Could not follow an element action', $e);
        }
    }

    public function afterAction(ElementActionEvent $event): void
    {
        $this->close(Batch::ACTION);
    }

    /**
     * Called as a queue job starts, after the recorder's own: a run of a batched element job is a batch.
     */
    public function beginJob(object $job): void
    {
        try {
            if ($job instanceof BaseBatchedElementJob && $this->batching()) {
                $this->batches[] = new Batch(Batch::JOB, $job::class, true);
            }
        } catch (Throwable $e) {
            $this->fail('Could not follow a queue job', $e);
        }
    }

    /**
     * Called as a queue job ends, before the recorder's own, so the batch row is the job's.
     */
    public function endJob(): void
    {
        $this->close(Batch::JOB);
    }

    /**
     * Ends whatever is still open — an action whose after event never came — as the request ends.
     */
    public function closeAll(): void
    {
        while ($this->batches !== []) {
            $this->close($this->batches[array_key_last($this->batches)]->key);
        }
    }

    public function beforeSave(ElementEvent $event): void
    {
        try {
            $element = $event->element;
            if ($this->skips($element)) {
                return;
            }

            // A first save has no stored state worth reading: it is recorded as created. Nor has a
            // save inside a batch, which is never diffed (B3).
            $batch = $this->batch();
            $batched = $batch !== null && ($batch->definite || $element->resaving);
            $snapshot = $batched || $this->isCreation($event) ? null : $this->plugin->getDiffer()->snapshot($element);
            $stack = $this->snapshots[$element] ?? [];
            $stack[] = $snapshot;
            $this->snapshots[$element] = $stack;
        } catch (Throwable $e) {
            $this->fail('Could not snapshot an element before its save', $e);
        }
    }

    public function afterSave(ElementEvent $event): void
    {
        try {
            $element = $event->element;
            $snapshot = $this->popSnapshot($element);
            if ($this->skips($element)) {
                return;
            }

            $creation = $this->isCreation($event);
            if ($this->absorbs($element, $creation ? self::EVENT_CREATED : self::EVENT_SAVED)) {
                return;
            }

            $differ = $this->plugin->getDiffer();

            if ($creation) {
                $this->record($element, self::EVENT_CREATED, $differ->created($element) ?: null);
                return;
            }

            // No snapshot means the before-save listener failed or never saw this save.
            if ($snapshot === null) {
                return;
            }

            $changes = $differ->diff($element, $snapshot);
            if ($changes === [] && !$this->plugin->getSettings()->recordNoChangeSaves) {
                return;
            }

            $this->record($element, self::EVENT_SAVED, $changes ?: null);
        } catch (Throwable $e) {
            $this->fail('Could not record an element save', $e);
        }
    }

    public function afterDelete(ElementEvent $event): void
    {
        try {
            $element = $event->element;
            if ($this->skips($element) || $this->absorbs($element, self::EVENT_DELETED)) {
                return;
            }

            $this->record($element, self::EVENT_DELETED, $element->hardDelete ? ['hardDelete' => true] : null);
        } catch (Throwable $e) {
            $this->fail('Could not record an element deletion', $e);
        }
    }

    public function afterRestore(ElementEvent $event): void
    {
        try {
            $element = $event->element;
            if (!$this->skips($element) && !$this->absorbs($element, self::EVENT_RESTORED)) {
                $this->record($element, self::EVENT_RESTORED);
            }
        } catch (Throwable $e) {
            $this->fail('Could not record an element restore', $e);
        }
    }

    public function afterMove(MoveElementEvent $event): void
    {
        try {
            $element = $event->element;
            // Placing a new element is part of its creation, not a move.
            if ($element->firstSave || $this->skips($element) || $this->absorbs($element, self::EVENT_MOVED)) {
                return;
            }

            // The target's label is kept for the same reason the subject's is: it may be deleted.
            $target = $event->targetElementId ? $event->getTargetElement() : null;

            $this->record($element, self::EVENT_MOVED, [
                'structureId' => $event->structureId,
                'action' => $event->action,
                'targetId' => $event->targetElementId,
                'targetLabel' => $target ? $this->label($target) : null,
            ]);
        } catch (Throwable $e) {
            $this->fail('Could not record an element move', $e);
        }
    }

    /**
     * Whether the element's event is one the log never holds (BR-5, BR-6), cheapest test first.
     */
    private function skips(ElementInterface $element): bool
    {
        $settings = $this->plugin->getSettings();
        if (!$settings->enabled || $element->propagating) {
            return true;
        }

        foreach ($settings->excludeElementTypes as $type) {
            if ($element instanceof $type) {
                return true;
            }
        }

        // Follows a nested element's owners, so a block inside a draft is a draft's.
        if (ElementHelper::isDraftOrRevision($element)) {
            return true;
        }

        foreach ($this->ignores as $test) {
            if ($test($element)) {
                return true;
            }
        }

        return !$this->plugin->getRecorder()->isRecording();
    }

    /**
     * Whether an operation may become a batch: not when recording is off, nor when batches are
     * recorded element by element (BR-14).
     */
    private function batching(): bool
    {
        $settings = $this->plugin->getSettings();

        return $settings->enabled && !$settings->recordBulkDiffs;
    }

    /**
     * The batch collecting now, if any.
     */
    private function batch(): ?Batch
    {
        return $this->batches === [] ? null : $this->batches[array_key_last($this->batches)];
    }

    /**
     * Hands an element's event to the open batch, and says whether the batch stands for it — in
     * which case the element records no row of its own.
     */
    private function absorbs(ElementInterface $element, string $event): bool
    {
        $batch = $this->batch();
        if ($batch === null) {
            return false;
        }

        if ($element->resaving) {
            $this->settle($batch, Batch::OPERATION_RESAVE);
        }

        // A nested element counts toward its owner, not the total (BR-13): it covers the owner, so a
        // resave of one field's nested entries still lists what they belong to.
        $ownerId = $element instanceof NestedElementInterface ? $element->getOwnerId() : null;
        if (
            $batch->cover($ownerId ?? (int)$element->id, (int)$element->siteId, $element::class, self::OPERATIONS[$event]) &&
            $batch->count() > $this->plugin->getSettings()->bulkThreshold
        ) {
            $this->settle($batch);
        }

        return $batch->definite;
    }

    /**
     * Makes a candidate a batch: the rows already recorded for it are dropped (BR-14).
     */
    private function settle(Batch $batch, ?string $operation = null): void
    {
        if ($batch->definite) {
            return;
        }

        $batch->definite = true;
        $batch->operation = $operation ?? $batch->operation;
        $this->plugin->getRecorder()->discard($batch->id);
    }

    /**
     * Ends the innermost batch if it is the one named, recording its row when it is a batch that
     * covered anything.
     */
    private function close(string $key): void
    {
        try {
            $batch = $this->batch();
            if ($batch === null || $batch->key !== $key) {
                return;
            }

            array_pop($this->batches);
            if (!$batch->definite || $batch->count() === 0) {
                return;
            }

            $this->plugin->getRecorder()->record(new Event([
                'family' => Event::FAMILY_ELEMENT,
                'event' => self::EVENT_BULK,
                'data' => $batch->data(),
                'elements' => $batch->elements(),
            ]));
        } catch (Throwable $e) {
            $this->fail('Could not record a batch', $e);
        }
    }

    private function isCreation(ElementEvent $event): bool
    {
        return $event->isNew || $event->element->firstSave;
    }

    private function popSnapshot(ElementInterface $element): ?Snapshot
    {
        if (!isset($this->snapshots[$element])) {
            return null;
        }

        $stack = $this->snapshots[$element];
        $snapshot = array_pop($stack);
        if ($stack === []) {
            unset($this->snapshots[$element]);
        } else {
            $this->snapshots[$element] = $stack;
        }

        return $snapshot;
    }

    /**
     * @param array<string, mixed>|null $data
     */
    private function record(ElementInterface $element, string $event, ?array $data = null): void
    {
        $this->plugin->getRecorder()->record(new Event([
            'family' => Event::FAMILY_ELEMENT,
            'event' => $event,
            'elementId' => $element->id,
            'elementType' => $element::class,
            'siteId' => $element->siteId,
            'elementLabel' => $this->label($element),
            'ownerId' => $element instanceof NestedElementInterface ? $element->getOwnerId() : null,
            'data' => $data,
            // Only a candidate is left open here: a batch would have absorbed the event.
            'batch' => $this->batch()?->id,
        ]));
    }

    private function label(ElementInterface $element): string
    {
        return self::labelFor($element);
    }

    /**
     * What a row calls an element: its title, or for a nested element without one — a Matrix
     * block, an address — its type and its owner's label, "Text block in The spring collection"
     * (BR-12). Craft's own words for an untitled element are its class and id ("Entry 856").
     *
     * Asking for the owner reads it when it is not loaded yet; a nested save made through its owner
     * already has it. Stored in English, like "System", with the type's name as the site has it.
     */
    public static function labelFor(ElementInterface $element, int $depth = 0): string
    {
        $title = $element->title ?? null;
        if (is_string($title) && trim($title) !== '') {
            return trim($title);
        }

        if ($element instanceof NestedElementInterface && $depth < self::LABEL_DEPTH) {
            try {
                $owner = $element->getOwner();
            } catch (Throwable) {
                $owner = null;
            }
            if ($owner !== null) {
                return self::nestedLabel(self::typeName($element), self::labelFor($owner, $depth + 1));
            }
        }

        $label = trim((string)$element);

        return $label !== '' ? $label : sprintf('%s #%s', $element::displayName(), $element->id);
    }

    /**
     * "<type> in <owner>", in English: the label is stored, and read by anyone.
     */
    public static function nestedLabel(string $type, string $owner): string
    {
        return Craft::t('auditor', '{type} in {owner}', ['type' => $type, 'owner' => $owner], 'en');
    }

    /**
     * An entry's type by its name; any other element by its type's.
     */
    private static function typeName(ElementInterface $element): string
    {
        if ($element instanceof Entry) {
            try {
                return $element->getType()->name;
            } catch (Throwable) {
                // A type deleted since: the element type's name below.
            }
        }

        return $element::displayName();
    }

    private function fail(string $message, Throwable $e): void
    {
        try {
            Craft::error(sprintf('%s: %s', $message, $e->getMessage()), Auditor::HANDLE);
        } catch (Throwable) {
            // Nowhere left to report it, and BR-2 forbids throwing.
        }
    }
}
