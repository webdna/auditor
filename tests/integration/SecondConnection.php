<?php

namespace webdna\auditor\tests\integration;

use Craft;
use craft\db\Connection;
use yii\base\Event;

/**
 * A database connection of the test's own, outside the harness's per-test transaction.
 *
 * Cloning Craft's connection is not enough. The Yii2 module's `TransactionForcer` listens to every
 * connection opening, and hands one with the same DSN and credentials the PDO handle it cached —
 * the main connection's, inside the harness transaction. Marking the open event handled on the
 * instance stops it reaching that class-level listener, so this connection gets a PDO of its own.
 *
 * Its commits are real, and its DDL cannot end the harness transaction; whatever a test writes
 * through it, the test removes.
 */
trait SecondConnection
{
    private function secondConnection(): Connection
    {
        // Cloning drops the PDO handle and the transaction.
        $db = clone Craft::$app->getDb();
        $db->on(Connection::EVENT_AFTER_OPEN, function(Event $event) {
            $event->handled = true;
        });
        $db->open();

        return $db;
    }
}
