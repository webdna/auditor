<?php

namespace webdna\auditor\events;

use craft\events\CancelableEvent;
use webdna\auditor\models\Event;

/**
 * Raised for each row before it is buffered (`Recorder::EVENT_BEFORE_RECORD`). A handler may change
 * the row, or set `isValid` to false to skip it.
 *
 * @since 1.0.0
 */
class RecordEvent extends CancelableEvent
{
    public Event $row;
}
