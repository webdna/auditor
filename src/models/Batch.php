<?php

namespace webdna\auditor\models;

use craft\helpers\StringHelper;

/**
 * An operation that may be recorded as one batch row rather than a row per element (BR-13).
 *
 * A batch is **definite** from the start when the watcher knows what it is — an element-index
 * action on several elements, or a run of a batched element job. A Craft bulk operation starts as a
 * **candidate**: it becomes definite when one of its saves is a resave, or when it touches more than
 * the threshold's top-level elements. Until then its rows are recorded as usual, tagged with
 * {@see $id}, so the recorder can drop them if it turns into a batch (BR-14).
 *
 * @internal
 * @since 1.0.0
 */
class Batch
{
    public const OPERATION_RESAVE = 'resave';

    /**
     * {@see $key} for an element-index action, and for a run of a batched element job. Craft's own
     * bulk operation keys are alphanumeric, so neither can collide with one.
     */
    public const ACTION = '@action';
    public const JOB = '@job';

    /**
     * Tags the rows recorded while it is a candidate.
     */
    public readonly string $id;

    /**
     * The top-level elements it covered, keyed `elementId:siteId`.
     *
     * @var array<string, array{int, int}>
     */
    private array $elements = [];

    /**
     * Distinct top-level element ids: what the count counts, across sites.
     *
     * @var array<int, true>
     */
    private array $ids = [];

    /**
     * Each owner id a nested element named, to the top-level element it belongs to, so a batch
     * walks each owner's chain once.
     *
     * @var array<int, int>
     */
    public array $roots = [];

    private ?string $elementType = null;

    private bool $mixed = false;

    /**
     * @param string $key What opened it: a Craft bulk operation's key, {@see ACTION} or {@see JOB}.
     * @param string|null $operation What it is, when that is known from the start; otherwise set by
     * the first element it covers.
     */
    public function __construct(
        public readonly string $key,
        public ?string $operation = null,
        public bool $definite = false,
    ) {
        $this->id = StringHelper::randomString(12);
    }

    /**
     * Adds a top-level element — for a nested one, its owner — and says whether it was one not
     * already counted.
     */
    public function cover(int $elementId, int $siteId, string $elementType, string $operation): bool
    {
        $this->operation ??= $operation;
        $this->elements["$elementId:$siteId"] = [$elementId, $siteId];

        if ($this->elementType === null) {
            $this->elementType = $elementType;
        } elseif ($this->elementType !== $elementType) {
            $this->mixed = true;
        }

        if (isset($this->ids[$elementId])) {
            return false;
        }

        $this->ids[$elementId] = true;

        return true;
    }

    public function count(): int
    {
        return count($this->ids);
    }

    /**
     * @return list<array{int, int}> `[elementId, siteId]` pairs
     */
    public function elements(): array
    {
        return array_values($this->elements);
    }

    /**
     * The batch row's `data` (§4): what ran, over how many, of which type (null when mixed).
     *
     * @return array{operation: string|null, count: int, elementType: string|null}
     */
    public function data(): array
    {
        return [
            'operation' => $this->operation,
            'count' => $this->count(),
            'elementType' => $this->mixed ? null : $this->elementType,
        ];
    }
}
