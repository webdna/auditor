<?php

namespace webdna\auditor\tests\integration;

use Codeception\Test\Unit;
use Craft;
use craft\controllers\UsersController;
use craft\db\Connection;
use craft\elements\User;
use craft\helpers\Session;
use craft\services\Users;
use craft\web\User as WebUser;
use RuntimeException;
use webdna\auditor\Auditor;
use webdna\auditor\models\Event;
use webdna\auditor\services\Differ;
use webdna\auditor\services\Recorder;
use webdna\auditor\tests\Support\TestSite;
use webdna\auditor\watchers\AuthWatcher;
use webdna\auditor\watchers\ElementWatcher;
use Yii;
use yii\log\Logger;
use yii\web\UserEvent;

/**
 * The auth watcher against Craft's own sign-in and account code: failed sign-ins through the real
 * login action, and the lock-out they end in (TS-3 steps 1-3); sign-in, sign-out and signing in as
 * someone else (BR-16, BR-22); a remember-me cookie (TN-10); the changes of standing that save no
 * user (BR-18); and the password, admin and email changes read from the user record (BR-19).
 *
 * As in the element watcher's tests, the recorder is swapped for one on a second connection, so
 * the rows are ready at once and are read from the buffer.
 */
class AuthWatcherTest extends Unit
{
    use SecondConnection;
    use WebRequests;

    private const PASSWORD = 'correct-horse-battery';

    private TestSite $site;

    private Auditor $plugin;

    private Recorder $recorder;

    private Connection $db;

    /**
     * @var array<string, mixed>
     */
    private array $settings;

    protected function _before(): void
    {
        $this->plugin = Auditor::getInstance();
        $this->settings = $this->plugin->getSettings()->toArray();
        $this->site = TestSite::build();

        $this->db = $this->secondConnection();
        $this->recorder = new Recorder(['db' => $this->db]);
        $this->plugin->set('recorder', $this->recorder);
    }

    protected function _after(): void
    {
        $this->db->close();
        $this->plugin->set('recorder', Recorder::class);
        $this->plugin->set('differ', Differ::class);
        $this->plugin->getSettings()->setAttributes($this->settings, false);
        Craft::$app->getUser()->setIdentity(null);
        Session::remove(Craft::$app->getUser()->impersonatorIdParam);
        TestSite::removeFiles();
    }

    /**
     * TS-3 steps 1-3: three bad passwords for `member` are three `loginFailed` rows against them;
     * carrying on until Craft locks the account adds one `locked` row; a bad password for an address
     * nobody has is recorded with what was typed and no subject. The password is never kept.
     */
    public function testFailedSignInsAndTheLockOutAreRecorded(): void
    {
        $member = $this->member('member');
        $max = Craft::$app->getConfig()->getGeneral()->maxInvalidLogins;
        $this->assertGreaterThan(3, $max, 'The lock-out needs more than three attempts');

        for ($i = 0; $i < 3; $i++) {
            $this->postLogin('member', 'wrong-password');
        }

        $rows = $this->rows(AuthWatcher::EVENT_LOGIN_FAILED);
        $this->assertCount(3, $rows);
        foreach ($rows as $row) {
            $this->assertSame(Event::FAMILY_AUTH, $row->family);
            $this->assertSame((int)$member->id, $row->elementId);
            $this->assertSame(User::class, $row->elementType);
            $this->assertSame((int)$member->id, $row->actorId, 'The attempt reads under the account it was made against');
            $this->assertSame(['username' => 'member', 'reason' => User::AUTH_INVALID_CREDENTIALS], $row->data);
            $this->assertStringNotContainsString('wrong-password', (string)json_encode($row), 'The password is never kept');
        }
        $this->assertSame([], $this->rows(AuthWatcher::EVENT_LOCKED));

        for ($i = 3; $i < $max; $i++) {
            $this->postLogin('member', 'wrong-password');
        }

        $locked = $this->rows(AuthWatcher::EVENT_LOCKED);
        $this->assertCount(1, $locked, 'One lock-out');
        $this->assertSame((int)$member->id, $locked[0]->elementId);
        $this->assertNull($locked[0]->actorId, 'Craft locked it, nobody signed in');
        $this->assertSame(Event::SYSTEM, $locked[0]->actorLabel);
        $this->assertCount($max, $this->rows(AuthWatcher::EVENT_LOGIN_FAILED), 'The locking attempt is itself recorded');

        // A site that hides lock-outs from visitors tells them the password was wrong; the log keeps
        // what Craft actually refused them for.
        $general = Craft::$app->getConfig()->getGeneral();
        $prevent = $general->preventUserEnumeration;
        $general->preventUserEnumeration = true;
        try {
            $this->postLogin('member', self::PASSWORD);
        } finally {
            $general->preventUserEnumeration = $prevent;
        }
        $rows = $this->rows(AuthWatcher::EVENT_LOGIN_FAILED);
        $this->assertContains(end($rows)->data['reason'] ?? null, [User::AUTH_ACCOUNT_LOCKED, User::AUTH_ACCOUNT_COOLDOWN]);

        $this->postLogin('nobody@example.com', 'wrong-password');
        $rows = $this->rows(AuthWatcher::EVENT_LOGIN_FAILED);
        $unknown = end($rows);
        $this->assertInstanceOf(Event::class, $unknown);
        $this->assertNull($unknown->elementId, 'No subject for an account that does not exist');
        $this->assertNull($unknown->actorId);
        $this->assertSame(Event::UNKNOWN_USER, $unknown->actorLabel, 'Not "System": someone typed it');
        $this->assertSame(['username' => 'nobody@example.com', 'reason' => User::AUTH_INVALID_CREDENTIALS], $unknown->data);
    }

    /**
     * BR-16: a sign-in is one `login` row, the user both subject and actor, and signing out is one
     * `logout` row written while they are still signed in.
     */
    public function testASignInAndASignOutAreRecorded(): void
    {
        $member = $this->member('member');

        $this->asWebRequest(function() use ($member) {
            $webUser = Craft::$app->getUser();
            $this->assertTrue($webUser->login($member), 'Signed in');
            $webUser->logout(false);
        });

        $login = $this->rows(AuthWatcher::EVENT_LOGIN);
        $this->assertCount(1, $login);
        $this->assertSame((int)$member->id, $login[0]->elementId);
        $this->assertSame((int)$member->id, $login[0]->actorId);
        $this->assertSame('member@example.com', $login[0]->actorLabel);
        $this->assertNull($login[0]->impersonatorId);
        $this->assertNull($login[0]->data);
        $this->assertSame('192.0.2.1', $login[0]->ip, 'A web row keeps the address');

        $logout = $this->rows(AuthWatcher::EVENT_LOGOUT);
        $this->assertCount(1, $logout);
        $this->assertSame((int)$member->id, $logout[0]->elementId);
        $this->assertSame((int)$member->id, $logout[0]->actorId);
    }

    /**
     * TN-10: a remember-me cookie signing someone back in writes nothing by default, and a `login`
     * marked as the cookie's once `recordCookieLogins` is on (BR-16).
     */
    public function testACookieSignInIsRecordedOnlyWhenAskedFor(): void
    {
        $member = $this->member('member');
        $this->clear();
        $cookie = fn() => $this->asWebRequest(fn() => Craft::$app->getUser()->trigger(
            WebUser::EVENT_AFTER_LOGIN,
            new UserEvent(['identity' => $member, 'cookieBased' => true, 'duration' => 3600]),
        ));

        $cookie();
        $this->assertSame([], $this->recorder->getReady(), 'No row by default');

        $this->plugin->getSettings()->recordCookieLogins = true;
        $cookie();
        $rows = $this->rows(AuthWatcher::EVENT_LOGIN);
        $this->assertCount(1, $rows);
        $this->assertSame(['cookie' => true], $rows[0]->data);
    }

    /**
     * BR-22: an admin signing in as `member` is one `impersonated` row — subject `member`, actor the
     * admin — and what `member` then changes names `member` as the actor and the admin as the
     * impersonator.
     */
    public function testSigningInAsSomeoneNamesBothPeople(): void
    {
        $admin = $this->site->user('admin', true);
        $member = $this->member('member');
        $this->clear();

        $this->asWebRequest(function() use ($admin, $member) {
            $webUser = Craft::$app->getUser();
            $webUser->setIdentity($admin);
            $webUser->setImpersonatorId((int)$admin->id);
            $this->assertTrue($webUser->login($member), 'Signed in as member');

            $member->firstName = 'Changed';
            $this->site->save($member);
        });

        $this->assertSame([], $this->rows(AuthWatcher::EVENT_LOGIN), 'Signing in as someone is not their own sign-in');
        $rows = $this->rows(AuthWatcher::EVENT_IMPERSONATED);
        $this->assertCount(1, $rows);
        $this->assertSame((int)$member->id, $rows[0]->elementId);
        $this->assertSame((int)$admin->id, $rows[0]->actorId);
        $this->assertSame('admin@example.com', $rows[0]->actorLabel);
        $this->assertSame((int)$admin->id, $rows[0]->impersonatorId);

        $saved = $this->rows(ElementWatcher::EVENT_SAVED);
        $this->assertCount(1, $saved);
        $this->assertSame((int)$member->id, $saved[0]->actorId);
        $this->assertSame((int)$admin->id, $saved[0]->impersonatorId);
    }

    /**
     * BR-18: suspending, reinstating, deactivating, activating and unlocking save no user, and each
     * is a row about the user, by the admin who did it.
     */
    public function testChangesOfStandingAreRecordedAgainstTheUser(): void
    {
        $admin = $this->site->user('admin', true);
        $member = $this->member('member');
        $this->clear();

        $users = Craft::$app->getUsers();
        $this->asWebRequest(function() use ($users, $admin, $member) {
            Craft::$app->getUser()->setIdentity($admin);
            $users->suspendUser($member);
            $users->unsuspendUser($member);
            $users->deactivateUser($member);
            $users->activateUser($member);
            $member->locked = true;
            $users->unlockUser($member);
        });

        $rows = $this->recorder->getReady();
        $this->assertSame([
            AuthWatcher::EVENT_SUSPENDED,
            AuthWatcher::EVENT_UNSUSPENDED,
            AuthWatcher::EVENT_DEACTIVATED,
            AuthWatcher::EVENT_ACTIVATED,
            AuthWatcher::EVENT_UNLOCKED,
        ], array_map(fn(Event $row) => $row->event, $rows));
        foreach ($rows as $row) {
            $this->assertSame(Event::FAMILY_AUTH, $row->family);
            $this->assertSame((int)$member->id, $row->elementId, 'The subject is the user it happened to');
            $this->assertSame((int)$admin->id, $row->actorId, 'The actor is who did it');
        }
    }

    /**
     * BR-19: a new password and a required reset are rows with no value at all; making someone an
     * admin is an `access` row as well as a line of their save's diff; a new email address is an
     * `auth` row as well as a line of the diff.
     */
    public function testAccountChangesAreTheirOwnRows(): void
    {
        $member = $this->member('member');
        $this->clear();

        $member->newPassword = 'another-horse-battery';
        $this->site->save($member);
        $this->assertSame([AuthWatcher::EVENT_PASSWORD_CHANGED], $this->events(), 'A password change, and no diff of it');
        $this->assertNull($this->recorder->getReady()[0]->data);
        $this->clear();

        $member->passwordResetRequired = true;
        $this->site->save($member);
        $this->assertSame([AuthWatcher::EVENT_PASSWORD_RESET_REQUIRED], array_values(array_diff($this->events(), [ElementWatcher::EVENT_SAVED])));
        $this->assertNull($this->rows(AuthWatcher::EVENT_PASSWORD_RESET_REQUIRED)[0]->data);
        $this->clear();

        $member->admin = true;
        $member->email = 'member@example.org';
        $this->site->save($member);

        $admin = $this->rows(AuthWatcher::EVENT_ADMIN_CHANGED);
        $this->assertCount(1, $admin);
        $this->assertSame(Event::FAMILY_ACCESS, $admin[0]->family);
        $this->assertSame((int)$member->id, $admin[0]->elementId);
        $this->assertSame(['admin' => ['old' => false, 'new' => true]], $admin[0]->data);

        $email = $this->rows(AuthWatcher::EVENT_EMAIL_CHANGED);
        $this->assertCount(1, $email);
        $this->assertSame(Event::FAMILY_AUTH, $email[0]->family);
        $this->assertSame(['email' => ['old' => 'member@example.com', 'new' => 'member@example.org']], $email[0]->data);

        $saved = $this->rows(ElementWatcher::EVENT_SAVED);
        $this->assertCount(1, $saved);
        $this->assertArrayHasKey('admin', $saved[0]->data ?? []);
        $this->assertArrayHasKey('email', $saved[0]->data ?? []);
    }

    /**
     * BR-18, BR-19: verifying a pending address — which Craft does without saving the user, and
     * without an event of its own — is an `emailVerified` row and an `emailChanged` row.
     */
    public function testVerifyingAnAddressIsRecorded(): void
    {
        $member = $this->member('member');
        $member->unverifiedEmail = 'new@example.com';
        $this->site->save($member);
        $this->clear();

        Craft::$app->getUsers()->verifyEmailForUser($member);

        $this->assertSame([AuthWatcher::EVENT_EMAIL_CHANGED, AuthWatcher::EVENT_EMAIL_VERIFIED], $this->events());
        $this->assertSame(['email' => 'new@example.com'], $this->rows(AuthWatcher::EVENT_EMAIL_VERIFIED)[0]->data);
    }

    /**
     * Creating an account inserts its record, so none of the account-change rows is written for it:
     * its `created` row says what it was created with.
     */
    public function testANewAccountWritesNoAccountChangeRows(): void
    {
        $this->member('member');

        $this->assertSame([ElementWatcher::EVENT_CREATED], $this->events());
    }

    /**
     * BR-2: a failure inside the watcher is logged, and the sign-in goes ahead.
     */
    public function testAFailureInTheWatcherNeverStopsASignIn(): void
    {
        $member = $this->member('member');
        $this->clear();
        $this->plugin->set('differ', new class() extends Differ {
            public function redact(array $payload): array
            {
                throw new RuntimeException('redact broke');
            }
        });

        $harness = Yii::getLogger();
        $logger = new Logger();
        Yii::setLogger($logger);
        try {
            $this->postLogin('member', 'wrong-password');
            $this->asWebRequest(fn() => $this->assertTrue(Craft::$app->getUser()->login($member), 'Signed in'));
        } finally {
            Yii::setLogger($harness);
        }

        $this->assertSame([AuthWatcher::EVENT_LOGIN], $this->events(), 'Only the failed sign-in, which needed the broken part, is missing');
        $logged = array_filter($logger->messages, fn(array $m) => $m[2] === Auditor::HANDLE && str_contains((string)$m[0], 'redact broke'));
        $this->assertNotEmpty($logged);
    }

    /**
     * The event names the watcher listens for are Craft's; a rename would silence it without an error.
     */
    public function testTheEventsListenedForAreCraftsOwn(): void
    {
        $this->assertSame('loginFailure', UsersController::EVENT_LOGIN_FAILURE);
        $this->assertSame('afterLockUser', Users::EVENT_AFTER_LOCK_USER);
        $this->assertSame('afterLogin', WebUser::EVENT_AFTER_LOGIN);
        $this->assertSame('beforeLogout', WebUser::EVENT_BEFORE_LOGOUT);
    }

    private function member(string $username): User
    {
        $user = new User([
            'username' => $username,
            'email' => "$username@example.com",
            'newPassword' => self::PASSWORD,
            'active' => true,
        ]);
        $this->site->save($user);

        return $user;
    }

    /**
     * Posts the login form through Craft's own action, as a browser would.
     */
    private function postLogin(string $loginName, string $password): void
    {
        $this->asWebRequest(fn() => $this->post('users/login', ['loginName' => $loginName, 'password' => $password]));
    }

    /**
     * @return Event[]
     */
    private function rows(string $event): array
    {
        return array_values(array_filter($this->recorder->getReady(), fn(Event $row) => $row->event === $event));
    }

    /**
     * @return string[]
     */
    private function events(): array
    {
        return array_map(fn(Event $row) => $row->event, $this->recorder->getReady());
    }

    /**
     * Starts the buffer afresh, as a new request would.
     */
    private function clear(): void
    {
        $this->recorder = new Recorder(['db' => $this->db]);
        $this->plugin->set('recorder', $this->recorder);
    }
}
