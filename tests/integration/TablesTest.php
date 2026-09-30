<?php

namespace webdna\auditor\tests\integration;

use Codeception\Test\Unit;
use Craft;
use webdna\auditor\db\Table;
use webdna\auditor\migrations\m260930_000000_create_tables;

/**
 * The log's tables exist after install, and the upgrade migration builds them on a site where
 * Install never ran (BR-39, TN-15).
 *
 * The DDL runs on a second connection: MySQL commits implicitly on DDL, which would end the
 * harness's per-test transaction on the main one.
 */
class TablesTest extends Unit
{
    use SecondConnection;

    private const OLD_TABLE = '{{%auditor_log}}';

    public function testInstallCreatesBothTablesWithTheirColumns(): void
    {
        $db = Craft::$app->getDb();

        $events = $db->getTableSchema(Table::EVENTS, true);
        $this->assertNotNull($events);
        $this->assertSame([
            'id', 'family', 'event', 'elementId', 'elementType', 'siteId', 'elementLabel', 'ownerId',
            'actorId', 'actorLabel', 'impersonatorId', 'context', 'contextDetail', 'ip', 'userAgent',
            'requestId', 'data', 'dateCreated', 'uid',
        ], $events->columnNames);

        $elements = $db->getTableSchema(Table::EVENT_ELEMENTS, true);
        $this->assertNotNull($elements);
        $this->assertSame(['eventId', 'elementId', 'siteId'], $elements->columnNames);
        $this->assertSame(['eventId', 'elementId', 'siteId'], $elements->primaryKey);
    }

    /**
     * TN-15: installed over the older same-handle plugin — its table present, ours absent — the
     * migration creates both of ours, leaves the old one untouched, and is safe to run twice.
     */
    public function testMigrationCreatesTablesOverTheOlderPluginAndLeavesItsTableAlone(): void
    {
        $db = $this->secondConnection();

        try {
            $db->createCommand()->createTable(self::OLD_TABLE, [
                'id' => 'pk',
                'note' => 'string',
            ])->execute();
            $db->createCommand()->insert(self::OLD_TABLE, ['note' => 'from the older plugin'])->execute();
            $oldColumns = $db->getTableSchema(self::OLD_TABLE, true)?->columnNames;

            $db->createCommand()->dropTable(Table::EVENT_ELEMENTS)->execute();
            $db->createCommand()->dropTable(Table::EVENTS)->execute();
            $this->assertFalse($db->tableExists(Table::EVENTS, true));

            $this->assertNotFalse((new m260930_000000_create_tables(['db' => $db]))->up());

            $this->assertTrue($db->tableExists(Table::EVENTS, true));
            $this->assertTrue($db->tableExists(Table::EVENT_ELEMENTS, true));
            $this->assertSame($oldColumns, $db->getTableSchema(self::OLD_TABLE, true)?->columnNames);
            $this->assertSame(
                [['id' => 1, 'note' => 'from the older plugin']],
                array_map(fn(array $row) => ['id' => (int)$row['id'], 'note' => $row['note']],
                    $db->createCommand('SELECT * FROM ' . self::OLD_TABLE)->queryAll()),
            );

            // Idempotent: a second run over existing tables keeps what they hold.
            $db->createCommand()->insert(Table::EVENTS, [
                'family' => 'element',
                'event' => 'saved',
                'actorLabel' => 'System',
                'context' => 'console',
                'requestId' => 'b6f8c1de-0000-4000-8000-000000000000',
                'dateCreated' => '2026-09-30 12:00:00',
                'uid' => 'b6f8c1de-0000-4000-8000-000000000001',
            ])->execute();
            $this->assertNotFalse((new m260930_000000_create_tables(['db' => $db]))->up());
            $this->assertSame('1', (string)$db->createCommand('SELECT COUNT(*) FROM ' . Table::EVENTS)->queryScalar());
        } finally {
            $db->createCommand()->dropTableIfExists(self::OLD_TABLE)->execute();
            // Leave the suite with clean tables whatever happened above.
            $db->createCommand()->dropTableIfExists(Table::EVENT_ELEMENTS)->execute();
            $db->createCommand()->dropTableIfExists(Table::EVENTS)->execute();
            (new m260930_000000_create_tables(['db' => $db]))->up();
            $db->close();
        }
    }
}
