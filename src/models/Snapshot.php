<?php

namespace webdna\auditor\models;

/**
 * An element's stored state just before a save: the candidates for its diff and what the database
 * held for each (BR-7, BR-8).
 *
 * It carries its own candidate list because Craft wipes the element's dirty record straight after
 * the save (`markAsClean()`), so the diff cannot ask the element again.
 *
 * @since 1.0.0
 */
class Snapshot
{
    /**
     * @param string[] $fields Handles of the candidate fields
     * @param array<string, mixed> $storedFields Stored value per candidate field; absent when nothing was stored
     * @param array<string, mixed> $storedAttributes Stored value per tracked attribute
     */
    public function __construct(
        public readonly int $elementId,
        public readonly int $siteId,
        public readonly array $fields,
        public readonly array $storedFields,
        public readonly array $storedAttributes,
    ) {
    }
}
