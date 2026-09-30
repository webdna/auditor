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

`validate` needs `--no-plugins` (see Traps). Manual testing uses the pilot site, LLL, through a
composer **path repository** — only in the pilot task, and only on an LLL branch (see §6).

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

### Craft — events that do not exist

- **No event for impersonation, a password change, or a passkey / 2FA change.** Impersonation is a
  normal login with the impersonator in the session (`web/User.php:61`, `getImpersonator()`); a
  password change is a User save with `newPassword` set; `Auth::deletePasskey()`
  (`services/Auth.php:661`) fires nothing. The passkey/2FA hook is a controller-action listener and
  breaks silently when Craft renames an action — pin it with a test.
- **`UserPermissions` has no before-save event** — only `EVENT_AFTER_SAVE_USER_PERMISSIONS` /
  `EVENT_AFTER_SAVE_GROUP_PERMISSIONS`. The old set is gone by the time it fires.
- **User status changes write the `users` table without saving the element.** Activate, suspend,
  lock, unlock and group assignment fire their own `Users::EVENT_AFTER_*` events and no element save
  (found building the Typesense plugin from LLL). A watcher fed only by saves misses them.

### Craft — lifecycle

- **Destructive and element handlers must skip drafts, provisional drafts and revisions.** A CP save
  hard-deletes its provisional draft, which fires the same delete event as a real delete; unguarded in
  LLL, that cascade deleted real content. Autosaves would also flood the log.
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
