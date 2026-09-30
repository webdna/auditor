<?php

namespace webdna\auditor\events;

use craft\base\ElementInterface;
use yii\base\Event;

/**
 * Raised by the differ so that any code can add keys of its own to an element's row (BR-24) —
 * state that is neither a field nor a tracked attribute, such as an order's line items.
 *
 * It is raised twice for a save, and only while someone listens:
 *
 * 1. **Before the save**, with {@see $before} null. A handler reads the element's stored state for
 *    each key it owns into {@see $values}. Read it from the database, not by loading the element
 *    (BR-8): the save has not written anything yet, and it is the stored state that is "before".
 * 2. **After the save**, with {@see $before} holding what the first call collected — or an empty
 *    array for a new element, which has no before. A handler puts each key's state now into
 *    {@see $values}, and the differ compares the two as it compares a field: a key whose values
 *    differ is recorded as `{label, old, new}`, under the key as its label. A handler that words
 *    the change itself sets {@see $changes} for the key instead, which the differ records as given;
 *    an empty array there means "nothing changed". Either shape is redacted by the `redact` setting
 *    like any other value (BR-10).
 *
 * A change set in {@see $changes} may be `{label, old, new}`, or a list of items in the shape the
 * log's detail screen draws as a table:
 *
 * ```php
 * [
 *     'label' => 'Line items',
 *     'fields' => ['qty' => 'Quantity', …],       // the item fields' labels, in English
 *     'added' => [['id' => 1, 'label' => '…', 'values' => ['qty' => 1, …]], …],
 *     'removed' => [/* as added *\/],
 *     'changed' => [['id' => 2, 'label' => '…', 'changes' => ['qty' => ['old' => 1, 'new' => 3]]], …],
 * ]
 * ```
 *
 * The after call runs inside the save's transaction, while Craft's after-save event is being
 * handled, so a read there sees what the save wrote.
 *
 * @since 1.0.0
 */
class DefineElementSnapshotEvent extends Event
{
    public ElementInterface $element;

    /**
     * Null before the save; after it, what the handlers put in {@see $values} before it (an empty
     * array for a new element).
     *
     * @var array<string, mixed>|null
     */
    public ?array $before = null;

    /**
     * Each key's state at the moment the event is raised.
     *
     * @var array<string, mixed>
     */
    public array $values = [];

    /**
     * After the save only: a key's change, worded by its handler.
     *
     * @var array<string, array<string, mixed>>
     */
    public array $changes = [];
}
