<?php

namespace webdna\auditor\controllers;

use Craft;
use craft\controllers\EditUserTrait;
use craft\elements\User;
use craft\web\Controller;
use craft\web\CpScreenResponseBehavior;
use craft\web\View;
use webdna\auditor\Auditor;
use webdna\auditor\models\Event;
use webdna\auditor\services\Events;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * A user's Activity (BR-32): one of Craft's own user edit screens, with two tabs — the changes
 * they made and their account's events — the next page of either, and one row's detail.
 *
 * Pro only, and refused on Lite whatever the viewer holds (BR-33). Every action names the user,
 * and answers only to someone who holds `auditor:viewUserActivity` and, for anyone but themselves,
 * Craft's `viewUsers` — a row's detail only when the row is part of that user's Activity, so the
 * permission opens no other part of the log.
 *
 * @since 1.0.0
 */
class ActivityController extends Controller
{
    use EditUserTrait;

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();
        if (!Auditor::getInstance()->is(Auditor::EDITION_PRO)) {
            throw new ForbiddenHttpException('Activity is part of Auditor Pro.');
        }
        $this->requirePermission(Auditor::PERMISSION_VIEW_USER_ACTIVITY);

        return true;
    }

    /**
     * `admin/users/<id>/activity`, or `admin/myaccount/activity` — the screen, with the first page
     * of each tab.
     */
    public function actionIndex(?int $userId = null): Response
    {
        $user = $this->editedUser($userId);
        $service = Auditor::getInstance()->getEvents();

        // Keyed by each tab's container id, as Craft's tabs toggle the container of that id.
        $tabs = [];
        foreach ($this->tabLabels() as $tab => $label) {
            $page = $service->activity((int)$user->id, $tab);
            $tabs["auditor-activity-$tab"] = [
                'tab' => $tab,
                'label' => $label,
                'events' => $page['events'],
                'after' => $page['after'],
            ] + $this->rowVariables($user, $page['events']);
        }

        /**
         * @var Response|CpScreenResponseBehavior $response
         * @phpstan-ignore varTag.nativeType
         */
        $response = $this->asEditUserScreen($user, Auditor::ACTIVITY_SCREEN);
        // Nothing here is saved, so no save shortcut.
        $response->altActions([]);
        $response->tabs(array_map(fn(string $id) => [
            'label' => $tabs[$id]['label'],
            'url' => "#$id",
        ], array_combine(array_keys($tabs), array_keys($tabs))));
        $response->contentTemplate('auditor/_activity', [
            'user' => $user,
            'tabs' => $tabs,
        ]);

        return $response;
    }

    /**
     * `actions/auditor/activity/page?userId=&tab=&after=` — the next page of one tab, rendered, and
     * the key of the page after it.
     */
    public function actionPage(int $userId, string $tab, int $after): Response
    {
        $user = $this->editedUser($userId);
        $service = Auditor::getInstance()->getEvents();
        $page = $service->activity((int)$user->id, $this->tab($tab), $after);
        $variables = $this->rowVariables($user, $page['events']);

        $html = '';
        foreach ($page['events'] as $event) {
            $html .= Craft::$app->getView()->renderTemplate('auditor/_row', [
                'event' => $event,
                'url' => $variables['urls'][$event->id] ?? null,
            ] + $variables, View::TEMPLATE_MODE_CP);
        }

        return $this->asJson([
            'html' => $html,
            'after' => $page['after'],
        ]);
    }

    /**
     * `actions/auditor/activity/view?userId=&id=` — one of the Activity's rows in full.
     */
    public function actionView(int $userId, int $id): Response
    {
        $user = $this->editedUser($userId);
        $service = Auditor::getInstance()->getEvents();
        $event = $service->get($id);
        if ($event === null || !$service->inActivity($event, (int)$user->id)) {
            throw new NotFoundHttpException('Event not found');
        }

        return $this->asRaw($service->renderDetail($event, static::currentUser()));
    }

    /**
     * @return array<Events::ACTIVITY_*, string>
     */
    private function tabLabels(): array
    {
        return [
            Events::ACTIVITY_CHANGES => Craft::t('auditor', 'Changes they made'),
            Events::ACTIVITY_ACCOUNT => Craft::t('auditor', 'Account events'),
        ];
    }

    /**
     * @return Events::ACTIVITY_*
     */
    private function tab(string $tab): string
    {
        return match ($tab) {
            Events::ACTIVITY_CHANGES, Events::ACTIVITY_ACCOUNT => $tab,
            default => throw new BadRequestHttpException("Unknown tab: $tab"),
        };
    }

    /**
     * What every rendered row needs besides itself.
     *
     * @param list<Event> $events
     * @return array<string, mixed>
     */
    private function rowVariables(User $user, array $events): array
    {
        $service = Auditor::getInstance()->getEvents();

        return [
            'urls' => $service->subjectUrls($events, static::currentUser()),
            'auditor' => $service,
            'multiSite' => Craft::$app->getIsMultiSite(),
            'viewAction' => 'auditor/activity/view',
            'viewParams' => ['userId' => (int)$user->id],
        ];
    }
}
