<?php

namespace webdna\auditor\records;

use craft\db\ActiveRecord;
use webdna\auditor\db\Table;

/**
 * A row of `auditor_events`, for reading.
 *
 * Rows are written only by the recorder's batch insert (BR-3) and are never updated through this
 * record (BR-1).
 *
 * @property int $id
 * @property string $family
 * @property string $event
 * @property int|null $elementId
 * @property string|null $elementType
 * @property int|null $siteId
 * @property string|null $elementLabel
 * @property int|null $ownerId
 * @property int|null $actorId
 * @property string $actorLabel
 * @property int|null $impersonatorId
 * @property string $context
 * @property string|null $contextDetail
 * @property string|null $ip
 * @property string|null $userAgent
 * @property string $requestId
 * @property array<string, mixed>|string|null $data
 * @property string $dateCreated
 * @property string $uid
 *
 * @since 1.0.0
 */
class EventRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::EVENTS;
    }
}
