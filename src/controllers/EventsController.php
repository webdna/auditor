<?php

namespace webdna\auditor\controllers;

use Craft;
use craft\elements\User;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use craft\web\View;
use webdna\auditor\Auditor;
use webdna\auditor\models\Event;
use webdna\auditor\models\Filter;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The log screen: the page, its next pages, and a row's detail for the slideout.
 *
 * Read-only — there is no action here that changes a row (BR-1, BR-28). Every action is a control
 * panel request by someone allowed to open the log; the page's own address is also refused by
 * Craft without it, but these action addresses are not, so the check is made here.
 *
 * @since 1.0.0
 */
class EventsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();
        $this->requirePermission(Auditor::PERMISSION_VIEW_LOG);

        return true;
    }

    /**
     * `admin/auditor` — the first page, filtered by the query string.
     */
    public function actionLog(): Response
    {
        $service = Auditor::getInstance()->getEvents();
        $filter = Filter::fromParams($this->request->getQueryParams());
        $page = $service->page($filter);

        // The form submits by GET, which drops the action's own query string, so any part of the
        // address that lives there (a `p` path parameter) goes in hidden inputs instead.
        $action = UrlHelper::cpUrl('auditor');
        parse_str((string)parse_url($action, PHP_URL_QUERY), $hidden);

        return $this->renderTemplate('auditor/index', [
            'filter' => $filter,
            'actor' => $filter->actorId ? User::find()->id($filter->actorId)->status(null)->one() : null,
            'options' => $service->filterOptions(),
            'events' => $page['events'],
            'after' => $page['after'],
            'urls' => $service->subjectUrls($page['events'], static::currentUser()),
            'auditor' => $service,
            'formAction' => strtok($action, '?'),
            'formHidden' => $hidden,
            'multiSite' => Craft::$app->getIsMultiSite(),
            'docsUrl' => Auditor::getInstance()->documentationUrl,
        ], View::TEMPLATE_MODE_CP);
    }

    /**
     * `actions/auditor/events/index` — the next page: its rows, rendered, and the key of the page
     * after it.
     */
    public function actionIndex(?int $after = null): Response
    {
        $service = Auditor::getInstance()->getEvents();
        $page = $service->page(Filter::fromParams($this->request->getQueryParams()), $after);
        $urls = $service->subjectUrls($page['events'], static::currentUser());

        $html = '';
        foreach ($page['events'] as $event) {
            $html .= Craft::$app->getView()->renderTemplate('auditor/_row', [
                'event' => $event,
                'url' => $urls[$event->id] ?? null,
                'auditor' => $service,
                'multiSite' => Craft::$app->getIsMultiSite(),
            ], View::TEMPLATE_MODE_CP);
        }

        return $this->asJson([
            'html' => $html,
            'after' => $page['after'],
        ]);
    }

    /**
     * `actions/auditor/events/view?id=` — one row in full, as an HTML fragment for the slideout.
     */
    public function actionView(int $id): Response
    {
        $service = Auditor::getInstance()->getEvents();
        $event = $service->get($id);
        if ($event === null) {
            throw new NotFoundHttpException('Event not found');
        }

        $this->hideNetwork($event);

        $html = Craft::$app->getView()->renderTemplate('auditor/_slideout', [
            'event' => $event,
            'url' => $service->subjectUrls([$event], static::currentUser())[$id] ?? null,
            'impersonator' => $service->impersonatorLabel($event),
            'described' => $service->describe($event),
            'auditor' => $service,
            'requestUrl' => $this->requestUrl($event),
        ], View::TEMPLATE_MODE_CP);

        return $this->asRaw($html);
    }

    /**
     * BR-27: the address and browser are cleared before the row reaches a template, so no
     * template can show them to someone not allowed to see them.
     */
    private function hideNetwork(Event $event): void
    {
        if (!Craft::$app->getUser()->checkPermission(Auditor::PERMISSION_VIEW_NETWORK)) {
            $event->ip = null;
            $event->userAgent = null;
        }
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

        $date = (clone $event->dateCreated)->setTimezone(new \DateTimeZone(Craft::$app->getTimeZone()));
        $filter = new Filter([
            'request' => $event->requestId,
            'from' => (clone $date)->modify('-1 day')->setTime(0, 0),
            'to' => (clone $date)->modify('+1 day')->setTime(0, 0),
        ]);

        return UrlHelper::cpUrl('auditor', $filter->toParams());
    }
}
