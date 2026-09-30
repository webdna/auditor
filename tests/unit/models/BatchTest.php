<?php

namespace webdna\auditor\tests\unit\models;

use Codeception\Test\Unit;
use craft\elements\Asset;
use craft\elements\Entry;
use webdna\auditor\models\Batch;

/**
 * What a batch counts and lists (BR-13, BR-15): distinct top-level elements, each site it touched
 * listed once, named for its first operation unless it was opened as something.
 */
class BatchTest extends Unit
{
    public function testItCountsElementsAndListsEachSiteOnce(): void
    {
        $batch = new Batch('key');

        $this->assertTrue($batch->cover(1, 1, Entry::class, 'save'));
        $this->assertFalse($batch->cover(1, 2, Entry::class, 'save'), 'Another site of the same element is not counted again');
        $this->assertFalse($batch->cover(1, 1, Entry::class, 'save'));
        $this->assertTrue($batch->cover(2, 1, Entry::class, 'delete'));

        $this->assertSame(2, $batch->count());
        $this->assertSame([[1, 1], [1, 2], [2, 1]], $batch->elements());
        $this->assertSame(['operation' => 'save', 'count' => 2, 'elementType' => Entry::class], $batch->data());
    }

    public function testMixedTypesHaveNoElementType(): void
    {
        $batch = new Batch(Batch::JOB, 'some\\Job', true);
        $batch->cover(1, 1, Entry::class, 'save');
        $batch->cover(2, 1, Asset::class, 'save');

        $this->assertSame(['operation' => 'some\\Job', 'count' => 2, 'elementType' => null], $batch->data());
    }
}
