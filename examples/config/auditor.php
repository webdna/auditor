<?php
/**
 * Auditor settings. Copy this file to your project's `config/auditor.php`.
 *
 * Every value shown is the default, so an empty array behaves exactly like this file. As with any
 * Craft config file, the array may be keyed by environment ('*', 'dev', 'production', …).
 */

return [
    // False turns all recording off. Nothing already logged is removed.
    'enabled' => true,

    // The request contexts recorded: 'cp', 'site', 'console' and 'queue'.
    'contexts' => ['cp', 'site', 'console', 'queue'],

    // Element classes never recorded, e.g. [\craft\elements\Asset::class].
    'excludeElementTypes' => [],

    // Field handles and attribute names logged as "redacted" instead of their value. Fields of
    // Craft's Password type are always redacted, listed here or not.
    'redact' => ['password', 'newPassword', 'verificationCode', 'authKey'],

    // Days a row is kept before garbage collection and `craft auditor/prune` remove it. 0 keeps
    // every row for ever.
    'retentionDays' => 365,

    // A single operation touching more top-level elements than this is logged as one batch row,
    // listing the elements it covered, rather than one row per element.
    'bulkThreshold' => 10,

    // Log each element in a batch as its own row, with its changes, instead of one batch row.
    // Slows resaves and large bulk actions.
    'recordBulkDiffs' => false,

    // Log a save even when no value changed.
    'recordNoChangeSaves' => false,

    // Log sign-ins made by a "Keep me signed in" cookie, not only those made with a password.
    'recordCookieLogins' => false,
];
