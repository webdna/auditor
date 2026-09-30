<?php

namespace webdna\auditor\console\controllers;

use craft\console\Controller;
use webdna\auditor\Auditor;
use yii\console\ExitCode;

/**
 * Removes the log's rows older than the retention period.
 *
 * Craft's garbage collection does the same on its own schedule; this is for doing it now, or for a
 * different period once.
 *
 * @since 1.0.0
 */
class PruneController extends Controller
{
    public $defaultAction = 'index';

    /**
     * @var int|null Remove rows older than this many days, instead of the `retentionDays` setting.
     */
    public ?int $days = null;

    /**
     * @var bool Count the rows that would be removed, and remove none.
     */
    public bool $dryRun = false;

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['days', 'dryRun']);
    }

    /**
     * Removes the rows older than the retention period.
     */
    public function actionIndex(): int
    {
        if ($this->days !== null && $this->days < 1) {
            $this->stderr("--days must be 1 or more.\n");
            return ExitCode::USAGE;
        }

        $days = $this->days ?? Auditor::getInstance()->getSettings()->retentionDays;
        if ($days === 0) {
            $this->stdout("Retention is off (retentionDays is 0): every row is kept.\n");
            return ExitCode::OK;
        }

        $count = Auditor::getInstance()->getRetention()->prune($days, $this->dryRun);
        $this->stdout(sprintf(
            $this->dryRun ? "%d rows older than %d days would be removed.\n" : "%d rows older than %d days removed.\n",
            $count,
            $days,
        ));

        return ExitCode::OK;
    }
}
