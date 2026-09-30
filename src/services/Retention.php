<?php

namespace webdna\auditor\services;

use Craft;
use craft\db\Connection;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\Json;
use DateInterval;
use DateTime;
use webdna\auditor\Auditor;
use webdna\auditor\db\Table;
use webdna\auditor\models\Event;
use webdna\auditor\models\Settings;
use webdna\auditor\watchers\AuthWatcher;
use yii\base\Component;

/**
 * The only two changes the log's rows ever undergo once written (BR-1): removal by age (BR-25) and
 * a deleted user's name taken out of them (BR-26).
 *
 * @since 1.0.0
 */
class Retention extends Component
{
    /**
     * Rows removed per delete statement (BR-25), so pruning a large backlog never holds one long
     * lock on the table.
     */
    public const BATCH_SIZE = 10000;

    /**
     * Failed sign-ins read and rewritten at a time while clearing their usernames (BR-26).
     */
    private const USERNAME_CHUNK = 1000;

    /**
     * Null means Craft's own connection.
     */
    public ?Connection $db = null;

    /**
     * Null means the plugin's settings.
     */
    public ?Settings $settings = null;

    /**
     * Removes the rows older than the retention period, in batches (BR-25). A batch row's list of
     * elements goes with it, by the side table's cascading key.
     *
     * @param int|null $days Null means the `retentionDays` setting; 0 keeps every row.
     * @param bool $dryRun Count the rows that would go, and remove none.
     * @return int The rows removed, or that would be.
     */
    public function prune(?int $days = null, bool $dryRun = false): int
    {
        $days ??= $this->getSettings()->retentionDays;
        if ($days <= 0) {
            return 0;
        }

        $db = $this->getDb();
        $cutoff = Db::prepareDateForDb((new DateTime())->sub(new DateInterval("P{$days}D")));
        $query = (new Query())
            ->from(Table::EVENTS)
            ->where(['<', 'dateCreated', $cutoff]);

        if ($dryRun) {
            return (int)$query->count('*', $db);
        }

        $removed = 0;
        do {
            $ids = (clone $query)
                ->select('id')
                ->orderBy(['id' => SORT_ASC])
                ->limit(self::BATCH_SIZE)
                ->column($db);
            if ($ids !== []) {
                $removed += $db->createCommand()->delete(Table::EVENTS, ['id' => $ids])->execute();
            }
        } while (count($ids) === self::BATCH_SIZE);

        return $removed;
    }

    /**
     * Takes a user's name out of the log (BR-26): every row they made reads "Deleted user #<id>",
     * and the username typed in each failed sign-in against their account is cleared. Their id
     * stays on every row, so the record still holds together.
     *
     * Safe to run again: a row already rewritten is not counted twice.
     *
     * @return int The rows rewritten.
     */
    public function anonymiseActor(int $userId): int
    {
        $db = $this->getDb();
        $label = self::deletedUserLabel($userId);
        $rewritten = 0;

        // Failed sign-ins first, while each row's actor still shows whether the update below will
        // rewrite (and count) it anyway.
        $failures = (new Query())
            ->select(['id', 'actorId', 'actorLabel', 'data'])
            ->from(Table::EVENTS)
            ->where([
                'elementId' => $userId,
                'family' => Event::FAMILY_AUTH,
                'event' => AuthWatcher::EVENT_LOGIN_FAILED,
            ])
            ->orderBy(['id' => SORT_ASC]);

        foreach ($failures->batch(self::USERNAME_CHUNK, $db) as $rows) {
            foreach ($rows as $row) {
                $data = is_string($row['data']) ? Json::decodeIfJson($row['data']) : $row['data'];
                if (!is_array($data) || ($data['username'] ?? null) === null) {
                    continue;
                }
                unset($data['username']);
                // The query builder encodes a JSON column's value itself.
                $db->createCommand()->update(Table::EVENTS, ['data' => $data], ['id' => $row['id']])->execute();

                if ((int)$row['actorId'] !== $userId || $row['actorLabel'] === $label) {
                    $rewritten++;
                }
            }
        }

        $rewritten += $db->createCommand()->update(
            Table::EVENTS,
            ['actorLabel' => $label],
            ['and', ['actorId' => $userId], ['not', ['actorLabel' => $label]]],
        )->execute();

        return $rewritten;
    }

    /**
     * What a deleted user's rows read as their actor. Stored as written, like {@see Event::SYSTEM},
     * so it does not depend on the language the deletion happened to run in.
     */
    public static function deletedUserLabel(int $userId): string
    {
        return sprintf('Deleted user #%d', $userId);
    }

    public function getDb(): Connection
    {
        return $this->db ?? Craft::$app->getDb();
    }

    public function getSettings(): Settings
    {
        return $this->settings ?? Auditor::getInstance()->getSettings();
    }
}
