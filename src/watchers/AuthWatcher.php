<?php

namespace webdna\auditor\watchers;

use Craft;
use craft\controllers\UsersController;
use craft\elements\User;
use craft\events\LoginFailureEvent;
use craft\events\UserEvent;
use craft\helpers\Session;
use craft\records\User as UserRecord;
use craft\services\Users;
use craft\web\Request;
use craft\web\User as WebUser;
use Throwable;
use webdna\auditor\Auditor;
use webdna\auditor\models\Event;
use yii\base\Event as YiiEvent;
use yii\db\AfterSaveEvent;
use yii\db\BaseActiveRecord;
use yii\web\UserEvent as WebUserEvent;

/**
 * Turns sign-ins and changes to an account's standing into rows (BR-16 to BR-19, BR-22).
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
