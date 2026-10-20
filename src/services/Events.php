<?php

namespace webdna\auditor\services;

use Craft;
use craft\base\ElementInterface;
use craft\base\NestedElementInterface;
use craft\db\Query;
use craft\db\Table as CraftTable;
use craft\elements\User;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use craft\models\UserGroup;
use craft\web\View;
use DateInterval;
use DateTimeZone;
use webdna\auditor\Auditor;
use webdna\auditor\db\Table;
use webdna\auditor\models\Event;
use webdna\auditor\models\Filter;
use webdna\auditor\watchers\AuthWatcher;
use webdna\auditor\watchers\CommerceWatcher;
use webdna\auditor\watchers\ElementWatcher;
use webdna\auditor\watchers\PromotionsWatcher;
use yii\base\Component;
use yii\helpers\Inflector;

/**
 * Reads the log back: a page of rows at a time, one row, and the words each row is shown in.
 *
 * Nothing here writes. Values reach the templates as plain strings, and the templates escape them
 * (BR-30).
 *
 * @since 1.0.0
 */
class Events extends Component
{
    /**
     * Rows per page of the log.
     */
    public const PAGE_SIZE = 50;

    /**
     * A user's Activity's two tabs (BR-32): the changes they made, and their account's events.
     */
    public const ACTIVITY_CHANGES = 'changes';
    public const ACTIVITY_ACCOUNT = 'account';

    /**
     * Every event each family writes, in the order the filter lists them.
     */
    public const EVENTS = [
        Event::FAMILY_ELEMENT => [
            ElementWatcher::EVENT_CREATED,
            ElementWatcher::EVENT_SAVED,
            ElementWatcher::EVENT_DELETED,
            ElementWatcher::EVENT_RESTORED,
            ElementWatcher::EVENT_MOVED,
            ElementWatcher::EVENT_BULK,
        ],
        Event::FAMILY_AUTH => [
            AuthWatcher::EVENT_LOGIN,
            AuthWatcher::EVENT_LOGIN_FAILED,
            AuthWatcher::EVENT_LOGOUT,
            AuthWatcher::EVENT_IMPERSONATED,
            AuthWatcher::EVENT_LOCKED,
            AuthWatcher::EVENT_UNLOCKED,
            AuthWatcher::EVENT_SUSPENDED,
            AuthWatcher::EVENT_UNSUSPENDED,
            AuthWatcher::EVENT_ACTIVATED,
            AuthWatcher::EVENT_DEACTIVATED,
            AuthWatcher::EVENT_EMAIL_CHANGED,
            AuthWatcher::EVENT_EMAIL_VERIFIED,
            AuthWatcher::EVENT_PASSWORD_CHANGED,
            AuthWatcher::EVENT_PASSWORD_RESET_REQUIRED,
            AuthWatcher::EVENT_MFA_ENABLED,
            AuthWatcher::EVENT_MFA_DISABLED,
            AuthWatcher::EVENT_PASSKEY_ADDED,
            AuthWatcher::EVENT_PASSKEY_REMOVED,
        ],
        Event::FAMILY_ACCESS => [
            AuthWatcher::EVENT_ADMIN_CHANGED,
            AuthWatcher::EVENT_GROUPS_CHANGED,
            AuthWatcher::EVENT_PERMISSIONS_CHANGED,
            AuthWatcher::EVENT_GROUP_SAVED,
            AuthWatcher::EVENT_GROUP_DELETED,
        ],
    ];

    /**
     * Each context, as the log names it.
     */
    public const CONTEXTS = [
        'cp' => 'Control panel',
        'site' => 'Site',
        'console' => 'Command',
        'queue' => 'Background job',
    ];

    private const FAMILY_LABELS = [
        Event::FAMILY_ELEMENT => 'Content',
        Event::FAMILY_AUTH => 'Sign-in',
        Event::FAMILY_ACCESS => 'Access',
    ];

    private const EVENT_LABELS = [
        ElementWatcher::EVENT_CREATED => 'Created',
        ElementWatcher::EVENT_SAVED => 'Saved',
        ElementWatcher::EVENT_DELETED => 'Deleted',
        ElementWatcher::EVENT_RESTORED => 'Restored',
        ElementWatcher::EVENT_MOVED => 'Moved',
        ElementWatcher::EVENT_BULK => 'Batch',
        AuthWatcher::EVENT_LOGIN => 'Signed in',
        AuthWatcher::EVENT_LOGIN_FAILED => 'Failed sign-in',
        AuthWatcher::EVENT_LOGOUT => 'Signed out',
        AuthWatcher::EVENT_IMPERSONATED => 'Signed in as user',
        AuthWatcher::EVENT_LOCKED => 'Locked',
        AuthWatcher::EVENT_UNLOCKED => 'Unlocked',
        AuthWatcher::EVENT_SUSPENDED => 'Suspended',
        AuthWatcher::EVENT_UNSUSPENDED => 'Unsuspended',
        AuthWatcher::EVENT_ACTIVATED => 'Activated',
        AuthWatcher::EVENT_DEACTIVATED => 'Deactivated',
        AuthWatcher::EVENT_EMAIL_CHANGED => 'Email changed',
        AuthWatcher::EVENT_EMAIL_VERIFIED => 'Email verified',
        AuthWatcher::EVENT_PASSWORD_CHANGED => 'Password changed',
        AuthWatcher::EVENT_PASSWORD_RESET_REQUIRED => 'Password reset required',
        AuthWatcher::EVENT_MFA_ENABLED => 'Two-step verification added',
        AuthWatcher::EVENT_MFA_DISABLED => 'Two-step verification removed',
        AuthWatcher::EVENT_PASSKEY_ADDED => 'Passkey added',
        AuthWatcher::EVENT_PASSKEY_REMOVED => 'Passkey removed',
        AuthWatcher::EVENT_ADMIN_CHANGED => 'Admin changed',
        AuthWatcher::EVENT_GROUPS_CHANGED => 'Groups changed',
        AuthWatcher::EVENT_PERMISSIONS_CHANGED => 'Permissions changed',
        AuthWatcher::EVENT_GROUP_SAVED => 'User group saved',
        AuthWatcher::EVENT_GROUP_DELETED => 'User group deleted',
    ];

    /**
     * Payload keys shown under a name of their own; any other key is its words, sentence case.
     */
    private const KEY_LABELS = [
        'action' => 'Move',
        'targetLabel' => 'Next to',
        'operation' => 'Operation',
        'count' => 'Elements',
        'elementType' => 'Element type',
        'username' => 'Username tried',
        'reason' => 'Reason',
        'isNew' => 'New group',
        'partial' => 'Partial',
        'method' => 'Method',
        'name' => 'Name',
        'group' => 'Group',
        'id' => 'ID',
    ];

    /**
     * Payload keys that only repeat what a label beside them says better.
     */
    private const HIDDEN_KEYS = ['structureId', 'targetId'];

    /**
     * Payload keys whose value names a class — an element type, a two-step method, an action or a
     * job — shown by that class's own name while it exists.
     */
    private const CLASS_KEYS = ['elementType', 'method', 'operation'];

    /**
     * A batch's operation when it is one of the element service's own (BR-14).
     */
    private const OPERATION_LABELS = [
        'resave' => 'Re-save',
        'create' => 'Create',
        'save' => 'Save',
        'delete' => 'Delete',
        'restore' => 'Restore',
        'move' => 'Move',
    ];

    /**
     * A failed sign-in's reason, Craft's `User::AUTH_*` codes (BR-17).
     */
    private const REASON_LABELS = [
        User::AUTH_INVALID_CREDENTIALS => 'Wrong password',
        User::AUTH_PENDING_VERIFICATION => 'Account not activated yet',
        User::AUTH_ACCOUNT_LOCKED => 'Account locked',
        User::AUTH_ACCOUNT_COOLDOWN => 'Account locked for now, after too many attempts',
        User::AUTH_PASSWORD_RESET_REQUIRED => 'Password reset required',
        User::AUTH_ACCOUNT_SUSPENDED => 'Account suspended',
        User::AUTH_NO_CP_ACCESS => 'No control panel access',
        User::AUTH_NO_CP_OFFLINE_ACCESS => 'Control panel offline',
        User::AUTH_NO_SITE_OFFLINE_ACCESS => 'Site offline',
    ];

    /**
     * A structure move's action, `Structures::ACTION_*`.
     */
    private const MOVE_LABELS = [
        'prepend' => 'To the start',
        'append' => 'To the end',
        'placeBefore' => 'Before',
        'placeAfter' => 'After',
    ];

    /**
     * One page of the log, newest first, and the key of the next page — null when there is none.
     *
     * Paged by key, never by offset and never counted (BR-29): the cost of a page does not grow
     * with the table.
     *
     * @return array{events: list<Event>, after: int|null}
     */
    public function page(Filter $filter, ?int $after = null, int $limit = self::PAGE_SIZE): array
    {
        return $this->keyed($this->query($filter), $after, $limit);
    }

    /**
     * One page of a user's Activity (BR-32), newest first, and the key of the next page.
     *
     * `changes` is what they did: the rows they are the actor of. `account` is what happened to
     * their account: the sign-in and access rows whose subject is them. A row is on one tab,
     * never both — their own sign-ins are account events, not changes they made.
     *
     * @param self::ACTIVITY_* $tab
     * @return array{events: list<Event>, after: int|null}
     */
    public function activity(int $userId, string $tab, ?int $after = null, int $limit = self::PAGE_SIZE): array
    {
        return $this->keyed($this->activityQuery($userId, $tab), $after, $limit);
    }

    /**
     * Whether a row is part of a user's Activity, on either tab, so its detail may be shown there.
     */
    public function inActivity(Event $event, int $userId): bool
    {
        if ($this->isAccountEvent($event, $userId)) {
            return true;
        }

        return $event->actorId === $userId;
    }

    /**
     * @param self::ACTIVITY_* $tab
     * @return Query<int, array<string, mixed>>
     */
    private function activityQuery(int $userId, string $tab): Query
    {
        $query = (new Query())->from(Table::EVENTS);

        if ($tab === self::ACTIVITY_ACCOUNT) {
            return $query->where([
                'elementId' => $userId,
                'family' => [Event::FAMILY_AUTH, Event::FAMILY_ACCESS],
            ]);
        }

        // Spelled out rather than NOT(account event): with elementId null that would be
        // NOT(NULL), and a user group they saved would drop off their changes.
        return $query->where(['actorId' => $userId])->andWhere([
            'or',
            ['family' => Event::FAMILY_ELEMENT],
            ['elementId' => null],
            ['not', ['elementId' => $userId]],
        ]);
    }

    private function isAccountEvent(Event $event, int $userId): bool
    {
        return $event->elementId === $userId && in_array($event->family, [Event::FAMILY_AUTH, Event::FAMILY_ACCESS], true);
    }

    /**
     * A query's rows a page at a time, newest first: keyed by id, never by offset and never
     * counted (BR-29).
     *
     * @param Query<int, array<string, mixed>> $query
     * @return array{events: list<Event>, after: int|null}
     */
    private function keyed(Query $query, ?int $after, int $limit): array
    {
        $query
            ->orderBy(['id' => SORT_DESC])
            ->limit($limit + 1);

        if ($after !== null) {
            $query->andWhere(['<', 'id', $after]);
        }

        $rows = $query->all();
        $more = count($rows) > $limit;
        $events = $this->hydrateAll(array_slice($rows, 0, $limit));
        $last = end($events);

        return [
            'events' => $events,
            'after' => $more && $last ? $last->id : null,
        ];
    }

    public function get(int $id): ?Event
    {
        $row = (new Query())->from(Table::EVENTS)->where(['id' => $id])->one();

        return $row ? $this->hydrateAll([$row])[0] : null;
    }

    /**
     * One page of an element's History (BR-31), newest first: the element rows whose subject or
     * owner it is, and the batches that listed it, grouped by request — each group's own rows
     * first, then its nested elements' beneath them.
     *
     * Each of the three sources is read by its own index, ids only, and the page is fetched by
     * id. A page never ends part-way through a request: the rest of the last group's request is
     * read too, so one publish stays one group.
     *
     * @return array{groups: list<list<Event>>, events: list<Event>, after: int|null}
     */
    public function history(int $elementId, ?int $after = null, int $limit = self::PAGE_SIZE): array
    {
        $ids = [];
        foreach ($this->historySources($elementId) as [$column, $query]) {
            if ($after !== null) {
                $query->andWhere(['<', $column, $after]);
            }
            $ids = [...$ids, ...array_map('intval', $query->orderBy([$column => SORT_DESC])->limit($limit + 1)->column())];
        }

        $ids = array_values(array_unique($ids));
        rsort($ids);
        $more = count($ids) > $limit;
        $ids = array_slice($ids, 0, $limit);
        if ($ids === []) {
            return ['groups' => [], 'events' => [], 'after' => null];
        }

        $rows = (new Query())->from(Table::EVENTS)->where(['id' => $ids])->orderBy(['id' => SORT_DESC])->all();
        $last = end($rows);
        $oldest = min($ids);

        if ($more && $last && $last['requestId'] !== null) {
            $rest = (new Query())
                ->from(Table::EVENTS)
                ->where(['family' => Event::FAMILY_ELEMENT, 'requestId' => $last['requestId']])
                ->andWhere(['or', ['elementId' => $elementId], ['ownerId' => $elementId]])
                ->andWhere(['<', 'id', $oldest])
                ->orderBy(['id' => SORT_DESC])
                ->all();
            if ($rest !== []) {
                $rows = [...$rows, ...$rest];
                $oldest = (int)end($rest)['id'];
            }
        }

        $events = $this->hydrateAll($rows);

        return [
            'groups' => $this->groupByRequest($events, $elementId),
            'events' => $events,
            'after' => $more ? $oldest : null,
        ];
    }

    /**
     * Whether a row is part of an element's History, so its detail may be shown there.
     */
    public function inHistory(Event $event, int $elementId): bool
    {
        if ($event->family !== Event::FAMILY_ELEMENT) {
            return false;
        }
        if ($event->elementId === $elementId || $event->ownerId === $elementId) {
            return true;
        }

        return $event->event === ElementWatcher::EVENT_BULK && (new Query())
            ->from(Table::EVENT_ELEMENTS)
            ->where(['eventId' => $event->id, 'elementId' => $elementId])
            ->exists();
    }

    /**
     * What a batch row reads as in History: "Included in a re-save of 1,240 entries" for one of
     * the element service's own operations, and "Included in a batch of 4 entries (Set Status)"
     * for an index action or a job, named by its display name or, for a job, which has none, by
     * its class's own name in words.
     */
    public function batchSummary(Event $event): string
    {
        $data = $event->data ?? [];
        $type = is_string($data['elementType'] ?? null) ? $data['elementType'] : '';
        $count = (int)($data['count'] ?? 0);
        $params = [
            'count' => Craft::$app->getFormatter()->asInteger($count),
            'type' => $this->isElementType($type)
                ? ($count === 1 ? $type::lowerDisplayName() : $type::pluralLowerDisplayName())
                : Craft::t('auditor', 'elements'),
        ];

        $operation = is_string($data['operation'] ?? null) ? $data['operation'] : '';
        if (isset(self::OPERATION_LABELS[$operation])) {
            return Craft::t('auditor', 'Included in a {operation} of {count} {type}', $params + [
                'operation' => mb_strtolower(Craft::t('auditor', self::OPERATION_LABELS[$operation])),
            ]);
        }
        if ($operation === '') {
            return Craft::t('auditor', 'Included in a batch of {count} {type}', $params);
        }

        // A component's default display name is its class's short name, which reads no better.
        $basename = StringHelper::basename($operation);
        $name = $this->displayName($operation);
        if ($name === null || $name === $basename) {
            $name = ucfirst(mb_strtolower(Inflector::camel2words($basename, false)));
        }

        return Craft::t('auditor', 'Included in a batch of {count} {type} ({name})', $params + ['name' => $name]);
    }

    /**
     * A row's detail, for the slideout, as the viewer may see it: the address and browser are
     * cleared first for anyone not allowed to see them (BR-27), so no template can show them, and
     * the link to the rest of its request is offered only to someone who can open the log.
     */
    public function renderDetail(Event $event, ?User $viewer): string
    {
        if (!$viewer?->can(Auditor::PERMISSION_VIEW_NETWORK)) {
            $event->ip = null;
            $event->userAgent = null;
        }

        return Craft::$app->getView()->renderTemplate('auditor/_slideout', [
            'event' => $event,
            'url' => $event->id !== null ? ($this->subjectUrls([$event], $viewer)[$event->id] ?? null) : null,
            'impersonator' => $this->impersonatorLabel($event),
            'described' => $this->describe($event),
            'auditor' => $this,
            'requestUrl' => $viewer?->can(Auditor::PERMISSION_VIEW_LOG) ? $this->requestUrl($event) : null,
        ], View::TEMPLATE_MODE_CP);
    }

    /**
     * The log narrowed to the row's request. The request id has no index of its own, so the
     * address also bounds the search to the day either side of the row, which the date index
     * serves; a request longer than that is not a request anyone runs.
     */
    private function requestUrl(Event $event): ?string
    {
        if ($event->requestId === null || $event->dateCreated === null) {
            return null;
        }

        $date = (clone $event->dateCreated)->setTimezone(new DateTimeZone(Craft::$app->getTimeZone()));
        $filter = new Filter([
            'request' => $event->requestId,
            'from' => (clone $date)->modify('-1 day')->setTime(0, 0),
            'to' => (clone $date)->modify('+1 day')->setTime(0, 0),
        ]);

        return UrlHelper::cpUrl(Auditor::HANDLE, $filter->toParams());
    }

    /**
     * History's three sources, each an ids-only query its own index serves, with the id column
     * it pages on.
     *
     * @return list<array{string, Query<int, array<string, mixed>>}>
     */
    private function historySources(int $elementId): array
    {
        $element = fn(string $column) => (new Query())
            ->select('id')
            ->from(Table::EVENTS)
            ->where([$column => $elementId, 'family' => Event::FAMILY_ELEMENT]);

        return [
            ['id', $element('elementId')],
            ['id', $element('ownerId')],
            ['eventId', (new Query())
                ->select('eventId')
                ->distinct()
                ->from(Table::EVENT_ELEMENTS)
                ->where(['elementId' => $elementId]),
            ],
        ];
    }

    /**
     * Rows grouped by the request that wrote them, newest group first. In each, a nested
     * element's row sits beneath the save of the element it belongs to — the first of the
     * element's own rows written after it, since Craft saves nested elements before their owner
     * finishes — so a request that saved the element twice reads as two publishes. A nested row
     * with no such save (a block saved on its own) stays where it fell.
     *
     * @param list<Event> $events Newest first.
     * @return list<list<Event>>
     */
    private function groupByRequest(array $events, int $elementId): array
    {
        $groups = [];
        foreach ($events as $event) {
            $groups[$event->requestId ?? "row-$event->id"][] = $event;
        }

        $isNested = fn(Event $event) => $event->elementId !== $elementId && $event->ownerId === $elementId;

        return array_values(array_map(function(array $rows) use ($isNested) {
            $heads = array_values(array_filter($rows, fn(Event $event) => !$isNested($event)));
            $beneath = [];
            $placed = [];
            foreach ($rows as $row) {
                if (!$isNested($row)) {
                    continue;
                }
                // Rows are newest first, so the last head newer than the row is the next one written.
                $head = null;
                foreach ($heads as $candidate) {
                    if ($candidate->id > $row->id) {
                        $head = $candidate;
                    }
                }
                if ($head !== null) {
                    $beneath[$head->id][] = $row;
                    $placed[$row->id] = true;
                }
            }

            $ordered = [];
            foreach ($rows as $row) {
                if (!$isNested($row)) {
                    $ordered = [...$ordered, $row, ...($beneath[$row->id] ?? [])];
                } elseif (!isset($placed[$row->id])) {
                    $ordered[] = $row;
                }
            }

            return $ordered;
        }, $groups));
    }

    /**
     * The query behind a page, narrowed by the filter.
     *
     * @return Query<int, array<string, mixed>>
     */
    public function query(Filter $filter): Query
    {
        $query = (new Query())->from(Table::EVENTS);

        foreach (['family', 'event', 'elementType', 'actorId', 'context', 'siteId'] as $column) {
            if ($filter->$column !== null) {
                $query->andWhere([$column => $filter->$column]);
            }
        }

        if ($filter->request !== null) {
            $query->andWhere(['requestId' => $filter->request]);
        }

        if ($filter->from !== null) {
            $query->andWhere(['>=', 'dateCreated', Db::prepareDateForDb($filter->from)]);
        }

        if ($filter->to !== null) {
            // The last day is shown whole: everything before the start of the next.
            $end = (clone $filter->to)->add(new DateInterval('P1D'));
            $query->andWhere(['<', 'dateCreated', Db::prepareDateForDb($end)]);
        }

        if ($filter->search !== null) {
            // Labels only (BR-29) — never the logged values, which may hold what was redacted elsewhere.
            $query->andWhere([
                'or',
                ['like', 'elementLabel', $filter->search],
                ['like', 'actorLabel', $filter->search],
            ]);
        }

        return $query;
    }

    public function eventLabel(string $event): string
    {
        return Craft::t('auditor', self::EVENT_LABELS[$event] ?? $event);
    }

    public function familyLabel(string $family): string
    {
        return Craft::t('auditor', self::FAMILY_LABELS[$family] ?? $family);
    }

    public function contextLabel(?string $context): string
    {
        return $context === null ? '' : Craft::t('auditor', self::CONTEXTS[$context] ?? $context);
    }

    /**
     * An element type's name, or a user group's, a pricing rule's or a discount's, or the class's
     * own name when it is gone.
     */
    public function typeLabel(?string $type): string
    {
        if ($type === null || $type === '') {
            return '';
        }
        if ($type === UserGroup::class) {
            return Craft::t('auditor', 'User group');
        }
        if ($type === PromotionsWatcher::PRICING_RULE || $type === PromotionsWatcher::DISCOUNT) {
            return Craft::t('auditor', PromotionsWatcher::typeName($type));
        }

        return $this->displayName($type) ?? $type;
    }

    public function siteLabel(?int $siteId): string
    {
        $site = $siteId ? Craft::$app->getSites()->getSiteById($siteId, true) : null;

        return $site ? Craft::t('site', $site->getName()) : '';
    }

    /**
     * The filter's options, each list headed by an empty "All" choice.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function filterOptions(): array
    {
        $all = ['label' => Craft::t('auditor', 'All'), 'value' => ''];

        $families = [$all];
        $events = [$all];
        foreach (self::EVENTS as $family => $names) {
            $families[] = ['label' => $this->familyLabel($family), 'value' => $family];
            $events[] = ['optgroup' => $this->familyLabel($family)];
            foreach ($names as $name) {
                $events[] = ['label' => $this->eventLabel($name), 'value' => $name];
            }
        }

        $contexts = [$all];
        foreach (array_keys(self::CONTEXTS) as $context) {
            $contexts[] = ['label' => $this->contextLabel($context), 'value' => $context];
        }

        $types = [];
        foreach (Craft::$app->getElements()->getAllElementTypes() as $type) {
            $types[] = ['label' => $type::pluralDisplayName(), 'value' => $type];
        }
        $types[] = ['label' => Craft::t('auditor', 'User groups'), 'value' => UserGroup::class];
        if (CommerceWatcher::applies()) {
            $types[] = ['label' => Craft::t('auditor', 'Catalog pricing rules'), 'value' => PromotionsWatcher::PRICING_RULE];
            $types[] = ['label' => Craft::t('auditor', 'Discounts'), 'value' => PromotionsWatcher::DISCOUNT];
        }
        usort($types, fn($a, $b) => strcasecmp($a['label'], $b['label']));

        $sites = [$all];
        foreach (Craft::$app->getSites()->getAllSites(true) as $site) {
            $sites[] = ['label' => Craft::t('site', $site->getName()), 'value' => $site->id];
        }

        return [
            'family' => $families,
            'event' => $events,
            'context' => $contexts,
            'elementType' => [$all, ...$types],
            'siteId' => $sites,
        ];
    }

    /**
     * The control panel address of each row's subject that still exists and the viewer may see,
     * keyed by row id: one query per element type on the page. A deleted subject has none, and
     * its row shows only the label it was recorded with (TN-5).
     *
     * @param list<Event> $events
     * @return array<int, string>
     */
    public function subjectUrls(array $events, ?User $viewer): array
    {
        $ids = [];
        foreach ($events as $event) {
            if ($event->elementId !== null && $event->elementType !== null && $this->isElementType($event->elementType)) {
                $ids[$event->elementType][$event->elementId] = true;
            }
        }

        $urls = [];
        foreach ($ids as $type => $elementIds) {
            /** @var class-string<ElementInterface> $type */
            $elements = $type::find()
                ->id(array_keys($elementIds))
                ->site('*')
                ->unique()
                ->status(null)
                ->all();

            foreach ($elements as $element) {
                if ($viewer === null || !Craft::$app->getElements()->canView($element, $viewer)) {
                    continue;
                }
                $url = $element->getCpEditUrl();
                if ($url !== null) {
                    $urls[$type][(int)$element->id] = $url;
                }
            }
        }

        $byEvent = [];
        foreach ($events as $event) {
            $url = $urls[$event->elementType][$event->elementId] ?? null;
            if ($url !== null && $event->id !== null) {
                $byEvent[$event->id] = $url;
            }
        }

        return $byEvent;
    }

    /**
     * Who a row's impersonator was, by their address now, or by id once they are gone.
     */
    public function impersonatorLabel(Event $event): ?string
    {
        if ($event->impersonatorId === null) {
            return null;
        }

        $user = User::find()->id($event->impersonatorId)->status(null)->one();

        return $user->email ?? Craft::t('auditor', 'User #{id}', ['id' => $event->impersonatorId]);
    }

    /**
     * What the slideout shows for a row's data: `changes`, each with a before and an after, and
     * `details`, each a single value. Every value is already a string.
     *
     * A save's or a creation's fields are changes, keyed by handle; any other payload is flattened
     * — an `{old, new}` pair anywhere in it is a change, and everything else a detail. A save's list
     * of items — an order's line items or adjustments (BR-23, BR-24) — is a `collection`: one table
     * row per item added or removed, and one per field changed on an item.
     *
     * @return array{
     *     changes: list<array{key: string, label: string, old: string, new: string, redacted: bool, html: bool}>,
     *     details: list<array{key: string, label: string, value: string, redacted: bool, code?: string}>,
     *     collections: list<array{key: string, label: string, rows: list<array{id: string, item: string, change: string, old: string, new: string, redacted: bool}>}>,
     * }
     */
    public function describe(Event $event): array
    {
        $described = ['changes' => [], 'details' => [], 'collections' => []];
        $data = $event->data ?? [];

        if ($event->family === Event::FAMILY_ELEMENT && in_array($event->event, [ElementWatcher::EVENT_SAVED, ElementWatcher::EVENT_CREATED], true)) {
            foreach ($data as $key => $entry) {
                // A model's id, kept beside its changes (BR-40); an element's values are all changes.
                if (!is_array($entry)) {
                    $described['details'][] = ['key' => (string)$key, 'label' => $this->keyLabel((string)$key), 'value' => $this->format($entry), 'redacted' => false];
                    continue;
                }
                if (self::isCount($entry)) {
                    $described['changes'][] = $this->count((string)$key, $entry);
                    continue;
                }
                if (self::isCollection($entry)) {
                    $described['collections'][] = $this->collection((string)$key, $entry);
                    continue;
                }
                $described['changes'][] = [
                    'key' => (string)$key,
                    'label' => (string)($entry['label'] ?? $key),
                    'old' => $this->format($entry['old'] ?? null),
                    'new' => $this->format($entry['new'] ?? null),
                    'redacted' => ($entry['redacted'] ?? false) === true,
                    'html' => $this->isHtml((string)$key, $entry),
                ];
            }

            return $described;
        }

        if ($event->event === AuthWatcher::EVENT_PERMISSIONS_CHANGED) {
            $data = $this->permissionChanges($data);
        }

        $this->flatten($data, '', '', $described);

        // A failed sign-in's reason in words, with Craft's own code beside it for support.
        if ($event->event === AuthWatcher::EVENT_LOGIN_FAILED) {
            foreach ($described['details'] as $i => $detail) {
                if ($detail['key'] === 'reason' && !$detail['redacted'] && is_string($data['reason'] ?? null)) {
                    $described['details'][$i]['value'] = $this->reasonLabel($event);
                    $described['details'][$i]['code'] = $data['reason'];
                }
            }
        }

        return $described;
    }

    /**
     * Why a sign-in failed, in words: Craft's reason code (`User::AUTH_*`) mapped to a phrase, or
     * the code itself for one this plugin does not know. Empty for any other row. A wrong password
     * against a username nobody has is worded as that, since Craft gives both the same code.
     */
    public function reasonLabel(Event $event): string
    {
        $reason = $event->event === AuthWatcher::EVENT_LOGIN_FAILED ? ($event->data['reason'] ?? null) : null;
        if (!is_string($reason) || $reason === '') {
            return '';
        }
        if ($reason === User::AUTH_INVALID_CREDENTIALS && $event->elementId === null) {
            return Craft::t('auditor', 'No account with that username');
        }

        return isset(self::REASON_LABELS[$reason]) ? Craft::t('auditor', self::REASON_LABELS[$reason]) : $reason;
    }

    /**
     * Whether a save's value is a rich-text field's HTML, shown rendered and sanitised rather than
     * as text: marked so when it was recorded, or — for a row recorded before values were marked —
     * keyed by the handle of a field that stores HTML. A string from any other field is never
     * rendered, whatever it contains.
     *
     * @param array<array-key, mixed> $entry
     */
    private function isHtml(string $key, array $entry): bool
    {
        if (array_key_exists('html', $entry)) {
            return $entry['html'] === true;
        }
        if ((!is_string($entry['old'] ?? null) && !is_string($entry['new'] ?? null)) || !preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $key)) {
            return false;
        }
        $field = Craft::$app->getFields()->getFieldByHandle($key);

        return $field !== null && Differ::isHtmlField($field);
    }

    /**
     * A count of things added and removed that are not recorded themselves: a discount's coupon
     * codes (BR-40).
     *
     * @param array<array-key, mixed> $entry
     */
    private static function isCount(array $entry): bool
    {
        return is_int($entry['added'] ?? null) && is_int($entry['removed'] ?? null);
    }

    /**
     * @param array<array-key, mixed> $entry
     * @return array{key: string, label: string, old: string, new: string, redacted: bool, html: bool}
     */
    private function count(string $key, array $entry): array
    {
        $parts = [];
        if ($entry['added'] > 0) {
            $parts[] = Craft::t('auditor', '{count} added', ['count' => $entry['added']]);
        }
        if ($entry['removed'] > 0) {
            $parts[] = Craft::t('auditor', '{count} removed', ['count' => $entry['removed']]);
        }

        return [
            'key' => $key,
            'label' => Craft::t('auditor', (string)($entry['label'] ?? $key)),
            'old' => '',
            'new' => implode(', ', $parts),
            'redacted' => false,
            'html' => false,
        ];
    }

    /**
     * @param array<array-key, mixed> $entry
     */
    private static function isCollection(array $entry): bool
    {
        return !array_key_exists('old', $entry) && !array_key_exists('new', $entry)
            && (isset($entry['added']) || isset($entry['removed']) || isset($entry['changed']));
    }

    /**
     * A list of items as table rows: each item added or removed with its values in one cell, each
     * field changed on an item as a row of its own. Field labels are stored in English and shown
     * translated.
     *
     * @param array<array-key, mixed> $entry
     * @return array{key: string, label: string, rows: list<array{id: string, item: string, change: string, old: string, new: string, redacted: bool}>}
     */
    private function collection(string $key, array $entry): array
    {
        $fields = is_array($entry['fields'] ?? null) ? $entry['fields'] : [];
        $fieldLabel = fn(string $name): string => Craft::t('auditor', (string)($fields[$name] ?? $name));
        $collection = [
            'key' => $key,
            'label' => Craft::t('auditor', (string)($entry['label'] ?? $key)),
            'rows' => [],
        ];

        $summary = function(mixed $values) use ($fieldLabel): string {
            $lines = [];
            foreach (is_array($values) ? $values : [] as $name => $value) {
                $text = $value === ['redacted' => true]
                    ? Craft::t('auditor', 'value not recorded')
                    : $this->format($value);
                if ($text !== '') {
                    $lines[] = sprintf('%s: %s', $fieldLabel((string)$name), $text);
                }
            }
            return implode("\n", $lines);
        };

        foreach (['added' => 'Added', 'removed' => 'Removed'] as $list => $change) {
            foreach (is_array($entry[$list] ?? null) ? $entry[$list] : [] as $item) {
                $text = $summary($item['values'] ?? []);
                $collection['rows'][] = [
                    'id' => (string)($item['id'] ?? ''),
                    'item' => (string)($item['label'] ?? ''),
                    'change' => Craft::t('auditor', $change),
                    'old' => $list === 'removed' ? $text : '',
                    'new' => $list === 'added' ? $text : '',
                    'redacted' => false,
                ];
            }
        }

        foreach (is_array($entry['changed'] ?? null) ? $entry['changed'] : [] as $item) {
            foreach (is_array($item['changes'] ?? null) ? $item['changes'] : [] as $name => $pair) {
                $pair = is_array($pair) ? $pair : [];
                $collection['rows'][] = [
                    'id' => (string)($item['id'] ?? ''),
                    'item' => (string)($item['label'] ?? ''),
                    'change' => $fieldLabel((string)$name),
                    'old' => $this->format($pair['old'] ?? null),
                    'new' => $this->format($pair['new'] ?? null),
                    'redacted' => ($pair['redacted'] ?? false) === true,
                ];
            }
        }

        return $collection;
    }

    /**
     * A value as one line of text, or pretty-printed JSON for anything structured.
     */
    public function format(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? Craft::t('auditor', 'Yes') : Craft::t('auditor', 'No');
        }
        if (is_scalar($value)) {
            return (string)$value;
        }
        if (is_array($value) && array_is_list($value) && array_filter($value, fn($item) => !is_scalar($item) && $item !== null) === []) {
            return implode(', ', array_map(fn($item) => $this->format($item), $value));
        }

        return Json::encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * A permissions row as what was granted and what was taken away, rather than two long lists;
     * a `partial` row, whose old set was not readable, as the new set alone.
     *
     * @param array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    private function permissionChanges(array $data): array
    {
        $new = is_array($data['new'] ?? null) ? $data['new'] : [];
        if (!is_array($data['old'] ?? null)) {
            return ['permissions' => $new, 'partial' => true];
        }
        $old = $data['old'];

        return [
            'added' => array_values(array_diff($new, $old)),
            'removed' => array_values(array_diff($old, $new)),
        ];
    }

    /**
     * @param array<array-key, mixed> $data
     * @param array{changes: list<array<string, mixed>>, details: list<array<string, mixed>>, collections: list<array<string, mixed>>} $described
     */
    private function flatten(array $data, string $prefix, string $keyPrefix, array &$described): void
    {
        foreach ($data as $key => $value) {
            $key = (string)$key;
            if (in_array($key, self::HIDDEN_KEYS, true)) {
                continue;
            }
            $label = $prefix === '' ? $this->keyLabel($key) : $prefix . ' ' . mb_strtolower($this->keyLabel($key));
            $path = $keyPrefix . $key;

            if (is_array($value) && ($value['redacted'] ?? false) === true) {
                $described['details'][] = ['key' => $path, 'label' => $label, 'value' => '', 'redacted' => true];
            } elseif (is_array($value) && $this->isChange($value)) {
                $described['changes'][] = [
                    'key' => $path,
                    'label' => $label,
                    'old' => $this->format($value['old'] ?? null),
                    'new' => $this->format($value['new'] ?? null),
                    'redacted' => false,
                    'html' => false,
                ];
            } elseif ($key === 'group' && is_array($value) && isset($value['name'])) {
                $name = (string)$value['name'];
                $described['details'][] = [
                    'key' => $path,
                    'label' => $label,
                    'value' => isset($value['handle']) ? "$name ({$value['handle']})" : $name,
                    'redacted' => false,
                ];
            } elseif (is_array($value) && $value !== [] && !array_is_list($value)) {
                // A group's `changes` are its own fields; any other nested map is named by its key.
                $this->flatten($value, $key === 'changes' ? $prefix : $label, "$path.", $described);
            } else {
                $text = match (true) {
                    $key === 'operation' && is_string($value) && isset(self::OPERATION_LABELS[$value]) => Craft::t('auditor', self::OPERATION_LABELS[$value]),
                    $key === 'action' && is_string($value) && isset(self::MOVE_LABELS[$value]) => Craft::t('auditor', self::MOVE_LABELS[$value]),
                    is_string($value) && in_array($key, self::CLASS_KEYS, true) => $this->displayName($value) ?? $value,
                    default => $this->format($value),
                };
                $described['details'][] = ['key' => $path, 'label' => $label, 'value' => $text, 'redacted' => false];
            }
        }
    }

    /**
     * @param array<array-key, mixed> $value
     */
    private function isChange(array $value): bool
    {
        return $value !== []
            && (array_key_exists('old', $value) || array_key_exists('new', $value))
            && array_diff(array_keys($value), ['old', 'new']) === [];
    }

    private function keyLabel(string $key): string
    {
        return Craft::t('auditor', self::KEY_LABELS[$key] ?? ucfirst(mb_strtolower(Inflector::camel2words($key, false))));
    }

    /**
     * A class's display name, when the string names a class that has one — an element type, or a
     * two-step method.
     */
    private function displayName(string $class): ?string
    {
        if (!str_contains($class, '\\') || !class_exists($class) || !method_exists($class, 'displayName')) {
            return null;
        }

        return (string)$class::displayName();
    }

    private function isElementType(string $type): bool
    {
        return class_exists($type) && is_subclass_of($type, ElementInterface::class);
    }

    /**
     * @param array<array<string, mixed>> $rows
     * @return list<Event>
     */
    private function hydrateAll(array $rows): array
    {
        $events = array_values(array_map(fn(array $row) => $this->hydrate($row), $rows));
        $this->relabelNested($events);

        return $events;
    }

    /**
     * Rows recorded before nested elements were named by their type and owner read as Craft's
     * words for an untitled element, "Entry 856". Those are named now as a new row would be
     * (BR-12): from the element, trashed or not, or — once it is gone for good — from its owner.
     * Costs nothing on a page without such rows; otherwise a query per element type and per
     * owner type.
     *
     * @param list<Event> $events
     */
    private function relabelNested(array $events): void
    {
        $stale = [];
        foreach ($events as $event) {
            if (
                $event->family === Event::FAMILY_ELEMENT
                && $event->ownerId !== null
                && $event->elementId !== null
                && $event->elementType !== null
                && $this->isElementType($event->elementType)
                && preg_match('/^\S.* #?(\d+)$/', (string)$event->elementLabel, $match)
                && (int)$match[1] === $event->elementId
            ) {
                $stale[] = $event;
            }
        }
        if ($stale === []) {
            return;
        }

        $byType = [];
        foreach ($stale as $event) {
            $byType[$event->elementType][$event->elementId] = true;
        }
        $elements = [];
        foreach ($byType as $type => $ids) {
            /** @var class-string<ElementInterface> $type */
            $query = $type::find()->id(array_keys($ids))->site('*')->unique()->status(null)->trashed(null);
            if (is_subclass_of($type, NestedElementInterface::class)) {
                $query->with(['owner']);
            }
            foreach ($query->all() as $element) {
                $elements[(int)$element->id] = $element;
            }
        }

        $ownerIds = [];
        foreach ($stale as $event) {
            if (!isset($elements[$event->elementId])) {
                $ownerIds[$event->ownerId] = true;
            }
        }
        $owners = [];
        if ($ownerIds !== []) {
            $ownersByType = [];
            foreach ((new Query())->select(['type', 'id'])->from(CraftTable::ELEMENTS)->where(['id' => array_keys($ownerIds)])->all() as $row) {
                $ownersByType[(string)$row['type']][] = (int)$row['id'];
            }
            foreach ($ownersByType as $type => $ids) {
                if (!$this->isElementType($type)) {
                    continue;
                }
                /** @var class-string<ElementInterface> $type */
                $query = $type::find()->id($ids)->site('*')->unique()->status(null)->trashed(null);
                foreach ($query->all() as $owner) {
                    $owners[(int)$owner->id] = $owner;
                }
            }
        }

        foreach ($stale as $event) {
            $element = $elements[$event->elementId] ?? null;
            if ($element !== null) {
                $event->elementLabel = ElementWatcher::labelFor($element);
            } elseif (isset($owners[$event->ownerId])) {
                /** @var class-string<ElementInterface> $type */
                $type = $event->elementType;
                $event->elementLabel = ElementWatcher::nestedLabel($type::displayName(), ElementWatcher::labelFor($owners[$event->ownerId]));
            }
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Event
    {
        $event = new Event();
        foreach (['id', 'elementId', 'siteId', 'ownerId', 'actorId', 'impersonatorId'] as $column) {
            $event->$column = isset($row[$column]) ? (int)$row[$column] : null;
        }
        foreach (['family', 'event'] as $column) {
            $event->$column = (string)$row[$column];
        }
        foreach (['elementType', 'elementLabel', 'actorLabel', 'context', 'contextDetail', 'ip', 'userAgent', 'requestId', 'uid'] as $column) {
            $event->$column = isset($row[$column]) ? (string)$row[$column] : null;
        }

        $data = $row['data'] ?? null;
        $data = is_string($data) ? Json::decodeIfJson($data) : $data;
        $event->data = is_array($data) ? $data : null;
        $event->dateCreated = DateTimeHelper::toDateTime($row['dateCreated']) ?: null;

        // Recorded as "System" before an unknown username had words of its own (BR-17).
        if ($event->event === AuthWatcher::EVENT_LOGIN_FAILED && $event->actorId === null && $event->actorLabel === Event::SYSTEM) {
            $event->actorLabel = Event::UNKNOWN_USER;
        }

        return $event;
    }
}
