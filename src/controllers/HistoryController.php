<?php

namespace webdna\auditor\controllers;

use Craft;
use craft\base\ElementInterface;
use craft\web\Controller;
use craft\web\View;
use webdna\auditor\Auditor;
use webdna\auditor\models\Event;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * An element's History (BR-31): its rows, grouped by request, for the slideout its action menu
 * opens; the next page; and one row's detail.
 *
 * Pro only, and refused on Lite whatever the viewer holds (BR-33). Every action names the element,
 * and answers only to someone who holds `auditor:viewHistory` and can view that element — a row's
 * detail only when the row is part of that element's History, so the permission opens no other
 * part of the log.
 *
 * @since 1.0.0
 */
class HistoryController extends Controller
{
    private ElementInterface $element;

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();
        if (!Auditor::getInstance()->is(Auditor::EDITION_PRO)) {
            throw new ForbiddenHttpException('History is part of Auditor Pro.');
        }
        $this->requirePermission(Auditor::PERMISSION_VIEW_HISTORY);

        $elementId = (int)$this->request->getRequiredQueryParam('elementId');
        $siteId = $this->request->getQueryParam('siteId');
        $element = Craft::$app->getElements()->getElementById($elementId, null, $siteId ? (int)$siteId : null, [
            'status' => null,
        ]);
        if ($element === null) {
            throw new NotFoundHttpException('Element not found');
        }
        if (!Craft::$app->getElements()->canView($element, static::currentUser())) {
            throw new ForbiddenHttpException('User not authorized to view this element.');
        }
        $this->element = $element;

        return true;
    }

    /**
     * `actions/auditor/history/index?elementId=&siteId=` — the panel, with its first page.
     */
    public function actionIndex(): Response
    {
        $service = Auditor::getInstance()->getEvents();
        $page = $service->history((int)$this->element->id);

        return $this->asRaw(Craft::$app->getView()->renderTemplate('auditor/_history', [
            'element' => $this->element,
            'groups' => $page['groups'],
            'after' => $page['after'],
        ] + $this->rowVariables($page['events']), View::TEMPLATE_MODE_CP));
    }

    /**
     * `actions/auditor/history/page?elementId=&siteId=&after=` — the next page's groups, rendered,
     * and the key of the page after it.
     */
    public function actionPage(int $after): Response
    {
        $service = Auditor::getInstance()->getEvents();
        $page = $service->history((int)$this->element->id, $after);

        $html = '';
        foreach ($page['groups'] as $group) {
            $html .= Craft::$app->getView()->renderTemplate('auditor/_history-group', [
                'group' => $group,
            ] + $this->rowVariables($page['events']), View::TEMPLATE_MODE_CP);
        }

        return $this->asJson([
            'html' => $html,
            'after' => $page['after'],
        ]);
    }

    /**
     * `actions/auditor/history/view?elementId=&siteId=&id=` — one of the History's rows in full.
     */
    public function actionView(int $id): Response
    {
        $service = Auditor::getInstance()->getEvents();
        $event = $service->get($id);
        if ($event === null || !$service->inHistory($event, (int)$this->element->id)) {
            throw new NotFoundHttpException('Event not found');
        }

        return $this->asRaw($service->renderDetail($event, static::currentUser()));
    }

    /**
     * What every rendered row needs besides itself.
     *
     * @param list<Event> $events
     * @return array<string, mixed>
     */
    private function rowVariables(array $events): array
    {
        $service = Auditor::getInstance()->getEvents();

        return [
            'elementId' => (int)$this->element->id,
            'urls' => $service->subjectUrls($events, static::currentUser()),
            'auditor' => $service,
            'multiSite' => Craft::$app->getIsMultiSite(),
            'viewAction' => 'auditor/history/view',
            'viewParams' => ['elementId' => (int)$this->element->id, 'siteId' => (int)$this->element->siteId],
        ];
    }
}
