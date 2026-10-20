<?php

namespace webdna\auditor\tests\Support;

use Craft;
use craft\base\Element;

/**
 * A plugin's element type as the README's example describes it: its status and rating are kept in
 * a table of its own, `reviews`, one row per element keyed on the element id — not in a field, so
 * Auditor knows nothing of them until the tracked-attributes event names them (BR-9).
 *
 * Its table is the test's to make: {@see self::createTable()} makes it a temporary one, which MySQL
 * creates and drops without committing the harness's transaction.
 */
class Review extends Element
{
    public const TABLE = '{{%reviews}}';

    public ?string $reviewStatus = null;

    public ?int $rating = null;

    public static function displayName(): string
    {
        return 'Review';
    }

    public static function createTable(): void
    {
        Craft::$app->getDb()->createCommand(sprintf(
            'CREATE TEMPORARY TABLE %s ([[id]] INT NOT NULL PRIMARY KEY, [[status]] VARCHAR(20), [[rating]] INT)',
            self::TABLE,
        ))->execute();
    }

    public static function dropTable(): void
    {
        Craft::$app->getDb()->createCommand(sprintf('DROP TEMPORARY TABLE IF EXISTS %s', self::TABLE))->execute();
    }

    public function attributeLabels(): array
    {
        return array_merge(parent::attributeLabels(), [
            'reviewStatus' => 'Review status',
            'rating' => 'Rating',
        ]);
    }

    public function afterSave(bool $isNew): void
    {
        $values = ['status' => $this->reviewStatus, 'rating' => $this->rating];
        $db = Craft::$app->getDb()->createCommand();
        if ($isNew) {
            $db->insert(self::TABLE, ['id' => $this->id] + $values)->execute();
        } else {
            $db->update(self::TABLE, $values, ['id' => $this->id])->execute();
        }

        parent::afterSave($isNew);
    }
}
