# Auditor

An audit log for Craft CMS. Anyone you give permission to can answer "who changed this, when, from
where, and what did it say before?" — and "who signed in, who failed to, and who was given access?"
— from the control panel, without reading log files or the database.

- **Every content change, with its before and after.** Entries, assets, users, categories, tags,
  Craft Commerce orders and products, and any element type a plugin adds: created, saved, deleted,
  restored and moved, with the value each changed field had before and after.
- **Every security event in the same log.** Sign-ins, failed sign-ins, lock-outs, password and email
  changes, two-step and passkey methods, changes to someone's groups or permissions, admin rights
  given or taken, and one person signing in as another.
- **Wherever the change came from.** The control panel, a form on the site, a console command or a
  queue job, each tagged so you can tell them apart.
- **Readable at scale.** A resave of every entry or an import of a thousand products is one row
  describing the batch, not a thousand, and each element's History still shows it was included.
- **Built to cost almost nothing.** A page that changes nothing does no auditing work at all. Changes
  are written once, together, at the end of the request, and only if they were committed. If
  recording ever fails, the save or sign-in carries on regardless.

## Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Editions](#editions)
- [What is recorded](#what-is-recorded)
- [Configuration](#configuration)
- [Permissions](#permissions)
- [The control panel](#the-control-panel)
- [Console commands](#console-commands)
- [Extension points](#extension-points)
- [Personal data](#personal-data)
- [Performance](#performance)
- [Troubleshooting](#troubleshooting)
- [Uninstalling](#uninstalling)

## Requirements

- Craft CMS 5.6 or later
- PHP 8.2 or later
- MySQL 8 (PostgreSQL is not yet tested)
- Craft Commerce 5, only to record orders, products and variants

## Installation

From the Plugin Store: search for "Auditor" and install it.

With Composer:

```bash
composer require webdna/auditor
php craft plugin/install auditor
```

Recording starts as soon as the plugin is installed. Nothing is recorded from before then.

## Editions

Both editions record exactly the same things. They differ in where you can read them.

| | Lite | Pro |
|---|---|---|
| The log screen, its filters and each row's detail | ✓ | ✓ |
| Retention, redaction and deleted-user handling | ✓ | ✓ |
| **History** on each entry, asset, product or user | | ✓ |
| **Activity** on each user: what they changed, and what happened to their account | | ✓ |

Moving from Lite to Pro shows History and Activity for everything already recorded.

## What is recorded

Every row says who did it (the **actor**), what it happened to (the **subject**), when, from which
context — control panel, site, console or queue, with the route, command or job — and, for web
requests, the IP address and browser. Rows written in one request share a request id, so the log
can show "everything else this request did".

### Content changes

| Event | Recorded |
|---|---|
| Created | The new element's non-empty field values and attributes |
| Saved | Each field and attribute that changed, with its value before and after |
| Deleted, Restored | The element and who did it |
| Moved | A structure move: where it went |
| Batch | One row for a large operation (see below) |

A save that changed nothing writes no row. The before value is read from what Craft stored, so a
form that re-posts every field is recorded as changing only what actually changed.

Alongside custom fields, these attributes are tracked:

| Element type | Attributes |
|---|---|
| Entries | title, slug, enabled, enabled for site, post date, expiry date, authors, entry type, section |
| Users | email, username, full name, first name, last name, admin, photo |
| Assets | title, filename, folder, volume, alt text |
| Categories, tags | title, slug, enabled |
| Commerce orders | order status, paid status, email, coupon code |
| Commerce products | title, enabled |
| Commerce variants | title, enabled, SKU, base price, base promotional price |

**Not recorded:** drafts, provisional drafts, autosaves, revisions, and the copies Craft makes when
it propagates a save to other sites. Publishing a draft *is* recorded — it is the change people care
about. A nested entry's change rolls up under its owner, so publishing an entry with three edited
blocks reads as one change with three beneath it.

### Batches

An operation is recorded as **one batch row** when it is a resave (`resave/*` commands, or a resave
Craft queues after a settings change), a batched queue job, an element-index action on more than one
element, or any single operation touching more than `bulkThreshold` top-level elements (10 by
default). The row says what the operation was, the element type, how many, and who ran it; every
element it covered is listed, so each one's History reads "Included in a re-save of 1,240 entries".

A batch takes no before-snapshots and records no per-element changes, which is what keeps a large
resave fast. Set `recordBulkDiffs` to record each element in full instead.

A batched job that Craft splits over several queue runs writes one batch row per run.

### Sign-ins and accounts

Signed in · signed out · failed sign-in (the attempted username and Craft's real reason — never the
password) · locked · unlocked · suspended · unsuspended · activated · deactivated · email changed ·
email verified · password changed · password reset required · authenticator app added or removed ·
passkey added or removed.

Sign-ins by a "Keep me signed in" cookie are not recorded unless `recordCookieLogins` is on.
Two-step methods that other plugins add are not recorded.

### Access

Groups added or removed · permissions changed · admin rights given or taken · a user group created,
changed (name, handle, description, permissions) or deleted · one user signing in as another.

When someone signs in as another user, that row names both, and every row written in that session
carries the original user as well.

A user group changed by applying project config on a deploy is recorded too, with the console as its
context.

### Craft Commerce orders

Orders are recorded once they are completed. Carts never are, and filling a cart costs the log
nothing. A completed order's save row shows its status and email changes as any element's do, and
also its **line items** added, removed or changed (purchasable, SKU, quantity, sale price, options,
notes, status) and its **adjustments** (type, name, amount, included, and the line item each applies
to).

Stock movements are not recorded: Commerce moves stock through inventory transactions, which save
no element.

## Configuration

Settings are code, deployed with the rest of the site. There is no settings screen. Copy
[`examples/config/auditor.php`](examples/config/auditor.php) to `config/auditor.php` and change what
you need; every value it shows is the default. As with any Craft config file, it may be keyed by
environment.

| Setting | Default | Does |
|---|---|---|
| `enabled` | `true` | `false` stops all recording. Nothing already recorded is removed. |
| `contexts` | `['cp', 'site', 'console', 'queue']` | The contexts recorded. Leave one out to stop recording from it. |
| `excludeElementTypes` | `[]` | Element classes never recorded, e.g. `[\craft\elements\Asset::class]`. |
| `redact` | `['password', 'newPassword', 'verificationCode', 'authKey']` | Field handles and attribute names recorded as "changed" without their value. |
| `retentionDays` | `365` | Days a row is kept. `0` keeps every row. |
| `bulkThreshold` | `10` | Top-level elements one operation may touch before it is recorded as a batch. |
| `recordBulkDiffs` | `false` | Record each element in a batch as its own row, with its changes. Slows resaves and large bulk actions. |
| `recordNoChangeSaves` | `false` | Record a save even when no value changed. |
| `recordCookieLogins` | `false` | Record sign-ins made by a "Keep me signed in" cookie. |

```php
<?php

return [
    '*' => [
        // A nightly import is noise in the log.
        'contexts' => ['cp', 'site', 'console'],
        'redact' => ['password', 'newPassword', 'verificationCode', 'authKey', 'taxNumber'],
        'retentionDays' => 180,
    ],
    'dev' => [
        'enabled' => false,
    ],
];
```

**Redaction** applies everywhere a value would appear: a new element's values, a save's changes, and
the payload of sign-in and access rows. A redacted value is recorded as having changed, so the log
still shows *that* a tax number was edited, and by whom, without keeping the number.

## Permissions

| Permission | Lets someone |
|---|---|
| **Access Auditor** (Craft's own plugin permission) | Open the log and each row's detail |
| **See the address and browser each event came from** | See IP addresses and browsers. Everyone else sees "—". |
| **See the history of what they can view** (Pro) | Open History on an element they can view |
| **See what each user changed and what happened to their account** (Pro) | Open a user's Activity. Another user's also needs Craft's *View users*. |

Admins hold all of them. No permission lets anyone edit or delete a row — an audit log that can be
edited is not an audit log.

## The control panel

**The log** is the Auditor item in the control panel's navigation. Filter by kind (content, sign-in,
access), event, element type, person, context, site and date range; search matches the subject's and
the person's names. Open any row for its detail: a Field / Before / After table for a save, the line
items and adjustments for an order, or the payload for a sign-in or access event, with a link to
everything else the same request did. Long values are cut at 500 characters with a control to show
the rest. Nothing logged is ever rendered as HTML.

**History** (Pro) is in each element's action menu (the ⋯ button on its edit screen). It lists the
element's changes newest first, one row per publish with its nested entries' changes beneath, and the
batches it was part of. It loads only when opened, so it adds nothing to the edit screen.

**Activity** (Pro) is a screen on each user, beside their profile and permissions, with two tabs:

- **Changes made** — everything that user did.
- **Account events** — what happened to their account, whoever did it: sign-ins, failed sign-ins,
  lock-outs, group and permission changes, someone signing in as them.

## Console commands

```bash
# Remove rows older than retentionDays now, instead of waiting for garbage collection
php craft auditor/prune

# Remove rows older than 30 days, once, whatever retentionDays says
php craft auditor/prune --days=30

# Count what would be removed, removing nothing
php craft auditor/prune --dry-run

# Anonymise a deleted user's rows (see Personal data)
php craft auditor/anonymise-actor --user=123
```

`--days` below 1 is refused. Each command exits non-zero on failure.

## Extension points

### Add or remove tracked attributes

```php
use webdna\auditor\events\DefineTrackedAttributesEvent;
use webdna\auditor\services\Differ;
use yii\base\Event;

Event::on(Differ::class, Differ::EVENT_DEFINE_TRACKED_ATTRIBUTES, function(DefineTrackedAttributesEvent $event) {
    if ($event->elementType === \craft\elements\Entry::class) {
        unset($event->attributes['postDate']);
    }
});
```

Each attribute maps to where its **stored** value is read: `'table.column'` for a column (`elements`
and `elements_sites` are the element's own rows; any other table is joined on its `id`), or a
`Closure(ElementInterface $element): mixed` that returns the stored value itself. The new value is
always read as `$element->{$name}`. The event is raised once per element type.

### Add your own state to an element's row

`Differ::EVENT_DEFINE_ELEMENT_SNAPSHOT` lets any code record state that is neither a field nor an
attribute. It is raised before a save, with `$event->before` null, for the handler to read each key's
**stored** state into `$event->values`; and after the save, with `$event->before` holding what was
read, for the handler to give each key's state now. A key whose two values differ is recorded like a
field. To word the change yourself, set `$event->changes[$key]` to `{label, old, new}`, or to a list
of items — `{label, fields, added, removed, changed}` — which the detail screen draws as a table. The
Commerce order differ is built on this event. It is raised only while something listens.

```php
use webdna\auditor\events\DefineElementSnapshotEvent;

Event::on(Differ::class, Differ::EVENT_DEFINE_ELEMENT_SNAPSHOT, function(DefineElementSnapshotEvent $event) {
    if (!$event->element instanceof \craft\elements\Entry) {
        return;
    }
    $event->values['bookings'] = (int)(new \craft\db\Query())
        ->from('{{%my_bookings}}')
        ->where(['entryId' => $event->element->id])
        ->count();
});
```

Read the before state from the database, not by loading the element. Values you add are redacted by
the `redact` setting like any other.

### Change or skip a row before it is recorded

```php
use webdna\auditor\events\RecordEvent;
use webdna\auditor\services\Recorder;

Event::on(Recorder::class, Recorder::EVENT_BEFORE_RECORD, function(RecordEvent $event) {
    if ($event->row->contextDetail === 'my-plugin/sync/run') {
        $event->isValid = false;
    }
});
```

`$event->row` is a `webdna\auditor\models\Event`: `family`, `event`, the subject (`elementId`,
`elementType`, `siteId`, `elementLabel`), the actor (`actorId`, `actorLabel`), `context`,
`contextDetail` and `data`.

### Record an event of your own

```php
use webdna\auditor\Auditor;
use webdna\auditor\models\Event as AuditEvent;

Auditor::getInstance()->getRecorder()->record(new AuditEvent([
    'family' => AuditEvent::FAMILY_ACCESS,
    'event' => 'apiKeyIssued',
    'data' => ['key' => 'Mobile app'],
]));
```

The person, context, request id and address are filled in for anything you leave unset, and the row
is written with the request's others, after its transaction commits.

## Personal data

The log holds personal data: IP addresses, browsers, email addresses, and the values people typed
into fields. Auditor gives you the controls; your site's privacy notice should say that it keeps an
audit log, what it holds and for how long.

- **Retention.** Rows older than `retentionDays` are removed by Craft's garbage collection, in batches
  of 10,000, and on demand by `auditor/prune`.
- **Redaction.** List any field whose value should never be kept.
- **Deleted people.** When a user is deleted, the rows they made are rewritten to read "Deleted user
  #123" and the username is cleared from failed sign-ins against them. The record of what happened
  stays. This happens when the user goes to the trash, so restoring them does not bring their name
  back. Rows *about* the user (their own sign-ins, say) keep the name they were recorded under, as
  any deleted subject does. `auditor/anonymise-actor --user=<id>` does the same for a user deleted
  before Auditor was installed; it refuses a user who still exists, because it cannot be undone.
- **Addresses** are shown only to people with the permission to see them.

Auditor sends nothing anywhere: no email, notification or webhook.

## Performance

- A request that saves nothing and raises no sign-in or access event runs **no** extra queries.
- A save reads its stored values once, from the rows Craft already keeps, and never loads the
  element. The request's rows are written in one insert at its end.
- A resave of 1,000 entries measured 0.3–0.8% slower with Auditor enabled than without (the limit
  it is built to is 10%).
- History and Activity load when opened, never as part of an edit screen.

## Troubleshooting

**Nothing is being recorded.** Check `enabled` and `contexts` in `config/auditor.php` for the current
environment, and that the element type is not in `excludeElementTypes`. Drafts and autosaves are
never recorded; publish the draft.

**A save I made is missing.** If the save was rolled back — a validation error, or an exception later
in the same transaction — it was never committed and is not recorded. A savepoint rolled back inside
a transaction that then commits cannot be detected; its changes are recorded as if they stood.

**A resave shows one row, not one per entry.** That is a batch. Open it to see the operation and
count, or set `recordBulkDiffs` to record each element.

**History or Activity is missing.** Both are Pro, and each needs its permission. History is not
offered on an unpublished draft.

**Recording failed.** Auditor never lets a failure stop a save or sign-in. Failures are logged to
the `auditor` log category: look in `storage/logs/`.

## Uninstalling

Uninstalling drops Auditor's two tables, `auditor_events` and `auditor_event_elements`, and every row
in them. To keep the log, back up those tables first.

Auditor's schema starts at 2.0.0 and its tables are also created by a migration, so it can replace an
older plugin with the handle `auditor`. That plugin's own tables are never read, changed or dropped.

## Support

Report a problem at [github.com/webdna/auditor/issues](https://github.com/webdna/auditor/issues).
