<?php

namespace webdna\auditor\models;

use craft\base\Model;
use DateTime;

/**
 * One row of the log: one thing that happened, to one thing, by one person, at one time.
 *
 * A watcher fills in what happened and to what; the recorder fills in who, where from and when
 * for any of those it leaves unset (BR-5).
 *
 * @since 1.0.0
 */
class Event extends Model
{
    public const FAMILY_ELEMENT = 'element';
    public const FAMILY_AUTH = 'auth';
    public const FAMILY_ACCESS = 'access';

    /**
     * The actor label when nobody is signed in — a command or a job.
     */
    public const SYSTEM = 'System';

    /**
     * The table's columns, in the order the recorder writes them. `id` is the database's.
     */
    public const COLUMNS = [
        'family', 'event', 'elementId', 'elementType', 'siteId', 'elementLabel', 'ownerId',
        'actorId', 'actorLabel', 'impersonatorId', 'context', 'contextDetail', 'ip', 'userAgent',
        'requestId', 'data', 'dateCreated', 'uid',
    ];

    public ?int $id = null;
    public string $family = self::FAMILY_ELEMENT;
    public string $event = '';

    /**
     * The subject. For auth and access rows, the user the event is about.
     */
    public ?int $elementId = null;
    public ?string $elementType = null;
    public ?int $siteId = null;
    public ?string $elementLabel = null;
    public ?int $ownerId = null;

    /**
     * Left null, the recorder sets the signed-in user, or "System".
     */
    public ?int $actorId = null;
    public ?string $actorLabel = null;
    public ?int $impersonatorId = null;

    /**
     * Left null, the recorder sets where the request came from.
     */
    public ?string $context = null;
    public ?string $contextDetail = null;
    public ?string $ip = null;
    public ?string $userAgent = null;
    public ?string $requestId = null;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = null;

    public ?DateTime $dateCreated = null;
    public ?string $uid = null;
}
