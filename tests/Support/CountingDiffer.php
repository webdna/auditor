<?php

namespace webdna\auditor\tests\Support;

use craft\base\ElementInterface;
use webdna\auditor\models\Snapshot;
use webdna\auditor\services\Differ;

/**
 * The real differ, counting the snapshots it is asked for — each one a read of stored rows.
 */
class CountingDiffer extends Differ
{
    public int $snapshots = 0;

    public function snapshot(ElementInterface $element): ?Snapshot
    {
        $this->snapshots++;

        return parent::snapshot($element);
    }
}
