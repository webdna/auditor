<?php

namespace webdna\auditor\watchers;

use Craft;
use craft\base\ElementInterface;
use craft\base\NestedElementInterface;
use craft\events\ElementEvent;
use craft\events\MoveElementEvent;
use craft\helpers\ElementHelper;
use craft\services\Elements;
use craft\services\Structures;
use Throwable;
use WeakMap;
use webdna\auditor\Auditor;
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

    /**
     * Snapshots of saves in progress, innermost last: an element can be saved again while its own
     * save is running.
     *
     * @var WeakMap<ElementInterface, list<Snapshot|null>>
     */
    private WeakMap $snapshots;

    public function __construct(private readonly Auditor $plugin)
    {
        $this->snapshots = new WeakMap();
    }

    public function attach(): void
    {
        YiiEvent::on(Elements::class, Elements::EVENT_BEFORE_SAVE_ELEMENT, [$this, 'beforeSave']);
        YiiEvent::on(Elements::class, Elements::EVENT_AFTER_SAVE_ELEMENT, [$this, 'afterSave']);
        YiiEvent::on(Elements::class, Elements::EVENT_AFTER_DELETE_ELEMENT, [$this, 'afterDelete']);
        YiiEvent::on(Elements::class, Elements::EVENT_AFTER_RESTORE_ELEMENT, [$this, 'afterRestore']);
        // Craft 5.9 renamed the move event; before it, `afterMoveElement` fired for a move and
        // `afterInsertElement` for a first placement. Only one of the two fires on any version.
        YiiEvent::on(Structures::class, 'afterUpdateElement', [$this, 'afterMove']);
        YiiEvent::on(Structures::class, 'afterMoveElement', [$this, 'afterMove']);
    }

    public function beforeSave(ElementEvent $event): void
    {
        try {
            $element = $event->element;
            if ($this->skips($element)) {
                return;
            }

            // A first save has no stored state worth reading: it is recorded as created.
            $snapshot = $this->isCreation($event) ? null : $this->plugin->getDiffer()->snapshot($element);
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

            $differ = $this->plugin->getDiffer();

            if ($this->isCreation($event)) {
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
            if ($this->skips($element)) {
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
            if (!$this->skips($event->element)) {
                $this->record($event->element, self::EVENT_RESTORED);
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
            if ($element->firstSave || $this->skips($element)) {
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

        return !$this->plugin->getRecorder()->isRecording();
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
        ]));
    }

    private function label(ElementInterface $element): string
    {
        $label = trim((string)$element);

        return $label !== '' ? $label : sprintf('%s #%s', $element::displayName(), $element->id);
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
