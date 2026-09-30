<?php

namespace webdna\auditor\tests\integration;

use Closure;
use Codeception\Test\Unit;
use Craft;
use craft\auth\methods\TOTP;
use craft\console\controllers\UsersController as ConsoleUsersController;
use craft\controllers\UsersController;
use craft\db\Connection;
use craft\elements\User;
use craft\helpers\Session;
use craft\models\UserGroup;
use craft\records\Authenticator as AuthenticatorRecord;
use craft\records\WebAuthn as WebAuthnRecord;
use craft\services\ProjectConfig;
use craft\services\UserGroups;
use craft\services\UserPermissions;
use craft\services\Users;
use PragmaRX\Google2FA\Google2FA;
use webdna\auditor\Auditor;
use webdna\auditor\models\Event;
use webdna\auditor\services\Recorder;
use webdna\auditor\tests\Support\TestSite;
use webdna\auditor\watchers\AuthWatcher;

/**
 * The auth watcher's access half against Craft's own code: groups and permissions changed through
 * the control panel's permissions action (TS-4 step 1, BR-20), user groups saved and deleted, and
 * two-step verification and passkeys set up and removed (BR-21), with the hooks a Craft rename would
 * silence pinned (TN-16). TS-4 steps 2 and 3 are {@see AuthWatcherTest}'s.
 *
 * As in the other watcher tests, the recorder is swapped for one on a second connection, so the
 * rows are ready at once and are read from the buffer.
 */
class AccessWatcherTest extends Unit
{
    use SecondConnection;
    use WebRequests;

    private const PASSWORD = 'correct-horse-battery';

    private TestSite $site;

    private Auditor $plugin;

    private Recorder $recorder;

    private Connection $db;

    private User $admin;

    protected function _before(): void
    {
        $this->plugin = Auditor::getInstance();
        $this->site = TestSite::build();
        $this->admin = $this->site->user('admin', true);
        $this->admin->newPassword = self::PASSWORD;
        $this->site->save($this->admin);

        $this->db = $this->secondConnection();
        $this->clear();
    }

    protected function _after(): void
    {
        $this->db->close();
        $this->plugin->set('recorder', Recorder::class);
        Craft::$app->getUser()->setIdentity(null);
        Session::remove(Craft::$app->getUser()->impersonatorIdParam);
        TestSite::removeFiles();
    }

    /**
     * TS-4 step 1: `admin` takes `editor` out of `editors` on the permissions screen. One
     * `groupsChanged` row: subject `editor`, actor `admin`, `removed: [editors]`.
     */
    public function testRemovingSomeoneFromAGroupNamesBothPeople(): void
    {
        $editor = $this->site->user('editor');
        $editors = $this->group('editors');
        $members = $this->group('members');
        Craft::$app->getUsers()->assignUserToGroups((int)$editor->id, [$editors->id, $members->id]);
        $this->clear();

        $this->asAdmin(fn() => $this->post('users/save-permissions', ['userId' => $editor->id, 'groups' => [$members->id]]));

        $rows = $this->rows(AuthWatcher::EVENT_GROUPS_CHANGED);
        $this->assertCount(1, $rows);
        $this->assertSame(Event::FAMILY_ACCESS, $rows[0]->family);
        $this->assertSame((int)$editor->id, $rows[0]->elementId, 'The subject is the user whose groups changed');
        $this->assertSame((int)$this->admin->id, $rows[0]->actorId, 'The actor is who changed them');
        $this->assertSame(['added' => [], 'removed' => ['editors']], $rows[0]->data);

        // Saving the screen again with the same groups changes nothing.
        $this->clear();
        $this->asAdmin(fn() => $this->post('users/save-permissions', ['userId' => $editor->id, 'groups' => [$members->id]]));
        $this->assertSame([], $this->rows(AuthWatcher::EVENT_GROUPS_CHANGED));
    }

    /**
     * BR-20: the permissions screen reads the old set first, so the row has both, and saving it
     * unchanged writes nothing; a change made any other way has only the new set and is `partial`.
     */
    public function testAChangeOfPermissionsKeepsTheOldSetWhenItCouldBeRead(): void
    {
        $editor = $this->site->user('editor');
        Craft::$app->getUserPermissions()->saveUserPermissions((int)$editor->id, ['accessCp']);
        $this->clear();

        $this->asAdmin(fn() => $this->post('users/save-permissions', ['userId' => $editor->id, 'permissions' => ['accessCp', 'utility:system-report']]));

        $rows = $this->rows(AuthWatcher::EVENT_PERMISSIONS_CHANGED);
        $this->assertCount(1, $rows);
        $this->assertSame(Event::FAMILY_ACCESS, $rows[0]->family);
        $this->assertSame((int)$editor->id, $rows[0]->elementId);
        $this->assertSame((int)$this->admin->id, $rows[0]->actorId);
        $this->assertSame(['old' => ['accesscp'], 'new' => ['accesscp', 'utility:system-report']], $rows[0]->data);

        $this->clear();
        $this->asAdmin(fn() => $this->post('users/save-permissions', ['userId' => $editor->id, 'permissions' => ['accessCp', 'utility:system-report']]));
        $this->assertSame([], $this->rows(AuthWatcher::EVENT_PERMISSIONS_CHANGED), 'Saved unchanged');

        Craft::$app->getUser()->setIdentity(null);
        Craft::$app->getUserPermissions()->saveUserPermissions((int)$editor->id, []);
        $rows = $this->rows(AuthWatcher::EVENT_PERMISSIONS_CHANGED);
        $this->assertCount(1, $rows);
        $this->assertSame(['new' => [], 'partial' => true], $rows[0]->data, 'Nothing read the old set');
        $this->assertSame(Event::SYSTEM, $rows[0]->actorLabel);
    }

    /**
     * BR-20: a new group is one `groupSaved` row, its permissions included although Craft saves them
     * afterwards; changing its name and permissions is one row with both; a save that changes nothing
     * writes nothing; deleting it is one `groupDeleted` row.
     */
    public function testAUserGroupsSavesAndDeletionAreRecorded(): void
    {
        $groups = Craft::$app->getUserGroups();
        $permissions = Craft::$app->getUserPermissions();

        $group = new UserGroup(['name' => 'Reviewers', 'handle' => 'reviewers']);
        $this->assertTrue($groups->saveGroup($group));
        $permissions->saveGroupPermissions((int)$group->id, ['accessCp']);

        $rows = $this->rows(AuthWatcher::EVENT_GROUP_SAVED);
        $this->assertCount(1, $rows, 'One row for the group and its permissions');
        $this->assertSame(Event::FAMILY_ACCESS, $rows[0]->family);
        $this->assertNull($rows[0]->elementId, 'A group is not an element');
        $this->assertSame(UserGroup::class, $rows[0]->elementType);
        $this->assertSame('Reviewers', $rows[0]->elementLabel);
        $this->assertSame([
            'group' => ['id' => $group->id, 'handle' => 'reviewers', 'name' => 'Reviewers'],
            'isNew' => true,
            'changes' => ['name' => ['old' => null, 'new' => 'Reviewers'], 'handle' => ['old' => null, 'new' => 'reviewers']],
            'permissions' => ['added' => ['accesscp'], 'removed' => []],
        ], $rows[0]->data);

        $this->clear();
        $group->name = 'Proofreaders';
        $this->assertTrue($groups->saveGroup($group));
        $permissions->saveGroupPermissions((int)$group->id, ['utility:system-report']);

        $rows = $this->rows(AuthWatcher::EVENT_GROUP_SAVED);
        $this->assertCount(1, $rows);
        $this->assertSame([
            'changes' => ['name' => ['old' => 'Reviewers', 'new' => 'Proofreaders']],
            'permissions' => ['added' => ['utility:system-report'], 'removed' => ['accesscp']],
        ], array_diff_key($rows[0]->data ?? [], ['group' => true]));

        $this->clear();
        $this->assertTrue($groups->saveGroup($group));
        $permissions->saveGroupPermissions((int)$group->id, ['utility:system-report']);
        $this->assertSame([], $this->recorder->getReady(), 'Saved unchanged');

        $this->assertTrue($groups->deleteGroup($group));
        $rows = $this->rows(AuthWatcher::EVENT_GROUP_DELETED);
        $this->assertCount(1, $rows);
        $this->assertSame('Proofreaders', $rows[0]->elementLabel);
        $this->assertSame(['group' => ['id' => $group->id, 'handle' => 'reviewers', 'name' => 'Proofreaders']], $rows[0]->data);
        $this->assertSame([], $this->rows(AuthWatcher::EVENT_GROUP_SAVED));
    }

    /**
     * BR-21: setting up the authenticator app is `mfaEnabled` once the first code is verified, and
     * not before; signing in with it later writes nothing; removing it through the control panel's
     * action is `mfaDisabled`, and so is removing it with `users/remove-2fa`.
     */
    public function testTwoStepVerificationBeingSetUpAndRemovedIsRecorded(): void
    {
        $member = $this->member('member');
        $totp = Craft::$app->getAuth()->getMethod(TOTP::class, $member);
        $google2fa = new Google2FA();
        $secret = $google2fa->generateSecretKey();

        $this->asWebRequest(function() use ($member, $totp, $google2fa, $secret) {
            $this->assertTrue(Craft::$app->getUser()->login($member));
            $this->clear();
            Craft::$app->getSession()->set($totp->secretParam, $secret);

            $this->assertFalse($totp->verify('000000'), 'A wrong code');
            $this->assertSame([], $this->rows(AuthWatcher::EVENT_MFA_ENABLED), 'Nothing is set up until a code is right');

            $this->assertTrue($totp->verify($google2fa->getCurrentOtp($secret)));
        });

        $rows = $this->rows(AuthWatcher::EVENT_MFA_ENABLED);
        $this->assertCount(1, $rows);
        $this->assertSame(Event::FAMILY_AUTH, $rows[0]->family);
        $this->assertSame((int)$member->id, $rows[0]->elementId);
        $this->assertSame((int)$member->id, $rows[0]->actorId);
        $this->assertSame(['method' => TOTP::class], $rows[0]->data);

        // A later sign-in with it only moves the record's timestamp.
        $this->clear();
        $record = AuthenticatorRecord::findOne(['userId' => $member->id]);
        $this->assertNotNull($record);
        $record->oldTimestamp = (int)$record->oldTimestamp + 1;
        $record->save();
        $this->assertSame([], $this->recorder->getReady());

        $this->asWebRequest(function() use ($member) {
            $this->assertTrue(Craft::$app->getUser()->login($member));
            $this->clear();
            $this->post('auth/remove-method', ['method' => TOTP::class]);
        }, cp: true);

        $rows = $this->rows(AuthWatcher::EVENT_MFA_DISABLED);
        $this->assertCount(1, $rows);
        $this->assertSame((int)$member->id, $rows[0]->elementId);
        $this->assertSame((int)$member->id, $rows[0]->actorId);
        $this->assertSame(['method' => TOTP::class], $rows[0]->data);
        $this->assertNull(AuthenticatorRecord::findOne(['userId' => $member->id]), 'Craft removed it');

        // The console command, for someone else, signed in as nobody.
        Craft::$app->getUser()->setIdentity(null);
        (new AuthenticatorRecord(['userId' => $member->id, 'auth2faSecret' => $secret, 'oldTimestamp' => 0]))->save();
        $this->clear();
        $console = new ConsoleUsersController('users', Craft::$app);
        $console->interactive = false;
        ob_start();
        try {
            $console->runAction('remove-2fa', [(string)$member->id]);
        } finally {
            ob_end_clean();
        }

        $rows = $this->rows(AuthWatcher::EVENT_MFA_DISABLED);
        $this->assertCount(1, $rows);
        $this->assertSame((int)$member->id, $rows[0]->elementId);
        $this->assertSame(Event::SYSTEM, $rows[0]->actorLabel);
    }

    /**
     * BR-21: a passkey is `passkeyAdded` when Craft stores it, using one to sign in writes nothing,
     * and deleting it through the control panel's action is `passkeyRemoved`.
     */
    public function testPasskeysBeingAddedAndRemovedAreRecorded(): void
    {
        $member = $this->member('member');

        $record = $this->asWebRequest(function() use ($member) {
            $this->assertTrue(Craft::$app->getUser()->login($member));
            $this->clear();

            // What `CredentialRepository::savedNamedCredentialSource()` writes once Craft has verified
            // a new credential; a real attestation cannot be made in a test.
            $record = new WebAuthnRecord([
                'userId' => $member->id,
                'credentialId' => 'test-credential',
                'credential' => '{}',
                'credentialName' => 'Work laptop',
                'dateLastUsed' => '2026-09-30 12:00:00',
            ]);
            $this->assertTrue($record->save());

            return $record;
        });

        $rows = $this->rows(AuthWatcher::EVENT_PASSKEY_ADDED);
        $this->assertCount(1, $rows);
        $this->assertSame(Event::FAMILY_AUTH, $rows[0]->family);
        $this->assertSame((int)$member->id, $rows[0]->elementId);
        $this->assertSame((int)$member->id, $rows[0]->actorId);
        $this->assertSame(['name' => 'Work laptop'], $rows[0]->data);

        $this->clear();
        $record->dateLastUsed = '2026-09-30 13:00:00';
        $record->save();
        $this->assertSame([], $this->recorder->getReady(), 'Signing in with it is not an addition');

        $this->asWebRequest(function() use ($member, $record) {
            $this->assertTrue(Craft::$app->getUser()->login($member));
            $this->clear();
            $this->post('auth/delete-passkey', ['uid' => $record->uid]);
        }, cp: true);

        $rows = $this->rows(AuthWatcher::EVENT_PASSKEY_REMOVED);
        $this->assertCount(1, $rows);
        $this->assertSame((int)$member->id, $rows[0]->elementId);
        $this->assertSame(['name' => 'Work laptop'], $rows[0]->data);
        $this->assertNull(WebAuthnRecord::findOne(['uid' => $record->uid]), 'Craft deleted it');
    }

    /**
     * TN-16: the watcher hears two-step removal by action name, and the rest by names of Craft's
     * events and classes. Each must still be Craft's, or the watcher goes quiet without an error.
     */
    public function testTheActionsAndEventsListenedForAreCraftsOwn(): void
    {
        foreach (AuthWatcher::MFA_REMOVAL_ACTIONS as $class => $id) {
            $controller = new $class('pin', Craft::$app);
            $this->assertNotNull($controller->createAction($id), "$class still has the action $id");
        }

        $this->assertSame('afterAssignUserToGroups', Users::EVENT_AFTER_ASSIGN_USER_TO_GROUPS);
        $this->assertSame('beforeAssignGroupsAndPermissions', UsersController::EVENT_BEFORE_ASSIGN_GROUPS_AND_PERMISSIONS);
        $this->assertSame('afterSaveUserPermissions', UserPermissions::EVENT_AFTER_SAVE_USER_PERMISSIONS);
        $this->assertSame('afterDeleteUserGroup', UserGroups::EVENT_AFTER_DELETE_USER_GROUP);
        $this->assertSame('users.groups', ProjectConfig::PATH_USER_GROUPS);
        $this->assertSame('{{%authenticator}}', AuthenticatorRecord::tableName());
        $this->assertSame('{{%webauthn}}', WebAuthnRecord::tableName());
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
        // The control panel's own account screens, where two-step and passkeys are managed.
        Craft::$app->getUserPermissions()->saveUserPermissions((int)$user->id, ['accessCp']);

        return $user;
    }

    private function group(string $handle): UserGroup
    {
        $group = Craft::$app->getUserGroups()->getGroupByHandle($handle);
        $this->assertNotNull($group);

        return $group;
    }

    /**
     * Signs `admin` in on a control-panel request — which also makes the session elevated, as
     * Craft's permission actions require — and runs the callback.
     */
    private function asAdmin(Closure $callback): void
    {
        $this->asWebRequest(function() use ($callback) {
            $this->assertTrue(Craft::$app->getUser()->login($this->admin), 'Signed in as admin');
            $this->clear();
            $callback();
        }, cp: true);
    }

    /**
     * @return Event[]
     * @phpstan-impure
     */
    private function rows(string $event): array
    {
        return array_values(array_filter($this->recorder->getReady(), fn(Event $row) => $row->event === $event));
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
