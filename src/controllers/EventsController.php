<?php

namespace webdna\auditor\controllers;

use Craft;
use craft\elements\User;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use craft\web\View;
use webdna\auditor\Auditor;
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

        $html = $service->renderDetail($event, static::currentUser());

        return $this->asRaw($html);
    }
}
