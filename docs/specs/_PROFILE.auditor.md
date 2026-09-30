# Project profile — Auditor (Craft CMS 5 plugin)

**This file is referenced from the front matter of every spec in `docs/specs/`.** It carries the
project-specific half of a spec's build contract, so individual specs can stay about their feature.

> **Agents: read this whole file before writing code.** The repo is new (30 Sep 2026) and holds only
> this spec folder until the first spec's task 0.1 scaffolds it. Stack, commands and layout below are
> what that task creates, mirroring `~/Projects/plugins/typesense-sync` (the webdna plugin scaffolded
> the same day). The Traps section is not invented: every Craft entry was read in Craft 5.11.3's
> source on 30 Sep 2026 (file and line given), every tooling entry broke in a sibling webdna harness,
> and every "old auditor" entry is a defect found in `~/Projects/turf5/plugins/auditor`.

---

## 1. Stack

| Layer | Choice |
|---|---|
| Package | `webdna/craft-auditor`, type `craft-plugin`, handle **`auditor`** |
| Namespace | `webdna\auditor\` → `src/` |
| CMS | Craft CMS `^5.6` |
| Commerce | Optional. `craftcms/commerce` `^5.0` is a **dev** requirement only, for the Commerce test leg |
| Language | PHP `^8.2` (CI matrix 8.2 and 8.4) |
| Editions | `lite`, `pro` (Craft plugin editions) |
| Tests | Codeception 5 with Craft's `craft\test\Craft` module — `unit` and `integration` suites |
| Static analysis | PHPStan 2 level 6 via `craftcms/phpstan`, **no baseline** |
| Coding standard | ECS via `craftcms/ecs` |
| Licence | Craft License (commercial, Plugin Store) |
| Schema version | Starts at **`2.0.0`**, not 1.0.0 — see Traps, "the turf5 handle" |

## 2. Commands

**The host has no PHP.** Everything runs in the test containers defined by `docker-compose.yml`
(PHP CLI, MySQL 8.0):

```bash
docker compose up -d mysql
docker compose run --rm php composer install
docker compose run --rm php composer check         # ecs + phpstan + both codeception suites
docker compose run --rm php composer test:unit     # codecept run unit
docker compose run --rm php composer test:integration
docker compose run --rm php composer analyse       # phpstan
docker compose run --rm php composer cs            # ecs check (cs-fix to apply)
PHP_VERSION=8.4 docker compose run --rm php composer check   # the second CI leg
```

`validate` needs `--no-plugins` (see Traps).

**Real-app testing during development uses the craft5 sandbox** (Sam, 30 Sep 2026) — a DDEV
Craft 5 install at `~/projects/craft5` with real, upgraded content. The suites above stay the proof;
craft5 is where a task is also exercised against data the harness cannot fake. Auditor is
bind-mounted into it (`.ddev/docker-compose.auditor.yaml` there → `plugins/auditor`), required
through its `plugins/*` path repository (a symlink, so it is always the live checkout) and installed.
Drive it with a throwaway script under craft5's `storage/` that boots the console app — craft5 has no
`bootstrap/` folder, so `require dirname(__DIR__) . '/bootstrap.php'` then
`CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php'`, and call the recorder's `flush()` yourself,
because a script never ends a request — then read `auditor_events` with `ddev mysql`; delete the script
afterwards. Never run `craft gc` there to test pruning: it also purges craft5's old trashed content;
trigger `Gc::EVENT_RUN` from the script instead (task 5.1). **A control-panel request** runs
the same way through `bootstrap/web.php` (task 4.1): set `$_SERVER` and `$_GET` (an action goes in
the `action` query parameter — a path under `admin/actions/` 404s from the CLI), then
`setIsConsoleRequest(false)`, the plugin's `controllerNamespace` set to its web controllers (it was
set while the request still looked like the CLI), `enableSession = false` + `setIdentity()`, and
`handleRequest()` → `STATE_SENDING_RESPONSE` → `send()` inside an output buffer. The edition can be
set on the plugin in memory, so Pro is exercised without touching craft5's project config. **`ddev exec` drops an
empty argument** (`''`), shifting the rest — give a probe a real path (`dashboard`) for an action
request, or every request 404s as a CP page. Run
`ddev mutagen sync` after writing a script, or the container does not see it yet. **Run `ddev` there from
`/Users/samuelbirch/projects/craft5` (lowercase p)** — that is the root DDEV registered, and from
`~/Projects` a restart stops the project and then refuses to start it. Do not commit in craft5;
it carries other plugins' uncommitted work.

The pilot site, LLL, is touched only in the pilot task, through a composer **path repository**,
and only on an LLL branch (see §6).

## 3. Where things go

| What | Where |
|---|---|
| Plugin class, settings model | `src/Auditor.php`, `src/models/Settings.php` |
| Services (registered as components) | `src/services/` — `Recorder`, `Differ`, `Events` (query/read), `Retention` |
| Watchers (event listeners, no DB writes) | `src/watchers/` — `ElementWatcher`, `AuthWatcher`, `CommerceWatcher` |
| Row model, record | `src/models/Event.php`, `src/records/EventRecord.php` |
| Plugin events | `src/events/` |
| Migrations | `src/migrations/` — `Install.php` **and** an idempotent create-table migration |
| CP controllers | `src/controllers/` |
| Console controllers | `src/console/controllers/` |
| CP templates | `src/templates/` |
| Translations | `src/translations/en/auditor.php` |
| Tests | `tests/unit/`, `tests/integration/`, `tests/_craft/` (test site config) |
| Specs | `docs/specs/YYYY-MM-DD-<slug>.md` — **the spec is the plan; there is no `docs/plans/`** |

## 4. Verification

```bash
# 1. Everything
docker compose run --rm php composer check
# expect: ECS "No errors found", PHPStan "[OK] No errors", both Codeception suites "OK (N tests, M assertions)"

# 2. No site-specific names leaked from turf5 or LLL
grep -rniE 'turf|otis|listingsource|seomatic|redactor|lll|members|companies' src/
# expect: no output

# 3. Composer metadata is Store-valid
docker compose run --rm php composer validate --strict --no-plugins
# expect: "./composer.json is valid"

# 4. Nothing renders logged values unescaped
grep -rn '|raw\|raw(' src/templates/
# expect: no output
```

## 5. Traps

### Craft — dirty tracking (Craft 5.11.3)

- **"Dirty" means a value was *set*, not that it *changed*.** `setFieldValue()` marks the field dirty
  unconditionally (`base/Element.php:5441`), and a site form that posts every field marks every field
  dirty. A diff built from `getDirtyFields()` alone logs edits nobody made. Dirty is the candidate
  list; a before/after comparison decides.
- **A resave marks everything dirty** (`_allDirty()` returns true while `resaving`,
  `base/Element.php:5631`). Diffing a bulk resave compares every field of every element.
- **The dirty record is wiped straight after `EVENT_AFTER_SAVE_ELEMENT`** (`markAsClean()`,
  `services/Elements.php:4437`). Read it in that event or earlier; a queue job or end-of-request
  handler sees nothing.
- **Applying a draft replaces the canonical clone's dirty set with the draft's recorded changes**
  (`services/Elements.php:1924-1930`, `2161`), and the clone has `duplicateOf` set, so Craft's own
  change tracking is off for that save (`helpers/ElementHelper.php:413`). The dirty list is accurate
  here; the attribute tracking is not.
- **Native attributes are barely tracked.** Only `title` is detected on its own
  (`base/Element.php:5278`); the save adds `fieldLayoutId`, `enabled`, `archived`, `slug`, `uri`,
  `enabledForSite` from record comparisons, and only when change tracking is on. `postDate`,
  `authorIds`, a user's `email`/`admin`, an order's status — none. They must be compared explicitly.

- **Content is stored as `serializeValueForDb()`, not `serializeValue()`,** in `elements_sites.content`
  keyed by the field's **layout element uid** (`services/Elements.php:4155-4190`), and a null value
  is not stored at all. MySQL's JSON type reorders object keys, so a stored value compared to a fresh
  serialisation must ignore key order. Fields whose `dbType()` is null (Matrix, Content Block,
  Addresses) have no stored value on their owner. A relation field's serialisation runs an ids query.
- **A relation field may have no content value at all, and then Craft reads the `relations` table**
  (`fields/BaseRelationField.php:788`): relations have been mirrored into content only since 5.3, so
  an element not saved since then — or since the field joined its layout — has no key for it. Every
  site upgraded from Craft 4 is full of these. Treating the missing key as "empty" logs every
  relation as newly added on the element's next save. Found on the craft5 sandbox, never in the
  harness, whose content is always current.
- **Applying an unpublished draft is a first save of an existing id** (`services/Drafts.php:387`,
  `removeDraftData()` sets `firstSave`). The CP makes every new entry this way, so "new" is
  `isNew || firstSave`, never `isNew` alone.
- **Structure moves fire `afterUpdateElement` from 5.9 and `afterMoveElement` before it** — the old
  constant still exists in 5.11 but nothing triggers it. The event's action is one of
  `Structures::ACTION_*`: `prepend`, `append`, `placeBefore`, `placeAfter`.

### Craft — events that do not exist

- **No event for impersonation, a password change, or a passkey / 2FA change.** Impersonation is a
  normal login with the impersonator in the session (`web/User.php:61`, `getImpersonator()`); a
  password change is a User save with `newPassword` set; `Auth::deletePasskey()`
  (`services/Auth.php:661`) fires nothing. But passkeys and the authenticator app are ActiveRecords:
  a passkey's `WebAuthn` record is inserted once Craft verifies it and deleted through the record
  (so `afterDelete` fires), and the authenticator's secret is stored once the first code verifies.
  Signing in with either only *updates* its record. **Removing the authenticator app is
  `Authenticator::deleteAll()`** (`auth/methods/TOTP.php:158`) — a bulk delete, no record event —
  so it is heard only by hooking the two actions that call it (`auth/remove-method`,
  `users/remove-2fa`), which break silently when Craft renames one; a test pins both (task 2.2).
- **`UserPermissions` has no before-save event** — only `EVENT_AFTER_SAVE_USER_PERMISSIONS` /
  `EVENT_AFTER_SAVE_GROUP_PERMISSIONS`. The old set is gone by the time it fires.
- **Saving a user group stores its project config without its permissions.** `saveGroup()` sets
  `users.groups.<uid>` to `getConfig(false)`; `handleChangedUserGroup()` puts the old permissions
  back on the *event*, but the stored value keeps none, so the `saveGroupPermissions()` that follows
  arrives as an **addition** with no old value. The group's own `updateItem` event still carries
  the old permissions — take them from there (task 2.2). Project config's change events are
  class-level `ProjectConfig::EVENT_ADD_ITEM`/`UPDATE_ITEM`/`REMOVE_ITEM` with `oldValue`, fire once
  per path (`ProjectConfigData::commitChanges()` dedupes), never when a value is unchanged, and
  also on `project-config/apply`.
- **User status changes write the `users` table without saving the element.** Activate, suspend,
  lock, unlock and group assignment fire their own `Users::EVENT_AFTER_*` events and no element save
  (found building the Typesense plugin from LLL). A watcher fed only by saves misses them.
- **`Users::EVENT_AFTER_VERIFY_EMAIL` does not mean an address was verified.** It fires from
  `UsersController::_processTokenRequest()` when any verification link is *opened* — the set-password
  link included — on the GET, before anything is verified. `Users::verifyEmailForUser()`, which does
  the verifying, fires nothing. The user record's `afterUpdate` is where it shows: `unverifiedEmail`
  cleared and `email` set to it (task 2.1).
- **`EVENT_LOGIN_FAILURE`'s `authError` can be a cover story.** With `preventUserEnumeration` on,
  `helpers/User::getLoginFailureInfo()` turns `account_locked` and `account_cooldown` into
  `invalid_credentials` before the event fires; `$event->user->authError` keeps the real one. Found on
  the craft5 sandbox, which has the setting on; the harness has it off.
- **A wrong two-step code raises no failure event.** `Auth::verify()` (`services/Auth.php:235`) only
  calls `handleInvalidLoginParam()`, so it counts toward the lock-out and nothing else hears it.
- **The user record's `afterUpdate` carries every changed column's old value**
  (`AfterSaveEvent::$changedAttributes`) at no cost, and every writer of the `users` table goes
  through it — but Yii counts a column changed when the new value is not *identical*, and a boolean
  read back from MySQL is an integer, so compare booleans as booleans.

### Craft — lifecycle

- **Destructive and element handlers must skip drafts, provisional drafts and revisions.** A CP save
  hard-deletes its provisional draft, which fires the same delete event as a real delete; unguarded in
  LLL, that cascade deleted real content. Autosaves would also flood the log.
- **Every save and every delete runs inside a Craft bulk operation** (`ensureBulkOp()`,
  `services/Elements.php:4001`, `:2620`), so "inside a bulk operation" does not mean "a bulk
  change". A top-level save's own before and after events fire **outside** its operation (3915 and
  4429 bracket the call); only its nested saves, and saves made inside an operation someone else
  opened (`resaveElements()`, a batched job), fall inside. `resumeBulkOp()` fires no event, so a
  batched job's later queue runs are invisible to bulk-op listeners (`queue/BaseBatchedElementJob.php`).
  `restoreElements()` opens no operation at all, and the element-index controller wraps an action
  only in `EVENT_BEFORE/AFTER_PERFORM_ACTION` — the after event only on success.
- **Craft 5 stores a draft's or revision's nested entries as ordinary elements** (no `draftId` of
  their own) owned by the draft. `Entry::find()` returns them, so a resave "of every entry" is mostly
  such entries on a real site (200 of craft5's 251); `ElementHelper::isDraftOrRevision()` follows the
  owners and is the only way to tell.
- **Yii fires `EVENT_COMMIT_TRANSACTION` / `EVENT_ROLLBACK_TRANSACTION` only at the outermost level**
  (`yiisoft/yii2/db/Transaction.php:163`, `:197`). A savepoint rolled back inside a committed outer
  transaction is invisible to a listener.
- **Craft applies `config/<handle>.php` over the Settings model, and an unknown key is an error.**
  Every key the config file may carry must be a declared Settings property.
- **The turf5 handle.** `~/Projects/turf5/plugins/auditor` is a different plugin with the **same
  handle (`auditor`) and namespace**, installed at schema 1.0.0 with table `auditor_log`. When a site
  swaps it for this package, Craft treats the plugin as already installed and **never runs
  `Install.php`** — so the events table must also be created by an ordinary, idempotent migration,
  and the schema version must start above 1.0.0.

### Craft — the control panel

- **Craft refuses any control panel address whose first segment is a plugin's handle** to a user
  without `accessPlugin-<handle>` (`web/Application.php:289`), whether or not the plugin sets
  `hasCpSection` — and only a plugin with a CP section gets that permission registered. So
  "Access Auditor" is the log's permission. Action requests skip that check; a controller must
  make it itself (task 3.1).
- **An element's action menu is `Element::EVENT_DEFINE_ACTION_MENU_ITEMS`, fired on every element
  type's edit screen and slideout** (`base/Element.php:4131`); a button item's `attributes` land on
  its `<button>` and a click on it bubbles to the document. The edit screen usually holds a
  provisional draft — name `getCanonicalId()`. Craft drops the menu for an element without an id.
- **A component's default `displayName()` is its class's short name** ("SetStatus"), and a queue job
  has no `displayName()` at all, so neither is fit to show as words (task 4.1, found on craft5).
- **`UserPermissions::getAllPermissions()` memoises its list**, so a test that changes what is
  registered (an edition) reads it from a `new UserPermissions()`.
- **A user edit screen of a plugin's own is `UsersController::EVENT_DEFINE_EDIT_SCREENS`** plus a
  controller that uses Craft's `EditUserTrait` (`editedUser()`, `asEditUserScreen()`), which is
  public from 5.1 (task 4.2). Craft builds the nav item as `users/<id>/<screen>` for someone else
  and `myaccount/<screen>` for oneself, so both need a CP rule. `asEditUserScreen()` refuses a
  screen id the event did not add, and adds a "Save and continue editing" alt action to every
  screen but Profile — clear it on a screen with nothing to save.
- **`craft help` prints a console controller's class docblock and each option's `@var` text to the
  customer.** Keep spec rule ids and internal notes out of them (task 5.1).
- **A soft user deletion leaves their entries alone.** `users/delete --delete-content` only confirms
  consent; the entries go when the user is hard-deleted. A test of "deleting a user" sees no entry
  deletion rows.
- **`Cp::nav()` makes every item's `url` absolute** (`web/twig/variables/Cp.php:404`) — compare
  with `UrlHelper::cpUrl()`, not the handle.

### The old auditor's defects — do not repeat

- **It rendered logged values with `raw()`** (`turf5/plugins/auditor/src/templates/detail.twig`).
  Field content is user input; that is stored XSS in the CP.
- **It declared `allowAnonymous`** on its log controller (copied from the generator) and checked no
  permission.
- **It loaded every row, then paginated in Twig.** Cost grew with the table.
- **It dereferenced `getIdentity()->email` with no null check**, so any save with no signed-in user
  was a fatal error — the reason it was limited to CP requests.
- **It hard-coded classes from Redactor, SEOmatic and a webdna field plugin** to flatten values.
  Field values are serialised by the field's own `serializeValue()`.

### Tooling (sibling webdna harnesses)

- **A cloned `Craft::$app->getDb()` is NOT a second connection under the harness.** With
  `transaction: true`, the Yii2 module's `TransactionForcer` hands any connection with the same DSN
  and credentials the main connection's cached PDO, already inside the per-test transaction, so
  `beginTransaction()` on the clone throws "There is already an active transaction" and nothing it
  writes is committed. `tests/integration/SecondConnection.php` marks the open event handled on the
  instance, which keeps the forcer from seeing the new connection. Use it for DDL and for anything
  that must really commit or roll back, and delete what it writes.
- **The harness's logger (`Codeception\Lib\Connector\Yii2\Logger`) keeps no `messages`** — only a
  five-line text queue. To assert that something was logged, `Yii::setLogger()` a plain
  `yii\log\Logger` for the test and restore the harness's afterwards.

- **On macOS, a file edited on the host can reach the container half-synced.** A run started straight
  after an edit may fail with a `ParseError` ("Unclosed '{'") in the edited file although `php -l`
  passes a moment later. Rerun before debugging; CI on Linux never sees it.
- **Building test content:** `link` is a reserved field handle; Craft refuses a Local filesystem
  inside its storage path (use the system temp directory); a section allows one author unless
  `maxAuthors` is raised. `tests/Support/TestSite.php` builds §7's content model inside each test's
  transaction.

- **The harness is a console app, so Craft points a plugin's `controllerNamespace` at
  `console\controllers`.** Running a web controller through `runAction()` means setting it to
  `webdna\auditor\controllers` for the test and putting it back (`LogScreenTest`). A
  `renderTemplate()` response is rendered by `(new TemplateResponseFormatter())->format($response)`.
- **A control-panel screen's content renders when the response is sent**, not when the action
  returns: `contentTemplate()` stores a closure. A test reads `CpScreenResponseBehavior::$contentHtml`
  and calls it **inside** the web request, or Craft's asset bundles throw on
  `getIsCpRequest()` returning null (`ActivityTest::content()`).
- **Signing in inside the harness** needs an active user and a request with a user agent and an
  address (`requireUserAgentAndIpForSession` is on by default and the CLI has neither), or
  `login()` quietly returns false. Craft's real login action runs through `Craft::$app->runAction()`
  with `REQUEST_METHOD=POST`, body params set and CSRF validation off (`AuthWatcherTest::postLogin()`).
- **A control-panel action refuses a user without `accessCp`** before the action runs, so a test
  posting `auth/*` as an ordinary user must grant it first. Signing in with `login()` also makes the
  session elevated, which the permission and two-step actions require. A **console** controller runs
  in the harness by instantiating it (`new UsersController('users', Craft::$app)` then
  `runAction()`); its output goes straight to STDOUT, past any output buffer.
- **Craft 5's `TestSetup::configureCraft()` needs `CRAFT_ROOT_PATH` and `CRAFT_TESTS_PATH` defined,
  and every `CRAFT_*_PATH` directory to exist** (it `realpath()`s them). Older harnesses omit both and
  fail with "Undefined constant".
- **Codeception 5 reads `bootstrap:` at the top level of `codeception.yml`.** Under `settings:` it is
  silently ignored and Craft's constants are never defined.
- **`craftcms/phpstan` does not require PHPStan** — `phpstan/phpstan` is its own dev requirement.
- **`composer validate` crashes with plugins loaded** ("$from (./vendor/craftcms) and $to (/app)
  must be absolute paths" from `craftcms/plugin-installer`) — use `--no-plugins`.
- **On PHP 8.4, `composer cs` prints ~150 `Deprecated:` lines** from ECS 10's vendored packages.
  Noise; judge the run by its exit code and "No errors found".

### The pilot site (LLL)

- **Never `ddev restart` LLL, and run `ddev` only from `~/Projects/lll`** (capital P). A restart
  kills its hand-run Vite server and pages then load with no JavaScript and no error.
- **LLL's local Servd volume is the production bucket.** Nothing in the pilot may move, rename or
  delete an asset file — do not drive a form submit that uploads.
- **Another Claude session edits LLL's checkout.** Never stash there; stage and commit only your own
  hunks (`git commit --only`).

## 6. Working conventions

- **Commit, never push.** Sam pushes and owns the GitHub repo, Packagist and the Plugin Store
  listing. The repo has no remote until Sam creates one.
- One spec task per session; tick the spec, commit, then hand over.
- **LLL is touched by the pilot task only**, on an LLL branch, as its own LLL commit (composer path
  repository + plugin install in project config). Reading LLL for reference is fine at any time.
