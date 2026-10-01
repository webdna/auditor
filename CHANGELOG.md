# Release Notes for Auditor

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
