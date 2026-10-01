# Plugin Store listing — draft

**Status:** draft by Claude, 1 Oct 2026. Sam owns the final text, the screenshots and the prices
(spec Appendix A #1–#3). Nothing here is published until Sam submits it.

## Fields

| Field | Draft |
|---|---|
| Name | Auditor |
| Handle | `auditor` |
| Package | `webdna/craft-auditor` |
| Short description | Who changed what, when, from where and what it said before — and every sign-in and change of access — in one log. |
| Categories | Security · Utilities |
| Keywords | audit, audit log, history, activity, security, changes, sign-in, compliance |
| Editions | **Lite** — the log (price: **Sam — open**) · **Pro** — adds History and Activity (price: **Sam — open**) |
| Licence | Craft License |
| Requires | Craft CMS 5.6+, PHP 8.2+; Craft Commerce 5 optional |
| Documentation URL | https://github.com/webdna/craft-auditor/blob/main/README.md |
| Changelog URL | https://raw.githubusercontent.com/webdna/craft-auditor/main/CHANGELOG.md |
| Repository | https://github.com/webdna/craft-auditor (**Sam — to create**) |
| Icon | `src/icon.svg` (draft: a log page with a clock — **Sam to approve or replace**) |

`composer.json` already names the package, the repository and both URLs above; if the repository
gets another name, change them there too.

## Long description

> Auditor keeps one log of everything that matters for accountability on a Craft site. Anyone you
> give permission to can answer "who changed this, when, from where, and what did it say before?"
> — and "who signed in, who failed to, and who was given access?" — without reading log files or
> the database.
>
> ### Every change, with its before and after
>
> Entries, assets, users, categories, Craft Commerce orders and products, and any element type a
> plugin adds: created, saved, deleted, restored and moved, with the value each field had before and
> after. A form that re-posts every field is recorded as changing only what actually changed.
> Drafts and autosaves stay out of the way; publishing is what gets recorded.
>
> ### Every security event in the same log
>
> Sign-ins, failed sign-ins with the real reason, lock-outs, password and email changes, two-step
> and passkey methods, group and permission changes, admin rights given or taken, and one person
> signing in as another — with every row in that session naming both.
>
> ### Wherever it came from
>
> The control panel, a form on the site, a console command or a queue job, each tagged with its
> route, command or job, and the IP address and browser for people allowed to see them. A resave of
> every entry is one row describing the batch, not a thousand.
>
> ### Fast, and never in the way
>
> A page that changes nothing does no auditing work at all. Changes are written once, at the end of
> the request, and only if they were committed — a save that fails leaves no trace. A resave of
> 1,000 entries measured under 1% slower. If recording ever fails, the save or sign-in carries on.
>
> ### Pro: history where people work
>
> - **History** in every entry's, asset's, product's and user's action menu: each publish, with its
>   blocks' changes beneath it, and the batches it was part of.
> - **Activity** on every user: what they changed, and what happened to their account.
>
> ### Personal data, handled
>
> Redact fields whose values should never be kept, set how long rows are kept, and when a user is
> deleted their name leaves the log while the record of what happened stays. Settings live in
> `config/auditor.php`, versioned with your code.

## Screenshots to take (Sam)

1. The log, filtered to one week, with content, sign-in and access rows.
2. A save's detail slideout: Field / Before / After.
3. A Commerce order's detail: Line items and Adjustments tables.
4. History on an entry (Pro), with a nested block's changes indented and a batch row.
5. A user's Activity (Pro), Account events tab.

LLL's pilot (spec 7.2) has the rows for 1, 2, 4 and 5; 3 needs a Commerce site.

## Copy for review (Appendix A #2)

The control-panel copy is all in `src/translations/en/auditor.php`. These are the points the build
flagged as worth a decision:

| Where | Today | Question |
|---|---|---|
| A failed sign-in's reason | The raw code, e.g. `invalid_credentials`, `account_locked` | Map each to words? |
| A nested entry with no title | "Entry 57113" | Name the owner and field instead? |
| Own Activity's first tab | "Changes they made" | "Changes you made" on one's own? |
| A failed sign-in for an unknown username | Actor "System" | Something clearer, e.g. "Unknown"? |
| A batch in History | "Included in a re-save of N entries" / "Included in a batch of N entries (Resave elements)" | Wording |
| Deleted user label | "Deleted user #123", stored in English | Fine as an English fixed label? |

Behaviour amended during the build that Sam has not yet seen (each is in the spec's change log):

- BR-14: a batched job Craft splits over several queue runs writes one batch row per run, not one.
- BR-13: an index action on one element is that element's own row, not a batch.
- BR-21: two-step methods added by other plugins are not recorded.
- BR-26: a user restored from the trash stays anonymised; rows *about* a deleted user keep their
  name as the subject label (a privacy gap you may want closed); `anonymise-actor` refuses a live user.
- BR-28: Craft's own "Access Auditor" permission is the view-log permission; the Pro permissions are
  offered only on Pro.
- BR-32: the Activity permission is the whole gate (no per-element view check, unlike History).
- BR-9 / BR-23: variants track stored base prices, not stock; a cart's `setEmail()` customer account
  is recorded as an ordinary user creation.

## Before submitting

- [ ] Prices for Lite and Pro (Appendix A #1)
- [ ] Copy reviewed — the table above and the translations file (Appendix A #2)
- [ ] GitHub repo, Packagist and the Plugin Store plugin created (Appendix A #3)
- [ ] Icon approved
- [ ] Screenshots taken
- [ ] Privacy-notice question for LLL answered before the pilot leaves local (Appendix A #4)
- [ ] `1.0.0` tagged with a matching `CHANGELOG.md` heading
