<?php

namespace webdna\auditor\watchers;

use Craft;
use craft\auth\methods\TOTP;
use craft\console\controllers\UsersController as ConsoleUsersController;
use craft\controllers\AuthController;
use craft\controllers\UsersController;
use craft\db\Query;
use craft\db\Table;
use craft\elements\User;
use craft\events\ConfigEvent;
use craft\events\ElementEvent;
use craft\events\LoginFailureEvent;
use craft\events\UserEvent;
use craft\events\UserGroupEvent;
use craft\events\UserGroupsAssignEvent;
use craft\events\UserPermissionsEvent;
use craft\helpers\ElementHelper;
use craft\helpers\Session;
use craft\models\UserGroup;
use craft\records\Authenticator as AuthenticatorRecord;
use craft\records\User as UserRecord;
use craft\records\WebAuthn as WebAuthnRecord;
use craft\services\Elements;
use craft\services\ProjectConfig;
use craft\services\UserGroups;
use craft\services\UserPermissions;
use craft\services\Users;
use craft\web\Request;
use craft\web\User as WebUser;
use Throwable;
use webdna\auditor\Auditor;
use webdna\auditor\models\Event;
use yii\base\ActionEvent;
use yii\base\Controller;
use yii\base\Event as YiiEvent;
use yii\db\AfterSaveEvent;
use yii\db\BaseActiveRecord;
use yii\web\UserEvent as WebUserEvent;

/**
 * Turns sign-ins, changes to an account's standing, and changes to who may do what into rows
 * (BR-16 to BR-22).
 *
 * The subject is always the user the row is about and the actor whoever did it (BR-22): a user
 * signing in is both, an administrator suspending someone is the actor of the other's row, and an
 * account Craft locks after too many failed sign-ins is locked by "System".
 *
 * Where each comes from:
 *
 * - `login`, `impersonated` and `logout` from the user component, so a sign-in by any route —
 *   password, passkey, two-step code, a plugin's own — is recorded. A sign-in made while an
 *   impersonator is in the session is `impersonated`, with the impersonator as its actor; the rows
 *   that follow in that session carry `impersonatorId` because the recorder adds it to every row.
 *   A remember-me cookie signing someone back in is recorded only when `recordCookieLogins` is on.
 * - `loginFailed` from the users controller's failure event, the only place Craft reports one.
 * - `locked`, `unlocked`, `suspended`, `unsuspended`, `activated` and `deactivated` from the users
 *   service, because none of them saves the user (BR-18).
 * - `passwordChanged`, `passwordResetRequired`, `adminChanged`, `emailChanged` and `emailVerified`
 *   from the user **record's** update, which carries the old value of every column it changed.
 *   Every one of them passes through it, whatever wrote it — a user save, a console command,
 *   `Users::verifyEmailForUser()` (which fires no event of its own; the service's
 *   `afterVerifyEmail` fires when any verification link is opened, a password reset's included, not
 *   when an address is verified). A new user's record is inserted, not updated, so creating an
 *   account writes none of these; its `created` row says what it was created with.
 * - `groupsChanged` from the users service's group assignment, which fires only when a group was
 *   added or removed, with both lists.
 * - `permissionsChanged` from the permissions service, whose only event comes after the old set is
 *   gone. The control panel's permissions screen announces itself first, so the old set is read
 *   there; any other caller's change is marked `partial` (BR-20).
 * - `groupSaved` from project config, whose change events carry the old value — for a group, and
 *   separately for its permissions, which Craft saves after it. Both halves of one save land in the
 *   same row. Project config is also how a deploy changes a group, so an applied change is recorded
 *   too. `groupDeleted` from the user-groups service, which still has the group.
 * - `mfaEnabled`, `passkeyAdded` and `passkeyRemoved` from Craft's authenticator and passkey
 *   records, which are written only once Craft has verified the code or the credential (BR-21). A
 *   passkey sign-in updates its record and is not an addition.
 * - `mfaDisabled` from the two actions that remove two-step methods — the control panel's and the
 *   `users/remove-2fa` command — by comparing who has an authenticator before and after, because
 *   the removal is a bulk delete that fires nothing. These are the hooks a Craft rename would
 *   silence, and a test pins them (TN-16).
 *
 * Nothing here queries until one of these events has happened (BR-34). Every listener is
 * class-level and swallows its own failures (BR-2).
 *
 * @since 1.0.0
 */
class AuthWatcher
{
    public const EVENT_LOGIN = 'login';
    public const EVENT_LOGIN_FAILED = 'loginFailed';
    public const EVENT_LOGOUT = 'logout';
    public const EVENT_IMPERSONATED = 'impersonated';
    public const EVENT_LOCKED = 'locked';
    public const EVENT_UNLOCKED = 'unlocked';
    public const EVENT_SUSPENDED = 'suspended';
    public const EVENT_UNSUSPENDED = 'unsuspended';
    public const EVENT_ACTIVATED = 'activated';
    public const EVENT_DEACTIVATED = 'deactivated';
    public const EVENT_EMAIL_VERIFIED = 'emailVerified';
    public const EVENT_EMAIL_CHANGED = 'emailChanged';
    public const EVENT_PASSWORD_CHANGED = 'passwordChanged';
    public const EVENT_PASSWORD_RESET_REQUIRED = 'passwordResetRequired';
    public const EVENT_ADMIN_CHANGED = 'adminChanged';
    public const EVENT_GROUPS_CHANGED = 'groupsChanged';
    public const EVENT_PERMISSIONS_CHANGED = 'permissionsChanged';
    public const EVENT_GROUP_SAVED = 'groupSaved';
    public const EVENT_GROUP_DELETED = 'groupDeleted';
    public const EVENT_MFA_ENABLED = 'mfaEnabled';
    public const EVENT_MFA_DISABLED = 'mfaDisabled';
    public const EVENT_PASSKEY_ADDED = 'passkeyAdded';
    public const EVENT_PASSKEY_REMOVED = 'passkeyRemoved';

    /**
     * The actions that remove two-step methods, as controller class => action id. Craft's removal
     * fires no event, so these are listened to by name, and a test pins each (TN-16).
     */
    public const MFA_REMOVAL_ACTIONS = [
        AuthController::class => 'remove-method',
        ConsoleUsersController::class => 'remove-2fa',
    ];

    /**
     * The group attributes a `groupSaved` row compares; its permissions are compared as sets.
     */
    private const GROUP_ATTRIBUTES = ['name', 'handle', 'description'];

    /**
     * The users service's events for changes of standing that write the `users` table without
     * saving the user (BR-18), and the row each becomes.
     */
    private const STANDING = [
        Users::EVENT_AFTER_LOCK_USER => self::EVENT_LOCKED,
        Users::EVENT_AFTER_UNLOCK_USER => self::EVENT_UNLOCKED,
        Users::EVENT_AFTER_SUSPEND_USER => self::EVENT_SUSPENDED,
        Users::EVENT_AFTER_UNSUSPEND_USER => self::EVENT_UNSUSPENDED,
        Users::EVENT_AFTER_ACTIVATE_USER => self::EVENT_ACTIVATED,
        Users::EVENT_AFTER_DEACTIVATE_USER => self::EVENT_DEACTIVATED,
    ];

    /**
     * The longest attempted username kept: what was typed is input, and could be anything.
     */
    private const USERNAME_LENGTH = 255;

    /**
     * A user's own permissions as the permissions screen found them, by user id, until the save
     * that follows uses them.
     *
     * @var array<int, string[]>
     */
    private array $permissionsBefore = [];

    /**
     * Each group's `groupSaved` row, by group uid, so the second half of a save joins the first.
     *
     * @var array<string, Event>
     */
    private array $groupRows = [];

    /**
     * A group's permissions as its own change found them, by group uid, for the permissions save
     * that follows (see {@see configChanged()}).
     *
     * @var array<string, string[]>
     */
    private array $groupPermissionsBefore = [];

    /**
     * Who had an authenticator before a two-step removal action ran, or null outside one.
     *
     * @var int[]|null
     */
    private ?array $authenticatorsBefore = null;

    public function __construct(private readonly Auditor $plugin)
    {
    }

    public function attach(): void
    {
        YiiEvent::on(WebUser::class, WebUser::EVENT_AFTER_LOGIN, [$this, 'afterLogin']);
        YiiEvent::on(WebUser::class, WebUser::EVENT_BEFORE_LOGOUT, [$this, 'beforeLogout']);
        YiiEvent::on(UsersController::class, UsersController::EVENT_LOGIN_FAILURE, [$this, 'loginFailure']);
        foreach (array_keys(self::STANDING) as $name) {
            YiiEvent::on(Users::class, $name, [$this, 'standing']);
        }
        YiiEvent::on(UserRecord::class, BaseActiveRecord::EVENT_AFTER_UPDATE, [$this, 'afterUpdateRecord']);

        YiiEvent::on(Users::class, Users::EVENT_AFTER_ASSIGN_USER_TO_GROUPS, [$this, 'groupsAssigned']);
        YiiEvent::on(UsersController::class, UsersController::EVENT_BEFORE_ASSIGN_GROUPS_AND_PERMISSIONS, [$this, 'beforeAssignPermissions']);
        // The screen may save no permissions at all; an old set left behind would be stale by the next.
        YiiEvent::on(UsersController::class, UsersController::EVENT_AFTER_ASSIGN_GROUPS_AND_PERMISSIONS, fn() => $this->permissionsBefore = []);
        YiiEvent::on(UserPermissions::class, UserPermissions::EVENT_AFTER_SAVE_USER_PERMISSIONS, [$this, 'userPermissionsSaved']);
        foreach ([ProjectConfig::EVENT_ADD_ITEM, ProjectConfig::EVENT_UPDATE_ITEM, ProjectConfig::EVENT_REMOVE_ITEM] as $name) {
            YiiEvent::on(ProjectConfig::class, $name, [$this, 'configChanged']);
        }
        YiiEvent::on(UserGroups::class, UserGroups::EVENT_AFTER_DELETE_USER_GROUP, [$this, 'groupDeleted']);
        YiiEvent::on(Elements::class, Elements::EVENT_AFTER_DELETE_ELEMENT, [$this, 'userDeleted']);

        YiiEvent::on(AuthenticatorRecord::class, BaseActiveRecord::EVENT_AFTER_INSERT, [$this, 'authenticatorSaved']);
        YiiEvent::on(AuthenticatorRecord::class, BaseActiveRecord::EVENT_AFTER_UPDATE, [$this, 'authenticatorSaved']);
        YiiEvent::on(WebAuthnRecord::class, BaseActiveRecord::EVENT_AFTER_INSERT, [$this, 'passkeyAdded']);
        YiiEvent::on(WebAuthnRecord::class, BaseActiveRecord::EVENT_AFTER_DELETE, [$this, 'passkeyRemoved']);
        foreach (array_keys(self::MFA_REMOVAL_ACTIONS) as $controller) {
            YiiEvent::on($controller, Controller::EVENT_BEFORE_ACTION, [$this, 'beforeMfaRemoval']);
            YiiEvent::on($controller, Controller::EVENT_AFTER_ACTION, [$this, 'afterMfaRemoval']);
        }
    }

    /**
     * A sign-in, or one user signing in as another (BR-16, BR-22).
     *
     * For an ordinary sign-in the recorder's defaults are right: the user is signed in by now, so
     * they are the actor as well as the subject.
     */
    public function afterLogin(WebUserEvent $event): void
    {
        try {
            if ($event->cookieBased && !$this->plugin->getSettings()->recordCookieLogins) {
                return;
            }
            if (!$event->identity instanceof User) {
                return;
            }

            // Read from the session, where the impersonate actions put it just before signing in:
            // the component's own getter may have cached "nobody" earlier in the request.
            $impersonatorId = (int)Session::get(Craft::$app->getUser()->impersonatorIdParam) ?: null;
            if ($impersonatorId !== null && $impersonatorId !== (int)$event->identity->id) {
                $impersonator = User::find()->id($impersonatorId)->status(null)->one();
                $this->record(self::EVENT_IMPERSONATED, $event->identity, actor: $impersonator, impersonatorId: $impersonatorId);
                return;
            }

            $this->record(self::EVENT_LOGIN, $event->identity, $event->cookieBased ? ['cookie' => true] : null);
        } catch (Throwable $e) {
            $this->fail('Could not record a sign-in', $e);
        }
    }

    /**
     * Recorded before the session ends, while the user is still the actor and an impersonator is
     * still in the session (BR-16).
     */
    public function beforeLogout(WebUserEvent $event): void
    {
        try {
            if ($event->identity instanceof User) {
                $this->record(self::EVENT_LOGOUT, $event->identity);
            }
        } catch (Throwable $e) {
            $this->fail('Could not record a sign-out', $e);
        }
    }

    /**
     * A failed sign-in: what was typed as the username, and Craft's reason — never the password
     * (BR-17). Against a real account, that account is both subject and actor, so the attempts
     * read under the person they were made against; otherwise there is neither.
     */
    public function loginFailure(LoginFailureEvent $event): void
    {
        try {
            // The user's own reason, not the event's: with `preventUserEnumeration` on, Craft tells the
            // visitor a locked account's password was wrong, and the event carries that cover story.
            $data = [
                'username' => $this->attemptedUsername(),
                'reason' => $event->user->authError ?? $event->authError,
            ];

            if ($event->user !== null) {
                $this->record(self::EVENT_LOGIN_FAILED, $event->user, $data, actor: $event->user);
                return;
            }

            $this->recordRow(new Event([
                'family' => Event::FAMILY_AUTH,
                'event' => self::EVENT_LOGIN_FAILED,
                'data' => $this->plugin->getDiffer()->redact($data),
            ]));
        } catch (Throwable $e) {
            $this->fail('Could not record a failed sign-in', $e);
        }
    }

    /**
     * A lock, unlock, suspension, reinstatement, activation or deactivation (BR-18).
     */
    public function standing(UserEvent $event): void
    {
        try {
            $name = self::STANDING[$event->name] ?? null;
            if ($name !== null) {
                $this->record($name, $event->user);
            }
        } catch (Throwable $e) {
            $this->fail('Could not record a change of standing', $e);
        }
    }

    /**
     * The account changes that only the record sees whole: a new password, a password reset being
     * required, admin granted or taken away, an email address changed or verified (BR-19).
     *
     * `changedAttributes` holds the old value of each column the update wrote. Yii counts a column
     * as changed when its new value is not identical to the old, and a boolean read back from MySQL
     * is an integer, so each is compared again here as what it means.
     */
    public function afterUpdateRecord(AfterSaveEvent $event): void
    {
        try {
            $record = $event->sender;
            $old = $event->changedAttributes;
            if (!$record instanceof UserRecord || $old === []) {
                return;
            }

            $rows = [];

            // Written without any value, not even the hash.
            if (array_key_exists('password', $old) && $record->password !== null && $old['password'] !== $record->password) {
                $rows[] = [Event::FAMILY_AUTH, self::EVENT_PASSWORD_CHANGED, null];
            }
            if (array_key_exists('passwordResetRequired', $old) && !$old['passwordResetRequired'] && $record->passwordResetRequired) {
                $rows[] = [Event::FAMILY_AUTH, self::EVENT_PASSWORD_RESET_REQUIRED, null];
            }
            if (array_key_exists('admin', $old) && (bool)$old['admin'] !== (bool)$record->admin) {
                $rows[] = [Event::FAMILY_ACCESS, self::EVENT_ADMIN_CHANGED, ['admin' => ['old' => (bool)$old['admin'], 'new' => (bool)$record->admin]]];
            }
            if (array_key_exists('email', $old) && (string)$old['email'] !== (string)$record->email) {
                $rows[] = [Event::FAMILY_AUTH, self::EVENT_EMAIL_CHANGED, ['email' => ['old' => $old['email'], 'new' => $record->email]]];

                // An address waiting for verification became the address.
                $pending = $old['unverifiedEmail'] ?? null;
                if ($pending !== null && $record->unverifiedEmail === null && $record->email === $pending) {
                    $rows[] = [Event::FAMILY_AUTH, self::EVENT_EMAIL_VERIFIED, ['email' => $record->email]];
                }
            }

            if ($rows === []) {
                return;
            }

            // The record's own columns name the user as Craft would, with no query for the element.
            $subject = new User([
                'id' => $record->id,
                'username' => $record->username,
                'email' => $record->email,
                'fullName' => $record->fullName,
                'firstName' => $record->firstName,
                'lastName' => $record->lastName,
            ]);

            foreach ($rows as [$family, $name, $data]) {
                $this->record($name, $subject, $data, $family);
            }
        } catch (Throwable $e) {
            $this->fail('Could not record an account change', $e);
        }
    }

    /**
     * A user added to or removed from groups (BR-20). Craft fires this only when something changed.
     */
    public function groupsAssigned(UserGroupsAssignEvent $event): void
    {
        try {
            $added = $this->groupHandles($event->newGroupIds);
            $removed = $this->groupHandles($event->removedGroupIds);
            $subject = $added === [] && $removed === [] ? null : $this->user($event->userId);
            if ($subject !== null) {
                $this->record(self::EVENT_GROUPS_CHANGED, $subject, ['added' => $added, 'removed' => $removed], Event::FAMILY_ACCESS);
            }
        } catch (Throwable $e) {
            $this->fail('Could not record a change of groups', $e);
        }
    }

    /**
     * The permissions screen is about to save: the user's own permissions are read now, because
     * the permissions service's only event comes after they are replaced (BR-20).
     */
    public function beforeAssignPermissions(UserEvent $event): void
    {
        try {
            if ($event->user->id) {
                $this->permissionsBefore[(int)$event->user->id] = $this->storedPermissions((int)$event->user->id);
            }
        } catch (Throwable $e) {
            $this->fail('Could not read a user\'s permissions', $e);
        }
    }

    /**
     * A user's own permissions saved: the new set, beside the old one when the permissions screen
     * read it first, or marked `partial` when it could not be read (BR-20). A save that changed
     * nothing writes no row, which is only knowable when the old set was read.
     */
    public function userPermissionsSaved(UserPermissionsEvent $event): void
    {
        try {
            $new = $this->sorted($event->permissions);
            $old = $this->permissionsBefore[$event->userId] ?? null;
            unset($this->permissionsBefore[$event->userId]);

            if ($old !== null && $old === $new) {
                return;
            }
            $subject = $this->user($event->userId);
            if ($subject === null) {
                return;
            }

            $data = $old === null ? ['new' => $new, 'partial' => true] : ['old' => $old, 'new' => $new];
            $this->record(self::EVENT_PERMISSIONS_CHANGED, $subject, $data, Event::FAMILY_ACCESS);
        } catch (Throwable $e) {
            $this->fail('Could not record a change of permissions', $e);
        }
    }

    /**
     * A user group's settings or permissions changed in project config (BR-20). Craft saves a
     * group's permissions after the group itself, as a change of their own, so each half is added
     * to the group's one row for the request. A group being removed is {@see groupDeleted()}'s.
     */
    public function configChanged(ConfigEvent $event): void
    {
        try {
            $pattern = sprintf('/^%s\.(%s)(\.permissions)?$/', preg_quote(ProjectConfig::PATH_USER_GROUPS, '/'), ProjectConfig::UID_PATTERN);
            if (!preg_match($pattern, $event->path, $matches)) {
                return;
            }
            $uid = $matches[1];

            if (isset($matches[2])) {
                // Saving a group stores it without its permissions, so the save of them that follows
                // arrives as an addition; the group's own change still had them.
                $old = $this->sorted($event->oldValue ?? $this->groupPermissionsBefore[$uid] ?? null);
                unset($this->groupPermissionsBefore[$uid]);
                $new = $this->sorted($event->newValue);
                $added = array_values(array_diff($new, $old));
                $removed = array_values(array_diff($old, $new));
                if ($added !== [] || $removed !== []) {
                    $this->groupSaved($uid, ['permissions' => ['added' => $added, 'removed' => $removed]]);
                }
                return;
            }

            if (!is_array($event->newValue)) {
                return;
            }
            $old = is_array($event->oldValue) ? $event->oldValue : [];
            if (array_key_exists('permissions', $old)) {
                $this->groupPermissionsBefore[$uid] = $this->sorted($old['permissions']);
            }
            $changes = [];
            foreach (self::GROUP_ATTRIBUTES as $attribute) {
                $before = ($old[$attribute] ?? null) ?: null;
                $after = ($event->newValue[$attribute] ?? null) ?: null;
                if ($before !== $after) {
                    $changes[$attribute] = ['old' => $before, 'new' => $after];
                }
            }

            if ($event->oldValue === null) {
                $this->groupSaved($uid, ['isNew' => true, 'changes' => $changes]);
            } elseif ($changes !== []) {
                $this->groupSaved($uid, ['changes' => $changes]);
            }
        } catch (Throwable $e) {
            $this->fail('Could not record a change to a user group', $e);
        }
    }

    /**
     * A user group deleted, from the service, which still has it (BR-20).
     */
    public function groupDeleted(UserGroupEvent $event): void
    {
        try {
            unset($this->groupRows[(string)$event->userGroup->uid]);
            $this->recordRow($this->groupEvent(self::EVENT_GROUP_DELETED, $event->userGroup, []));
        } catch (Throwable $e) {
            $this->fail('Could not record a deleted user group', $e);
        }
    }

    /**
     * A user deleted — to the trash or for good: their name comes out of the log's rows once the
     * deletion commits (BR-26). The deletion itself is recorded by the element watcher, under the
     * user's label. Craft's garbage collection purges a trashed user without any event, which is
     * why the trashing is the moment, and a user restored from the trash stays anonymised.
     */
    public function userDeleted(ElementEvent $event): void
    {
        try {
            $user = $event->element;
            if ($user instanceof User && $user->id && !ElementHelper::isDraftOrRevision($user)) {
                $this->plugin->getRecorder()->anonymiseActor((int)$user->id);
            }
        } catch (Throwable $e) {
            $this->fail('Could not take a deleted user out of the log', $e);
        }
    }

    /**
     * The authenticator app set up: Craft stores its secret only once the first code from it has
     * been verified (BR-21). A sign-in with it updates only the record's timestamp.
     */
    public function authenticatorSaved(AfterSaveEvent $event): void
    {
        try {
            $record = $event->sender;
            if (
                !$record instanceof AuthenticatorRecord ||
                !array_key_exists('auth2faSecret', $event->changedAttributes) ||
                !empty($event->changedAttributes['auth2faSecret']) ||
                empty($record->auth2faSecret)
            ) {
                return;
            }

            $this->recordOwn(self::EVENT_MFA_ENABLED, (int)$record->userId, ['method' => TOTP::class]);
        } catch (Throwable $e) {
            $this->fail('Could not record two-step verification being set up', $e);
        }
    }

    /**
     * A passkey added: Craft writes its record only once the new credential has been verified
     * (BR-21). Using a passkey updates the record, so it is not heard here.
     */
    public function passkeyAdded(AfterSaveEvent $event): void
    {
        try {
            $record = $event->sender;
            if ($record instanceof WebAuthnRecord) {
                $this->recordOwn(self::EVENT_PASSKEY_ADDED, (int)$record->userId, ['name' => $record->credentialName]);
            }
        } catch (Throwable $e) {
            $this->fail('Could not record a new passkey', $e);
        }
    }

    /**
     * A passkey removed. `Auth::deletePasskey()` fires nothing, but deletes through the record.
     */
    public function passkeyRemoved(YiiEvent $event): void
    {
        try {
            $record = $event->sender;
            if ($record instanceof WebAuthnRecord) {
                $this->recordOwn(self::EVENT_PASSKEY_REMOVED, (int)$record->userId, ['name' => $record->credentialName]);
            }
        } catch (Throwable $e) {
            $this->fail('Could not record a removed passkey', $e);
        }
    }

    /**
     * Before an action that removes two-step methods: who has an authenticator (TN-16). The table
     * holds one row per person using one, and is read only here.
     */
    public function beforeMfaRemoval(ActionEvent $event): void
    {
        try {
            $this->authenticatorsBefore = $this->isMfaRemoval($event) ? $this->authenticatorUserIds() : null;
        } catch (Throwable $e) {
            $this->authenticatorsBefore = null;
            $this->fail('Could not read who uses two-step verification', $e);
        }
    }

    /**
     * After it: each person who no longer has one had it removed (BR-21). An action that failed
     * never gets here, and one that removed nothing changes nothing.
     */
    public function afterMfaRemoval(ActionEvent $event): void
    {
        try {
            $before = $this->authenticatorsBefore;
            $this->authenticatorsBefore = null;
            if ($before === null || !$this->isMfaRemoval($event)) {
                return;
            }

            foreach (array_diff($before, $this->authenticatorUserIds()) as $userId) {
                $subject = $this->user($userId);
                if ($subject !== null) {
                    $this->record(self::EVENT_MFA_DISABLED, $subject, ['method' => TOTP::class]);
                }
            }
        } catch (Throwable $e) {
            $this->fail('Could not record two-step verification being removed', $e);
        }
    }

    /**
     * Adds one half of a group's save to its row, or starts the row (BR-20).
     *
     * @param array<string, mixed> $part
     */
    private function groupSaved(string $uid, array $part): void
    {
        $row = $this->groupRows[$uid] ?? null;
        if ($row !== null && $this->plugin->getRecorder()->holds($row)) {
            $row->data = array_merge($row->data ?? [], $part);
            return;
        }

        $group = Craft::$app->getUserGroups()->getGroupByUid($uid) ?? new UserGroup(['uid' => $uid]);
        $row = $this->groupEvent(self::EVENT_GROUP_SAVED, $group, $part);
        $this->groupRows[$uid] = $row;
        $this->recordRow($row);
    }

    /**
     * A row about a user group. A group is not an element, so there is no subject id; the group is
     * named in the label and the payload.
     *
     * @param array<string, mixed> $part
     */
    private function groupEvent(string $name, UserGroup $group, array $part): Event
    {
        return new Event([
            'family' => Event::FAMILY_ACCESS,
            'event' => $name,
            'elementType' => UserGroup::class,
            'elementLabel' => (string)($group->name ?: $group->handle ?: $group->uid),
            'data' => ['group' => ['id' => $group->id, 'handle' => $group->handle, 'name' => $group->name]] + $part,
        ]);
    }

    /**
     * A row about something a person does to their own account. Setting up two-step verification
     * can happen while signing in, before anyone is signed in; the person is then the actor too.
     *
     * @param array<string, mixed> $data
     */
    private function recordOwn(string $event, int $userId, array $data): void
    {
        $subject = $this->user($userId);
        if ($subject === null) {
            return;
        }

        $signedIn = Craft::$app->has('user') ? Craft::$app->getUser()->getIdentity() : null;
        $this->record($event, $subject, $data, actor: $signedIn === null ? $subject : null);
    }

    private function isMfaRemoval(ActionEvent $event): bool
    {
        foreach (self::MFA_REMOVAL_ACTIONS as $controller => $action) {
            if ($event->sender instanceof $controller && $event->action->id === $action) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return int[]
     */
    private function authenticatorUserIds(): array
    {
        return array_map('intval', (new Query())->select(['userId'])->from(Table::AUTHENTICATOR)->column());
    }

    /**
     * A user's own permissions, not the ones their groups give them.
     *
     * @return string[]
     */
    private function storedPermissions(int $userId): array
    {
        return $this->sorted((new Query())
            ->select(['p.name'])
            ->from(['p' => Table::USERPERMISSIONS])
            ->innerJoin(['p_u' => Table::USERPERMISSIONS_USERS], '[[p_u.permissionId]] = [[p.id]]')
            ->where(['p_u.userId' => $userId])
            ->column());
    }

    /**
     * @param int[] $ids
     * @return string[]
     */
    private function groupHandles(array $ids): array
    {
        $groups = Craft::$app->getUserGroups();

        return array_values(array_map(fn($id) => $groups->getGroupById((int)$id)->handle ?? "#$id", $ids));
    }

    /**
     * A permission list as a sorted list of unique lower-case names, however it was given.
     *
     * @return string[]
     */
    private function sorted(mixed $permissions): array
    {
        $names = array_unique(array_map(fn($name) => strtolower((string)$name), is_array($permissions) ? $permissions : []));
        sort($names);

        return $names;
    }

    private function user(int $id): ?User
    {
        return User::find()->id($id)->status(null)->one();
    }

    /**
     * @param array<string, mixed>|null $data
     */
    private function record(
        string $event,
        User $subject,
        ?array $data = null,
        string $family = Event::FAMILY_AUTH,
        ?User $actor = null,
        ?int $impersonatorId = null,
    ): void {
        $row = new Event([
            'family' => $family,
            'event' => $event,
            'elementId' => (int)$subject->id,
            'elementType' => User::class,
            'elementLabel' => $this->label($subject),
            'data' => $data === null ? null : $this->plugin->getDiffer()->redact($data),
        ]);

        // Naming the actor outright stops the recorder putting the signed-in user there.
        if ($actor !== null) {
            $row->actorId = (int)$actor->id;
            $row->actorLabel = (string)$actor->email;
            $row->impersonatorId = $impersonatorId;
        }

        $this->recordRow($row);
    }

    private function recordRow(Event $row): void
    {
        $this->plugin->getRecorder()->record($row);
    }

    /**
     * What was posted as the username, if anything was. A passkey sign-in posts none.
     */
    private function attemptedUsername(): ?string
    {
        $request = Craft::$app->getRequest();
        if (!$request instanceof Request) {
            return null;
        }

        $name = $request->getBodyParam('loginName');

        return is_string($name) && $name !== '' ? mb_substr($name, 0, self::USERNAME_LENGTH) : null;
    }

    private function label(User $user): string
    {
        $label = trim((string)$user);

        return $label !== '' ? $label : sprintf('%s #%s', User::displayName(), $user->id);
    }

    private function fail(string $message, Throwable $e): void
    {
        try {
            Craft::error(sprintf('%s: %s', $message, $e->getMessage()), Auditor::HANDLE);
        } catch (Throwable) {
            // Nowhere left to report it, and BR-2 forbids throwing.
        }
    }
}
