<?php

namespace webdna\auditor\migrations;

use craft\db\Migration;

/**
 * Creates the log's tables where Install never ran (BR-39).
 *
 * A site replacing the older plugin that shares this handle already has a plugin row at schema
 * 1.0.0, so Craft skips Install and runs pending migrations instead. On a fresh install Craft marks
 * this migration applied without running it. Either way, the tables exist afterwards; the other
 * plugin's own tables are left exactly as they were.
 */
class m260930_000000_create_tables extends Migration
{
    public function safeUp(): bool
    {
        (new Install(['db' => $this->db]))->createTables();

        return true;
    }

    public function safeDown(): bool
    {
        echo "m260930_000000_create_tables cannot be reverted: the tables hold the site's audit log. Uninstall the plugin to remove them.\n";

        return false;
    }
}
