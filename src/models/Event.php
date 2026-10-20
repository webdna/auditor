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
     * The actor label when nobody is signed in — a command, a job, a control-panel request.
     */
    public const SYSTEM = 'System';

    /**
     * The actor label of a site request with nobody signed in, such as a form a visitor posts
     * (§4). Stored in English, like {@see SYSTEM}; rows recorded before it read "System".
     *
     * @since 1.1.0
     */
    public const VISITOR = 'Visitor';

    /**
     * The actor label of a failed sign-in with a username nobody has: someone tried, and who is
     * not known (BR-17).
     */
    public const UNKNOWN_USER = 'Unknown user';

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

    /**
     * Not a column. The candidate batch it was recorded in, so it can be dropped if that operation
     * turns out to be a batch (BR-14).
     */
    public ?string $batch = null;

    /**
     * Not a column. For a batch row, the `[elementId, siteId]` pairs written to the side table in the
     * same flush (BR-15).
     *
     * @var list<array{int, int}>
     */
    public array $elements = [];
}
