<?php

namespace webdna\auditor\tests\unit\helpers;

use Codeception\Test\Unit;
use webdna\auditor\helpers\ItemDiff;

/**
 * Comparing two lists of items (BR-23): paired by id, then by a pairing key, with values compared
 * in canonical form.
 */
class ItemDiffTest extends Unit
{
    public function testItemsArePairedByIdIntoAddedRemovedAndChanged(): void
    {
        $diff = ItemDiff::compare(
            [$this->item(1, 'Boot', ['qty' => 1, 'note' => null]), $this->item(2, 'Cap', ['qty' => 1])],
            [$this->item(1, 'Boot', ['qty' => 3, 'note' => '']), $this->item(3, 'Scarf', ['qty' => 2])],
        );

        $this->assertNotNull($diff);
        $this->assertSame([$this->item(3, 'Scarf', ['qty' => 2])], $diff['added']);
        $this->assertSame([$this->item(2, 'Cap', ['qty' => 1])], $diff['removed']);
        $this->assertSame(
            [['id' => 1, 'label' => 'Boot', 'changes' => ['qty' => ['old' => 1, 'new' => 3]]]],
            $diff['changed'],
            "only the quantity: a null note and an empty one are the same",
        );
    }

    public function testNothingChangedIsNull(): void
    {
        $this->assertNull(ItemDiff::compare([], []));
        $this->assertNull(ItemDiff::compare(
            [$this->item(1, 'Boot', ['options' => ['size' => 9, 'colour' => 'tan'], 'price' => 10.0])],
            [$this->item(1, 'Boot', ['options' => ['colour' => 'tan', 'size' => 9], 'price' => 10])],
        ), 'object keys in any order, and a whole float as its integer');
    }

    public function testARowWrittenAgainUnderANewIdPairsByItsKey(): void
    {
        $byName = fn(array $item): string => (string)$item['values']['name'];

        $diff = ItemDiff::compare(
            [$this->item(10, 'Goodwill', ['name' => 'Goodwill', 'amount' => -5.0]), $this->item(11, 'Tax', ['name' => 'Tax', 'amount' => 2.0])],
            [$this->item(20, 'Goodwill', ['name' => 'Goodwill', 'amount' => -7.5]), $this->item(21, 'Tax', ['name' => 'Tax', 'amount' => 2.0])],
            $byName,
        );

        $this->assertNotNull($diff);
        $this->assertSame([], $diff['added']);
        $this->assertSame([], $diff['removed']);
        $this->assertSame([['id' => 20, 'label' => 'Goodwill', 'changes' => ['amount' => ['old' => -5.0, 'new' => -7.5]]]], $diff['changed']);

        $unpaired = ItemDiff::compare([$this->item(10, 'Goodwill', ['name' => 'Goodwill'])], [$this->item(20, 'Loyalty', ['name' => 'Loyalty'])], $byName);
        $this->assertCount(1, $unpaired['added'] ?? [], 'a different key is an addition…');
        $this->assertCount(1, $unpaired['removed'] ?? [], '…and a removal');

        $this->assertCount(1, ItemDiff::compare([$this->item(10, 'Goodwill', ['name' => 'Goodwill'])], [$this->item(20, 'Goodwill', ['name' => 'Goodwill'])])['added'] ?? [], 'without a key, a new id is an addition');
    }

    /**
     * @param array<string, mixed> $values
     * @return array{id: int, label: string, values: array<string, mixed>}
     */
    private function item(int $id, string $label, array $values): array
    {
        return ['id' => $id, 'label' => $label, 'values' => $values];
    }
}
