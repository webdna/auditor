<?php

namespace webdna\auditor\tests\integration\jobs;

use craft\queue\BaseJob;
use RuntimeException;
use webdna\auditor\Auditor;
use webdna\auditor\models\Event;

/**
 * A queue job that records rows through the plugin's recorder — optionally inside a transaction of
 * its own that it rolls back as it fails, the way a job whose element saves throw would.
 */
class RecordingJob extends BaseJob
{
    public string $label = '';
    public int $rows = 1;
    public bool $fail = false;

    public function execute($queue): void
    {
        $recorder = Auditor::getInstance()->getRecorder();

        if (!$this->fail) {
            $this->record($this->rows);
            return;
        }

        $transaction = $recorder->getDb()->beginTransaction();
        try {
            $this->record($this->rows);
            throw new RuntimeException('The job failed after recording.');
        } catch (RuntimeException $e) {
            $transaction->rollBack();
            throw $e;
        }
    }

    protected function defaultDescription(): ?string
    {
        return 'Recording job';
    }

    private function record(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            Auditor::getInstance()->getRecorder()->record(new Event([
                'event' => 'saved',
                'elementLabel' => $this->label,
            ]));
        }
    }
}
