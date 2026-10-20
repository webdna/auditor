# Release Notes for Auditor

## Unreleased

### Fixed
- A redacted value no longer appears through a generated title. When an element's title is made from a title format (no title field) and its layout holds a redacted field, or its type a redacted attribute such as a variant's SKU, its title and slug are recorded as redacted and its rows are named by type and id, such as "Entry #123". ([#4](https://github.com/webdna/auditor/issues/4))

## 1.0.0 - 2026-10-19

### Changed
- A block or other nested element with no title of its own is now named by its type and what it belongs to, such as "Text Block in The spring collection", instead of "Entry 856". Rows recorded before this read the same way.
- Rich-text fields (CKEditor and any other field built on Craft's HTML field) now show their before and after as formatted text in a row's detail, cleaned by Craft's HTML purifier so nothing in them can run. Long ones are cut to a few lines with "Show more".
- A failed sign-in now says why in words, such as "Wrong password" or "Account locked", in the log and in its detail; the detail keeps Craft's own code beside it.
- A failed sign-in with a username nobody has shows "Unknown user" as who did it, instead of "System". Rows recorded before this read the same way.
- The first tab of a user's Activity is now called "Changes made".

## 1.0.0-beta.1 - 2026-10-01

### Added
- One audit log of content changes, sign-ins and access changes, recorded from the control panel, the site, console commands and queue jobs, each tagged with its route, command or job.
- Content changes on every element type: created (with its values), saved (each changed field and tracked attribute, before and after), deleted, restored and moved. Drafts, autosaves, revisions and propagated copies are skipped; publishing a draft is recorded. Nested entries roll up under their owner.
- Before values read from what Craft stored, never by loading the element, so a form that re-posts every field records only what actually changed.
- Batches: a resave, a batched job, a multi-element index action or any operation over `bulkThreshold` elements is one row, listing every element it covered.
- Sign-in events: signed in and out, failed sign-ins with Craft's real reason, locked, unlocked, suspended, unsuspended, activated, deactivated, email changed and verified, password changed and reset required, authenticator apps and passkeys added and removed.
- Access events: groups and permissions changed, admin rights given or taken, user groups created, changed or deleted (including by a project config apply), and one user signing in as another, with every row in that session naming both.
- Craft Commerce: completed orders with their line items and adjustments added, removed and changed; products and variants with their stored prices. Carts are never recorded.
- The log screen, with filters by kind, event, element type, person, context, site and date, a detail slideout per row, and every other row from the same request.
- Pro: History in each element's action menu, and an Activity screen on each user with "Changes they made" and "Account events".
- Permissions to see addresses and browsers, History and Activity. No permission lets anyone edit or delete a row.
- Redaction of named fields and attributes, retention with garbage collection, and deleted users' rows rewritten to "Deleted user #id".
- Console commands `auditor/prune` and `auditor/anonymise-actor`.
- Events: `Differ::EVENT_DEFINE_TRACKED_ATTRIBUTES`, `Differ::EVENT_DEFINE_ELEMENT_SNAPSHOT` and `Recorder::EVENT_BEFORE_RECORD`.
- Rows are written once per request, after the transaction that made them commits; a rolled-back save leaves no row, and a failure to record never stops a save or sign-in.
