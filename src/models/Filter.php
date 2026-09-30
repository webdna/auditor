<?php

namespace webdna\auditor\models;

use craft\base\Model;
use craft\helpers\DateTimeHelper;
use DateTime;
use webdna\auditor\services\Events;

/**
 * What the log screen is narrowed to (BR-29): family, event, element type, actor, context, site, a
 * date range, a search over the subject and actor labels, and one request's rows.
 *
 * Built from query parameters, so an unknown family, event or context is dropped rather than
 * searched for.
 *
 * @since 1.0.0
 */
class Filter extends Model
{
    public ?string $family = null;
    public ?string $event = null;
    public ?string $elementType = null;
    public ?int $actorId = null;
    public ?string $context = null;
    public ?int $siteId = null;

    /**
     * The first day shown, from midnight in the viewer's time zone.
     */
    public ?DateTime $from = null;

    /**
     * The last day shown, whole.
     */
    public ?DateTime $to = null;

    /**
     * Matched against the subject and actor labels only, never against logged values.
     */
    public ?string $search = null;

    /**
     * One request's rows — the slideout's "everything recorded in this request" link.
     */
    public ?string $request = null;

    /**
     * @param array<string, mixed> $params
     */
    public static function fromParams(array $params): self
    {
        $filter = new self();
        $filter->family = self::oneOf($params['family'] ?? null, array_keys(Events::EVENTS));
        $filter->event = self::oneOf($params['event'] ?? null, array_merge(...array_values(Events::EVENTS)));
        $filter->context = self::oneOf($params['context'] ?? null, array_keys(Events::CONTEXTS));
        $filter->elementType = self::text($params['elementType'] ?? null);
        $filter->search = self::text($params['search'] ?? null);
        $filter->request = self::text($params['request'] ?? null);
        $filter->siteId = self::id($params['siteId'] ?? null);

        // An element select posts a list, even of one.
        $actor = $params['actor'] ?? null;
        $filter->actorId = self::id(is_array($actor) ? reset($actor) : $actor);

        $filter->from = self::date($params['from'] ?? null);
        $filter->to = self::date($params['to'] ?? null);

        return $filter;
    }

    /**
     * The filter as query parameters, as {@see fromParams()} reads them back — for the next page.
     *
     * @return array<string, string|int>
     */
    public function toParams(): array
    {
        return array_filter([
            'family' => $this->family,
            'event' => $this->event,
            'elementType' => $this->elementType,
            'actor' => $this->actorId,
            'context' => $this->context,
            'siteId' => $this->siteId,
            'from' => $this->from?->format(DATE_ATOM),
            'to' => $this->to?->format(DATE_ATOM),
            'search' => $this->search,
            'request' => $this->request,
        ], fn($value) => $value !== null);
    }

    public function isEmpty(): bool
    {
        return $this->toParams() === [];
    }

    /**
     * @param string[] $allowed
     */
    private static function oneOf(mixed $value, array $allowed): ?string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : null;
    }

    private static function text(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private static function id(mixed $value): ?int
    {
        return is_numeric($value) && (int)$value > 0 ? (int)$value : null;
    }

    private static function date(mixed $value): ?DateTime
    {
        // A date field posts `{date, timezone}` with an empty date when left blank.
        if ($value === null || $value === '' || (is_array($value) && ($value['date'] ?? '') === '')) {
            return null;
        }

        return DateTimeHelper::toDateTime($value) ?: null;
    }
}
