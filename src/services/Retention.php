<?php

namespace webdna\auditor\services;

use Craft;
use craft\base\ElementInterface;
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
use yii\base\Component;

/**
 * The only two changes the log's rows ever undergo once written (BR-1): removal by age (BR-25) and
 * a deleted user taken out of them (BR-26).
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
     * Rows about a deleted user read and rewritten at a time (BR-26).
     */
    private const REWRITE_CHUNK = 1000;

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
     * Takes a deleted user out of the log (BR-26). Every row they made reads "Deleted user #<id>"
     * as its actor. Every row about them — whose subject is the user, or an element they own, such
     * as their addresses — loses its label and its values: the user's rows read "Deleted user
     * #<id>", an owned element's "<type> #<id>", and each key in `data` keeps only its label,
     * marked `redacted`. The username typed in a failed sign-in against them goes with the rest.
     * Ids stay on every row, so the record still holds together.
     *
     * Safe to run again: a row already rewritten is rewritten, and counted, no more.
     *
     * @return int The rows rewritten, each counted once whether its actor, its subject or both
     * were the user.
     */
    public function anonymiseActor(int $userId): int
    {
        $db = $this->getDb();
        $label = self::deletedUserLabel($userId);
        $rewritten = 0;

        // Rows about them first, taking the actor too where it is them, so the update below does
        // not reach (and count) the same row again. Paged by id, not by a cursor, because each
        // page is rewritten before the next is read.
        $lastId = 0;
        do {
            $rows = (new Query())
                ->select(['id', 'elementId', 'elementType', 'actorId', 'actorLabel', 'elementLabel', 'data'])
                ->from(Table::EVENTS)
                ->where(['or', ['elementId' => $userId], ['ownerId' => $userId]])
                ->andWhere(['>', 'id', $lastId])
                ->orderBy(['id' => SORT_ASC])
                ->limit(self::REWRITE_CHUNK)
                ->all($db);

            foreach ($rows as $row) {
                $lastId = (int)$row['id'];
                $data = is_string($row['data']) ? Json::decodeIfJson($row['data']) : $row['data'];
                $columns = [];

                $elementLabel = (int)$row['elementId'] === $userId
                    ? $label
                    : self::ownedLabel($row['elementType'], (int)$row['elementId']);
                if ($row['elementLabel'] !== $elementLabel) {
                    $columns['elementLabel'] = $elementLabel;
                }
                if (is_array($data)) {
                    $redacted = self::redactValues($data);
                    if ($redacted !== $data) {
                        // The query builder encodes a JSON column's value itself.
                        $columns['data'] = $redacted;
                    }
                }
                if ((int)$row['actorId'] === $userId && $row['actorLabel'] !== $label) {
                    $columns['actorLabel'] = $label;
                }

                if ($columns !== []) {
                    $db->createCommand()->update(Table::EVENTS, $columns, ['id' => $row['id']])->execute();
                    $rewritten++;
                }
            }
        } while (count($rows) === self::REWRITE_CHUNK);

        $rewritten += $db->createCommand()->update(
            Table::EVENTS,
            ['actorLabel' => $label],
            ['and', ['actorId' => $userId], ['not', ['actorLabel' => $label]]],
        )->execute();

        return $rewritten;
    }

    /**
     * A row's `data` with every value replaced by `{redacted: true}` and its key kept — `{label,
     * redacted: true}` where the value had a label, as BR-10 records a redacted change.
     *
     * @param array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    private static function redactValues(array $data): array
    {
        foreach ($data as $key => $value) {
            $data[$key] = is_array($value) && is_string($value['label'] ?? null)
                ? ['label' => $value['label'], 'redacted' => true]
                : ['redacted' => true];
        }

        return $data;
    }

    /**
     * What a row about an element the deleted user owned reads as its subject: its type and id,
     * as a title that could hold a redacted value is (BR-41), in English.
     */
    private static function ownedLabel(?string $type, int $elementId): string
    {
        $name = $type !== null && is_subclass_of($type, ElementInterface::class)
            ? $type::displayName()
            : 'Element';

        return sprintf('%s #%d', $name, $elementId);
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
