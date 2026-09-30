<?php

namespace webdna\auditor\db;

/**
 * The plugin's table names.
 *
 * @since 1.0.0
 */
abstract class Table
{
    /**
     * One row per event.
     */
    public const EVENTS = '{{%auditor_events}}';

    /**
     * The elements a batch row covered.
     */
    public const EVENT_ELEMENTS = '{{%auditor_event_elements}}';
}
