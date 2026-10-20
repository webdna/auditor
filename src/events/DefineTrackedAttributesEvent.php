<?php

namespace webdna\auditor\events;

use Closure;
use craft\base\ElementInterface;
use webdna\auditor\services\Differ;
use yii\base\Event;

/**
 * Raised once per element type, the first time the differ needs that type's tracked attributes
 * (BR-9). A handler adds to or removes from {@see $attributes}.
 *
 * Each attribute maps to where its **stored** value is read from, because the before-state comes
 * from the database and never from loading the element (BR-8):
 *
 * - `'table.column'` — a column read in the differ's one query. `elements` and `elements_sites` are
 *   the element's own rows; any other table (unprefixed name) is joined on its `id` being the
 *   element id, as `entries`, `users` and `assets` are.
 * - a `Closure(ElementInterface $element, Differ $differ): mixed` that returns the stored value
 *   itself, for a value kept any other way (a join table, a per-site row). It runs one query of its
 *   own — unless it reads through {@see Differ::readStored()}, which reads the value once for all
 *   the nested elements an owner saves in its own save (BR-35).
 *
 * The **new** value is always read as `$element->{$name}`.
 *
 * @since 1.0.0
 */
class DefineTrackedAttributesEvent extends Event
{
    /**
     * @var class-string<ElementInterface>
     */
    public string $elementType;

    /**
     * @var array<string, string|Closure>
     */
    public array $attributes = [];
}
