<?php

namespace webdna\auditor\migrations;

use craft\db\Migration;
use webdna\auditor\db\Table;

/**
 * Creates the log's tables on a fresh install.
 *
 * Install is not the only way in: a site replacing a different plugin with the same handle keeps
 * that plugin's row, so Craft never runs this class there. `m260930_000000_create_tables` builds
 * the same tables through createTables() for that case (BR-39), so both paths must stay idempotent.
 *
 * @since 1.0.0
 */
class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->createTables();

        return true;
    }

    public function safeDown(): bool
    {
        // Only the tables this plugin created; anything else under the handle is not ours.
        $this->dropTableIfExists(Table::EVENT_ELEMENTS);
        $this->dropTableIfExists(Table::EVENTS);

        return true;
    }

    /**
     * Creates whichever of the two tables is missing, with its indexes and key.
     */
    public function createTables(): void
    {
        // Refreshed: once Craft is installed, tableExists() reads a cached list of table names.
        if (!$this->db->tableExists(Table::EVENTS, true)) {
            // Append-only: no dateUpdated. No foreign keys — a row outlives its subject and actor.
            $this->createTable(Table::EVENTS, [
                'id' => $this->primaryKey(),
                'family' => $this->string(16)->notNull(),
                'event' => $this->string(32)->notNull(),
                'elementId' => $this->integer(),
                'elementType' => $this->string(),
                'siteId' => $this->integer(),
                'elementLabel' => $this->string(255),
                'ownerId' => $this->integer(),
                'actorId' => $this->integer(),
                'actorLabel' => $this->string(255)->notNull(),
                'impersonatorId' => $this->integer(),
                'context' => $this->string(8)->notNull(),
                'contextDetail' => $this->string(255),
                'ip' => $this->string(45),
                'userAgent' => $this->string(255),
                'requestId' => $this->string(36)->notNull(),
                'data' => $this->json(),
                'dateCreated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->createIndex(null, Table::EVENTS, ['elementId', 'dateCreated']);
            $this->createIndex(null, Table::EVENTS, ['ownerId', 'dateCreated']);
            $this->createIndex(null, Table::EVENTS, ['actorId', 'dateCreated']);
            $this->createIndex(null, Table::EVENTS, ['family', 'event', 'dateCreated']);
            $this->createIndex(null, Table::EVENTS, ['dateCreated']);
        }

        if (!$this->db->tableExists(Table::EVENT_ELEMENTS, true)) {
            $this->createTable(Table::EVENT_ELEMENTS, [
                'eventId' => $this->integer()->notNull(),
                'elementId' => $this->integer()->notNull(),
                'siteId' => $this->integer()->notNull(),
                'PRIMARY KEY([[eventId]], [[elementId]], [[siteId]])',
            ]);

            $this->createIndex(null, Table::EVENT_ELEMENTS, ['elementId']);
            // Pruning a batch row prunes its list.
            $this->addForeignKey(null, Table::EVENT_ELEMENTS, ['eventId'], Table::EVENTS, ['id'], 'CASCADE');
        }
    }
}
