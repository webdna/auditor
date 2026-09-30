<?php

namespace webdna\auditor\tests\integration;

use Closure;
use Codeception\Test\Unit;
use Craft;
use craft\controllers\UsersController;
use craft\db\Query;
use craft\elements\Entry;
use craft\elements\User;
use craft\events\DefineEditUserScreensEvent;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use craft\models\UserGroup;
use craft\services\UserPermissions;
use craft\web\CpScreenResponseBehavior;
use DateTime;
use ReflectionMethod;
use webdna\auditor\Auditor;
use webdna\auditor\db\Table;
use webdna\auditor\models\Event;
use webdna\auditor\services\Events;
use webdna\auditor\services\Recorder;
use webdna\auditor\tests\Support\TestSite;
use webdna\auditor\watchers\AuthWatcher;
use webdna\auditor\watchers\ElementWatcher;
use Yii;
use yii\base\Event as YiiEvent;
use yii\log\Logger;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * A user's Activity (task 4.2): Lite offers no Activity and refuses its addresses whoever asks
 * (TS-9 step 2, BR-33); on Pro it is one of the user's edit screens for someone allowed it, and
 * offering it costs the other screens nothing (BR-37); and its two tabs split the log between the
 * changes the user made and their account's events (TS-9 step 4, BR-32), each paged by key.
 */
class ActivityTest extends Unit
{
    use SecondConnection;
    use WebRequests;

    private TestSite $site;

    private Auditor $plugin;

    private Events $events;

    private User $admin;

    /**
     * Holds Activity, but not Craft's `viewUsers`.
     */
    private User $auditor;

    /**
     * Whose Activity is read.
     */
    private User $member;

    private string $controllerNamespace;

    private string $edition;

    protected function _before(): void
    {
        $this->site = TestSite::build();
        $this->plugin = Auditor::getInstance();
        $this->events = $this->plugin->getEvents();
        $this->edition = $this->plugin->edition;
        $this->plugin->edition = Auditor::EDITION_PRO;

        // The harness is a console app, so Craft pointed the plugin at its console controllers.
        $this->controllerNamespace = $this->plugin->controllerNamespace;
        $this->plugin->controllerNamespace = 'webdna\\auditor\\controllers';

        $this->admin = $this->site->user('admin', true);
        $this->auditor = $this->site->user('auditor');
        $this->member = new User(['username' => 'member', 'email' => 'member@example.com', 'active' => true]);
        $this->site->save($this->member);
        $this->grant($this->auditor, ['accessCp', Auditor::PERMISSION_VIEW_USER_ACTIVITY]);

        Craft::$app->getDb()->createCommand()->delete(Table::EVENTS)->execute();
    }

    protected function _after(): void
    {
        $this->plugin->edition = $this->edition;
        $this->plugin->controllerNamespace = $this->controllerNamespace;
        $this->plugin->set('recorder', Recorder::class);
        Craft::$app->getUser()->setIdentity(null);
        TestSite::removeFiles();
    }

    /**
     * TS-9 step 2 (BR-33): on Lite no one is offered Activity, an admin included, and each of its
     * addresses answers 403 — the edition is checked before anything else.
     */
    public function testLiteOffersNoActivityAndRefusesItsAddresses(): void
    {
        $id = $this->insert(['elementId' => (int)$this->member->id, 'family' => Event::FAMILY_AUTH, 'event' => AuthWatcher::EVENT_LOGIN]);
        $this->plugin->edition = Auditor::EDITION_LITE;

        $this->assertArrayNotHasKey(Auditor::ACTIVITY_SCREEN, $this->screens($this->admin));
        foreach ($this->routes($this->member, $id) as $route => $params) {
            $this->assertRefused($this->admin, $route, $params, ForbiddenHttpException::class, "$route answered on Lite");
        }

        $this->assertNotContains(Auditor::PERMISSION_VIEW_USER_ACTIVITY, $this->ourPermissions(), 'Not offered for granting on Lite');
    }

    /**
     * BR-28, BR-32: on Pro, Activity is offered to and answers someone who holds the permission;
     * it refuses someone without it at every address. Another user's Activity also needs Craft's
     * `viewUsers`, as every other user screen does; their own does not.
     */
    public function testProOffersActivityToThoseAllowedIt(): void
    {
        $id = $this->insert(['elementId' => (int)$this->member->id, 'family' => Event::FAMILY_AUTH, 'event' => AuthWatcher::EVENT_LOGIN]);
        $own = $this->insert(['actorId' => (int)$this->auditor->id]);
        $stranger = $this->site->user('stranger');
        $this->grant($stranger, ['accessCp', 'viewUsers']);

        $this->assertContains(Auditor::PERMISSION_VIEW_USER_ACTIVITY, $this->ourPermissions());
        $this->assertSame('Activity', $this->screens($this->admin)[Auditor::ACTIVITY_SCREEN]['label'] ?? null);
        $this->assertArrayHasKey(Auditor::ACTIVITY_SCREEN, $this->screens($this->auditor));
        $this->assertArrayNotHasKey(Auditor::ACTIVITY_SCREEN, $this->screens($stranger), 'No screen without the permission');

        foreach ($this->routes($this->member, $id) as $route => $params) {
            $this->assertRefused($stranger, $route, $params, ForbiddenHttpException::class, "$route answered without the permission");
            $this->assertRefused($this->auditor, $route, $params, ForbiddenHttpException::class, "$route answered without viewUsers");
            $answer = $this->as($this->admin, function() use ($route, $params) {
                $response = Craft::$app->runAction($route, $params);

                return $route === 'auditor/activity/index' ? $this->content($response) : $response->data;
            });
            $this->assertNotEmpty($answer, "$route answered an admin");
        }

        // Their own Activity, at myaccount/activity (no user named) or by their own id.
        $this->assertStringContainsString("data-auditor-event=\"$own\"", $this->screen($this->auditor, null));
        $this->assertStringContainsString("data-auditor-event=\"$own\"", $this->screen($this->auditor, $this->auditor));
    }

    /**
     * BR-37: offering the screen asks only a permission — no query — so a user's other screens
     * pay nothing for it; Activity itself is read only when its screen is opened.
     */
    public function testOfferingTheScreenCostsNothing(): void
    {
        $logger = Yii::getLogger();
        Yii::setLogger(new Logger());
        try {
            $screens = $this->screens($this->admin);
            $queries = array_filter(Yii::getLogger()->messages, fn($message) => str_starts_with((string)$message[2], 'yii\db\Command::'));
        } finally {
            Yii::setLogger($logger);
        }

        $this->assertArrayHasKey(Auditor::ACTIVITY_SCREEN, $screens);
        $this->assertSame([], array_values($queries), 'No query');
    }

    /**
     * TS-9 step 4 (BR-32), from what the watchers really record: `member` signs in and saves an
     * entry, and `admin` adds them to a group. Their changes are the save; their account events
     * are the sign-in and the group change — which is `admin`'s change, not theirs.
     */
    public function testTheTabsSplitWhatTheyDidFromWhatHappenedToTheirAccount(): void
    {
        $entry = $this->site->entry('Summer menu');
        $editors = Craft::$app->getUserGroups()->getGroupByHandle('editors');
        $this->assertInstanceOf(UserGroup::class, $editors);
        $uid = $this->site->pages->uid;
        $this->grant($this->member, ['accessCp', "viewEntries:$uid", "saveEntries:$uid", "viewPeerEntries:$uid", "savePeerEntries:$uid"]);

        $db = $this->secondConnection();
        $recorder = new Recorder(['db' => $db]);
        $this->plugin->set('recorder', $recorder);
        try {
            $this->asWebRequest(function() use ($entry, $editors) {
                $this->assertTrue(Craft::$app->getUser()->login($this->member), 'Signed in');
                $entry->title = 'Autumn menu';
                $this->site->save($entry);
                Craft::$app->getUser()->setIdentity($this->admin);
                Craft::$app->getUsers()->assignUserToGroups((int)$this->member->id, [$editors->id]);
                Craft::$app->getUser()->setIdentity(null);
            }, cp: true);
            $rows = $recorder->getReady();
        } finally {
            $db->close();
        }
        $this->assertSame(
            [AuthWatcher::EVENT_LOGIN, ElementWatcher::EVENT_SAVED, AuthWatcher::EVENT_GROUPS_CHANGED],
            array_map(fn(Event $row) => $row->event, $rows),
        );
        // What the recorder would have written, on the connection Activity reads.
        (new ReflectionMethod(Recorder::class, 'insertRows'))->invoke($recorder, Craft::$app->getDb(), $rows);
        [$login, $save, $groups] = array_map(fn(array $row) => (int)$row['id'], (new Query())->from(Table::EVENTS)->orderBy(['id' => SORT_ASC])->all());

        $this->assertSame([$save], $this->ids($this->member, Events::ACTIVITY_CHANGES), 'Their changes: the save, not their sign-in');
        $this->assertSame([$groups, $login], $this->ids($this->member, Events::ACTIVITY_ACCOUNT));
        $this->assertSame([$groups], $this->ids($this->admin, Events::ACTIVITY_CHANGES), 'The group change is admin\'s change');
        $this->assertSame([], $this->ids($this->admin, Events::ACTIVITY_ACCOUNT));

        $html = $this->screen($this->admin, $this->member);
        $this->assertMatchesRegularExpression(
            '~<div id="auditor-activity-changes"[^>]*data-auditor-tab="changes">.*data-auditor-event="' . $save . '".*</div>\s*<div id="auditor-activity-account" class="auditor-activity hidden"~s',
            $html,
            'The save under Changes they made, shown first',
        );
        $this->assertMatchesRegularExpression('~<div id="auditor-activity-account".*data-auditor-event="' . $groups . '".*data-auditor-event="' . $login . '"~s', $html);
        $this->assertStringContainsString('data-auditor-view="auditor/activity/view"', $html);
        $this->assertStringContainsString('data-auditor-params="' . htmlspecialchars('{"userId":' . $this->member->id . '}') . '"', $html);
    }

    /**
     * BR-32: an account event whose actor is someone else, or no one, is still theirs — "signed
     * in as them", a failed sign-in — and it is the other person's change. A user group they
     * saved has no subject at all, and is still a change they made (the NULL trap in SQL).
     */
    public function testEveryRowLandsOnExactlyOneTab(): void
    {
        $member = (int)$this->member->id;
        $admin = (int)$this->admin->id;
        $impersonated = $this->insert(['family' => Event::FAMILY_AUTH, 'event' => AuthWatcher::EVENT_IMPERSONATED, 'elementId' => $member, 'elementType' => User::class, 'actorId' => $admin, 'impersonatorId' => $admin]);
        $failed = $this->insert(['family' => Event::FAMILY_AUTH, 'event' => AuthWatcher::EVENT_LOGIN_FAILED, 'elementId' => $member, 'elementType' => User::class]);
        $groupSaved = $this->insert(['family' => Event::FAMILY_ACCESS, 'event' => AuthWatcher::EVENT_GROUP_SAVED, 'elementId' => null, 'elementType' => UserGroup::class, 'actorId' => $member]);
        $theirProfile = $this->insert(['elementId' => $member, 'elementType' => User::class, 'actorId' => $admin]);
        $asThem = $this->insert(['actorId' => $member, 'impersonatorId' => $admin]);
        $elsewhere = $this->insert(['actorId' => (int)$this->auditor->id]);

        $this->assertSame([$asThem, $groupSaved], $this->ids($this->member, Events::ACTIVITY_CHANGES));
        $this->assertSame([$failed, $impersonated], $this->ids($this->member, Events::ACTIVITY_ACCOUNT), 'Their profile saved is its History, not an account event');
        $this->assertSame([$theirProfile, $impersonated], $this->ids($this->admin, Events::ACTIVITY_CHANGES));

        foreach ([$impersonated, $failed, $groupSaved, $asThem] as $id) {
            $this->assertTrue($this->events->inActivity($this->events->get($id), $member), "Row $id is in their Activity");
        }
        foreach ([$theirProfile, $elsewhere] as $id) {
            $this->assertFalse($this->events->inActivity($this->events->get($id), $member), "Row $id is not");
        }
    }

    /**
     * BR-29 on each tab: paged by key and never counted, the older button naming its tab; an
     * unknown tab is refused.
     */
    public function testEachTabPagesByKey(): void
    {
        $ids = [];
        for ($i = 0; $i < 52; $i++) {
            $ids[] = $this->insert(['actorId' => (int)$this->member->id]);
        }
        $this->insert(['family' => Event::FAMILY_AUTH, 'event' => AuthWatcher::EVENT_LOGIN, 'elementId' => (int)$this->member->id]);

        $html = $this->screen($this->admin, $this->member);
        $this->assertSame(1, substr_count($html, 'data-auditor-older'), 'Only the changes tab has more');
        $this->assertStringContainsString('data-action="auditor/activity/page"', $html);
        $this->assertStringContainsString(htmlspecialchars('{"userId":' . $this->member->id . ',"tab":"changes"}'), $html);

        $first = $this->events->activity((int)$this->member->id, Events::ACTIVITY_CHANGES);
        $this->assertSame($ids[2], $first['after']);

        $logger = Yii::getLogger();
        Yii::setLogger(new Logger());
        try {
            $json = $this->as($this->admin, fn() => Craft::$app->runAction('auditor/activity/page', ['userId' => (int)$this->member->id, 'tab' => Events::ACTIVITY_CHANGES, 'after' => $first['after']]))->data;
            $queries = array_filter(Yii::getLogger()->messages, fn($message) => str_starts_with((string)$message[2], 'yii\db\Command::query'));
        } finally {
            Yii::setLogger($logger);
        }
        $this->assertSame(2, substr_count($json['html'], 'data-auditor-event='));
        $this->assertStringContainsString("data-auditor-event=\"{$ids[0]}\"", $json['html']);
        $this->assertNull($json['after']);
        foreach ($queries as $message) {
            $this->assertStringNotContainsStringIgnoringCase('count(', (string)$message[0]);
            $this->assertStringNotContainsStringIgnoringCase('offset', (string)$message[0]);
        }

        $this->assertRefused($this->admin, 'auditor/activity/page', ['userId' => (int)$this->member->id, 'tab' => 'everything', 'after' => 1], BadRequestHttpException::class, 'An unknown tab');
    }

    /**
     * A row's detail from Activity: only a row of this user's Activity, with the address hidden
     * (BR-27) from someone who may not see it.
     */
    public function testARowsDetailIsOnlyForThatUsersActivity(): void
    {
        $theirs = $this->insert(['actorId' => (int)$this->auditor->id, 'ip' => '203.0.113.9', 'data' => ['title' => ['label' => 'Title', 'old' => 'A', 'new' => '<b>B</b>']]]);
        $someoneElses = $this->insert(['actorId' => (int)$this->member->id]);
        $params = fn(int $id) => ['userId' => (int)$this->auditor->id, 'id' => $id];

        $detail = (string)$this->as($this->auditor, fn() => Craft::$app->runAction('auditor/activity/view', $params($theirs)))->data;
        $this->assertStringContainsString('data-auditor-field="title"', $detail);
        $this->assertStringContainsString('&lt;b&gt;B&lt;/b&gt;', $detail);
        $this->assertStringContainsString('data-auditor-ip>—</dd>', $detail);
        $this->assertStringNotContainsString('Everything recorded in this request', $detail, 'The log is not theirs to open');

        $this->assertRefused($this->auditor, 'auditor/activity/view', $params($someoneElses), NotFoundHttpException::class, 'Another user\'s row was shown');
    }

    /**
     * §3's first run: a user with no rows reads as having none since install, on both tabs.
     */
    public function testAUserWithNoRowsSaysSo(): void
    {
        $html = $this->screen($this->admin, $this->member);
        $this->assertSame(2, substr_count($html, 'data-auditor-empty'));
        $this->assertStringContainsString('No changes recorded since Auditor was installed.', $html);
        $this->assertStringContainsString('No account events recorded since Auditor was installed.', $html);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function routes(User $user, int $id): array
    {
        return [
            'auditor/activity/index' => ['userId' => (int)$user->id],
            'auditor/activity/page' => ['userId' => (int)$user->id, 'tab' => Events::ACTIVITY_ACCOUNT, 'after' => $id + 1],
            'auditor/activity/view' => ['userId' => (int)$user->id, 'id' => $id],
        ];
    }

    /**
     * The screens Craft offers `$viewer` on a user's edit screen, as its own `asEditUserScreen()`
     * gathers them.
     *
     * @return array<string, array<string, mixed>>
     */
    private function screens(User $viewer): array
    {
        $event = new DefineEditUserScreensEvent(['currentUser' => $viewer, 'editedUser' => $this->member, 'screens' => []]);
        YiiEvent::trigger(UsersController::class, UsersController::EVENT_DEFINE_EDIT_SCREENS, $event);

        return $event->screens;
    }

    /**
     * The Activity screen's content, as `$viewer` opens it for `$user` (their own with none).
     */
    private function screen(User $viewer, ?User $user): string
    {
        // The content renders when the screen is sent, so inside the request.
        return $this->as($viewer, fn() => $this->content(Craft::$app->runAction('auditor/activity/index', $user ? ['userId' => (int)$user->id] : [])));
    }

    private function content(mixed $response): string
    {
        $this->assertInstanceOf(Response::class, $response);
        /** @var CpScreenResponseBehavior|null $screen */
        $screen = $response->getBehavior(CpScreenResponseBehavior::NAME);
        $this->assertNotNull($screen, 'A control panel screen');
        $this->assertSame(['auditor-activity-changes', 'auditor-activity-account'], array_keys($screen->tabs));
        $content = $screen->contentHtml;

        return (string)(is_callable($content) ? $content() : $content);
    }

    /**
     * @return list<int>
     */
    private function ids(User $user, string $tab): array
    {
        /** @var Events::ACTIVITY_* $tab */
        return array_map(fn(Event $event) => (int)$event->id, $this->events->activity((int)$user->id, $tab)['events']);
    }

    /**
     * @param array<string, mixed> $params
     * @param class-string<\Throwable> $exception
     */
    private function assertRefused(User $user, string $route, array $params, string $exception, string $message): void
    {
        try {
            $this->as($user, fn() => Craft::$app->runAction($route, $params));
            $this->fail($message);
        } catch (\Throwable $e) {
            $this->assertInstanceOf($exception, $e, $message);
        }
    }

    /**
     * The permissions offered in the plugin's group, read from a fresh service: Craft's keeps the
     * list it built first.
     *
     * @return string[]
     */
    private function ourPermissions(): array
    {
        $permissions = (new UserPermissions())->getAllPermissions();
        $ours = array_filter($permissions, fn($group) => $group['heading'] === 'Auditor');

        return array_merge(...array_map(fn($group) => array_keys($group['permissions']), array_values($ours)));
    }

    private function as(User $user, Closure $callback): mixed
    {
        return $this->asWebRequest(function() use ($user, $callback) {
            Craft::$app->getUser()->setIdentity($user);
            try {
                return $callback();
            } finally {
                Craft::$app->getUser()->setIdentity(null);
            }
        }, cp: true);
    }

    /**
     * @param string[] $permissions
     */
    private function grant(User $user, array $permissions): void
    {
        Craft::$app->getUserPermissions()->saveUserPermissions((int)$user->id, $permissions);
    }

    /**
     * A row, inserted as the recorder would have written it.
     *
     * @param array<string, mixed> $attributes
     */
    private function insert(array $attributes): int
    {
        $db = Craft::$app->getDb();
        $db->createCommand()->insert(Table::EVENTS, $attributes + [
            'family' => Event::FAMILY_ELEMENT,
            'event' => ElementWatcher::EVENT_SAVED,
            'elementType' => Entry::class,
            'elementLabel' => 'Entry',
            'actorLabel' => Event::SYSTEM,
            'context' => 'cp',
            'requestId' => 'req-' . uniqid('', true),
            'dateCreated' => Db::prepareDateForDb(new DateTime()),
            'uid' => StringHelper::UUID(),
        ])->execute();

        return (int)$db->getLastInsertID();
    }
}
