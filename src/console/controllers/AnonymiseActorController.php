<?php

namespace webdna\auditor\console\controllers;

use craft\console\Controller;
use craft\elements\User;
use webdna\auditor\Auditor;
use yii\console\ExitCode;

/**
 * Takes one deleted user's name out of the log, as deleting them does — for a user deleted
 * before Auditor was installed, or whose rewrite failed. A user who still exists is refused.
 *
 * @since 1.0.0
 */
class AnonymiseActorController extends Controller
{
    public $defaultAction = 'index';

    /**
     * @var int|null The user's id.
     */
    public ?int $user = null;

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['user']);
    }

    /**
     * Rewrites the user's rows to "Deleted user #<id>" and clears the username from failed sign-ins
     * against them.
     */
    public function actionIndex(): int
    {
        if ($this->user === null || $this->user < 1) {
            $this->stderr("Give the user's id: --user=<id>.\n");
            return ExitCode::USAGE;
        }

        // The rewrite cannot be undone, so a mistyped id must not reach a live account.
        if (User::find()->id($this->user)->status(null)->exists()) {
            $this->stderr(sprintf("User #%d still exists. Delete them instead: that takes their name out of the log.\n", $this->user));
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $count = Auditor::getInstance()->getRetention()->anonymiseActor($this->user);
        $this->stdout(sprintf("%d rows rewritten for user #%d.\n", $count, $this->user));

        return ExitCode::OK;
    }
}
