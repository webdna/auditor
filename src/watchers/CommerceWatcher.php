<?php

namespace webdna\auditor\watchers;

use Craft;
use craft\base\ElementInterface;
use craft\base\NestedElementInterface;
use craft\db\Query;
use craft\events\ElementEvent;
use craft\helpers\Json;
use craft\services\Elements;
use Throwable;
use webdna\auditor\events\DefineElementSnapshotEvent;
use webdna\auditor\events\DefineTrackedAttributesEvent;
use webdna\auditor\helpers\ItemDiff;
use webdna\auditor\services\Differ;
use yii\base\Event as YiiEvent;

/**
 * What the log knows about Craft Commerce (BR-9, BR-23, BR-24). Attached only while Commerce is
 * enabled, and even then it names Commerce's classes only as strings and reads Commerce's tables
 * directly: no Commerce class is loaded on its account, and on a site without Commerce nothing of
 * it runs at all (TN-14).
 *
 * - **Carts are never recorded.** An order that is not completed, and anything saved or deleted as
 *   part of a cart's save (its addresses), is skipped before any snapshot is taken, so a shopper
 *   filling a cart costs the log nothing. The save that completes an order is its first row.
 * - **A completed order's row carries its line items and adjustments** — added, removed, and
 *   changed — through {@see Differ::EVENT_DEFINE_ELEMENT_SNAPSHOT}. Both sides are read from the
 *   tables, before the save and after it (Commerce writes both inside `Order::afterSave()`, before
 *   Craft's after-save event), so they compare like with like; that is two reads each side.
 * - **Orders, products and variants track their attributes** (BR-9): an order's status, paid
 *   status, email and coupon code; a product's and a variant's title and enabled state; a
 *   variant's SKU and its base and promotional prices. A variant's stock is not an attribute of its
 *   save: Commerce moves stock through inventory transactions, which save nothing, and a variant
 *   save only refreshes a stored summary of them.
 *
 * @since 1.0.0
 */
class CommerceWatcher
{
    public const HANDLE = 'commerce';

    public const ORDER = 'craft\\commerce\\elements\\Order';
    public const PRODUCT = 'craft\\commerce\\elements\\Product';
    public const VARIANT = 'craft\\commerce\\elements\\Variant';

    /**
     * The keys an order's row carries its internals under (§4).
     */
    public const LINE_ITEMS = 'lineItems';
    public const ADJUSTMENTS = 'adjustments';

    private const TABLE_LINE_ITEMS = '{{%commerce_lineitems}}';
    private const TABLE_LINE_ITEM_STATUSES = '{{%commerce_lineitemstatuses}}';
    private const TABLE_ADJUSTMENTS = '{{%commerce_orderadjustments}}';
    private const TABLE_PURCHASABLES_STORES = '{{%commerce_purchasables_stores}}';

    /**
     * The line item fields a change is recorded on, with their labels (in English, as stored).
     */
    private const LINE_ITEM_FIELDS = [
        'purchasableId' => 'Purchasable',
        'sku' => 'SKU',
        'qty' => 'Quantity',
        'salePrice' => 'Sale price',
        'options' => 'Options',
        'note' => 'Note',
        'privateNote' => 'Private note',
        'status' => 'Status',
    ];

    private const ADJUSTMENT_FIELDS = [
        'type' => 'Type',
        'name' => 'Name',
        'amount' => 'Amount',
        'included' => 'Included in price',
        'lineItem' => 'Line item',
    ];

    /**
     * Carts whose save is running, innermost last: what they own is saved inside that save.
     *
     * @var list<ElementInterface>
     */
    private array $carts = [];

    /**
     * Whether Commerce is enabled, asked once as the plugin starts. The stored plugin list is read
     * before any plugin is loaded, so it answers while plugins are still loading.
     */
    public static function applies(): bool
    {
        return Craft::$app->getPlugins()->isPluginEnabled(self::HANDLE);
    }

    public function attach(ElementWatcher $elements): void
    {
        $elements->ignore(fn(ElementInterface $element): bool => $this->ignores($element));
        YiiEvent::on(Elements::class, Elements::EVENT_BEFORE_SAVE_ELEMENT, [$this, 'beforeSave']);
        YiiEvent::on(Elements::class, Elements::EVENT_AFTER_SAVE_ELEMENT, [$this, 'afterSave']);
        YiiEvent::on(Differ::class, Differ::EVENT_DEFINE_TRACKED_ATTRIBUTES, [$this, 'trackedAttributes']);
        YiiEvent::on(Differ::class, Differ::EVENT_DEFINE_ELEMENT_SNAPSHOT, [$this, 'snapshot']);
    }

    /**
     * A cart, or something a cart owns saved inside the cart's own save (BR-23).
     */
    public function ignores(ElementInterface $element): bool
    {
        if (self::isOrder($element)) {
            return !self::isCompleted($element);
        }
        if ($this->carts === [] || !$element instanceof NestedElementInterface) {
            return false;
        }

        $ownerId = $element->getOwnerId();
        foreach ($this->carts as $cart) {
            if ($cart->id !== null && $cart->id === $ownerId) {
                return true;
            }
        }

        return false;
    }

    public function beforeSave(ElementEvent $event): void
    {
        if (self::isOrder($event->element) && !self::isCompleted($event->element)) {
            $this->carts[] = $event->element;
        }
    }

    public function afterSave(ElementEvent $event): void
    {
        $key = array_search($event->element, $this->carts, true);
        if ($key !== false) {
            array_splice($this->carts, $key, 1);
        }
    }

    public function trackedAttributes(DefineTrackedAttributesEvent $event): void
    {
        $type = $event->elementType;

        if (is_a($type, self::ORDER, true)) {
            $event->attributes += [
                'orderStatusId' => 'commerce_orders.orderStatusId',
                'paidStatus' => 'commerce_orders.paidStatus',
                'email' => 'commerce_orders.email',
                'couponCode' => 'commerce_orders.couponCode',
            ];
        } elseif (is_a($type, self::PRODUCT, true)) {
            $event->attributes += [
                'title' => 'elements_sites.title',
                'enabled' => 'elements.enabled',
            ];
        } elseif (is_a($type, self::VARIANT, true)) {
            $event->attributes += [
                'title' => 'elements_sites.title',
                'enabled' => 'elements.enabled',
                'sku' => 'commerce_purchasables.sku',
                'basePrice' => fn(ElementInterface $element): mixed => $this->storedPrice($element, 'basePrice'),
                'basePromotionalPrice' => fn(ElementInterface $element): mixed => $this->storedPrice($element, 'basePromotionalPrice'),
            ];
        }
    }

    /**
     * An order's line items and adjustments: read before the save, then compared after it.
     */
    public function snapshot(DefineElementSnapshotEvent $event): void
    {
        $order = $event->element;
        if (!self::isOrder($order) || !self::isCompleted($order) || !$order->id) {
            return;
        }

        $state = $this->state($order->id);
        if ($event->before === null) {
            $event->values = $state + $event->values;
            return;
        }

        /** @var list<array{id: int, label: string, values: array<string, mixed>}> $oldLineItems */
        $oldLineItems = $event->before[self::LINE_ITEMS] ?? [];
        /** @var list<array{id: int, label: string, values: array<string, mixed>}> $oldAdjustments */
        $oldAdjustments = $event->before[self::ADJUSTMENTS] ?? [];

        $lineItems = ItemDiff::compare($oldLineItems, $state[self::LINE_ITEMS]);
        // Commerce deletes and rewrites adjustments when it recalculates, under new ids.
        $adjustments = ItemDiff::compare(
            $oldAdjustments,
            $state[self::ADJUSTMENTS],
            fn(array $item): string => Json::encode([$item['values']['type'] ?? null, $item['values']['name'] ?? null, $item['values']['lineItem'] ?? null]),
        );

        $event->changes[self::LINE_ITEMS] = $lineItems === null ? [] : [
            'label' => 'Line items',
            'fields' => self::LINE_ITEM_FIELDS,
        ] + $this->withStatusNames($lineItems);
        $event->changes[self::ADJUSTMENTS] = $adjustments === null ? [] : [
            'label' => 'Adjustments',
            'fields' => self::ADJUSTMENT_FIELDS,
        ] + $adjustments;
    }

    public static function isOrder(ElementInterface $element): bool
    {
        return is_a($element, self::ORDER);
    }

    private static function isCompleted(ElementInterface $element): bool
    {
        $property = 'isCompleted';

        return (bool)($element->$property ?? false);
    }

    /**
     * An order's stored line items and adjustments, as items for {@see ItemDiff}.
     *
     * @return array{lineItems: list<array{id: int, label: string, values: array<string, mixed>}>, adjustments: list<array{id: int, label: string, values: array<string, mixed>}>}
     */
    private function state(int $orderId): array
    {
        $lineItems = [];
        $descriptions = [];
        $rows = (new Query())
            ->select(['id', 'description', 'purchasableId', 'sku', 'qty', 'salePrice', 'options', 'note', 'privateNote', 'lineItemStatusId'])
            ->from(self::TABLE_LINE_ITEMS)
            ->where(['orderId' => $orderId])
            ->orderBy(['id' => SORT_ASC])
            ->all();
        foreach ($rows as $row) {
            $id = (int)$row['id'];
            $label = self::text($row['description']) ?? self::text($row['sku']) ?? sprintf('#%d', $id);
            $descriptions[$id] = $label;
            $lineItems[] = [
                'id' => $id,
                'label' => $label,
                'values' => [
                    'purchasableId' => self::id($row['purchasableId']),
                    'sku' => self::text($row['sku']),
                    'qty' => (int)$row['qty'],
                    'salePrice' => (float)$row['salePrice'],
                    'options' => self::options($row['options']),
                    'note' => self::text($row['note']),
                    'privateNote' => self::text($row['privateNote']),
                    'status' => self::id($row['lineItemStatusId']),
                ],
            ];
        }

        $adjustments = [];
        $rows = (new Query())
            ->select(['id', 'lineItemId', 'type', 'name', 'amount', 'included'])
            ->from(self::TABLE_ADJUSTMENTS)
            ->where(['orderId' => $orderId])
            ->orderBy(['id' => SORT_ASC])
            ->all();
        foreach ($rows as $row) {
            $id = (int)$row['id'];
            $lineItemId = self::id($row['lineItemId']);
            $adjustments[] = [
                'id' => $id,
                'label' => self::text($row['name']) ?? (string)$row['type'],
                'values' => [
                    'type' => (string)$row['type'],
                    'name' => self::text($row['name']),
                    'amount' => (float)$row['amount'],
                    'included' => (bool)$row['included'],
                    // What it applies to, in words: its id means nothing once the item is gone.
                    'lineItem' => $lineItemId === null ? null : ($descriptions[$lineItemId] ?? sprintf('#%d', $lineItemId)),
                ],
            ];
        }

        return [self::LINE_ITEMS => $lineItems, self::ADJUSTMENTS => $adjustments];
    }

    /**
     * A line item status recorded by name, as the subject's label is: the status may be renamed or
     * deleted. One read, and only when a status appears in the change.
     *
     * @param array{added: list<array<string, mixed>>, removed: list<array<string, mixed>>, changed: list<array<string, mixed>>} $diff
     * @return array{added: list<array<string, mixed>>, removed: list<array<string, mixed>>, changed: list<array<string, mixed>>}
     */
    private function withStatusNames(array $diff): array
    {
        $ids = [];
        foreach (['added', 'removed'] as $list) {
            foreach ($diff[$list] as $item) {
                $ids[] = $item['values']['status'] ?? null;
            }
        }
        foreach ($diff['changed'] as $item) {
            $ids[] = $item['changes']['status']['old'] ?? null;
            $ids[] = $item['changes']['status']['new'] ?? null;
        }
        $ids = array_values(array_unique(array_filter($ids, 'is_int')));
        if ($ids === []) {
            return $diff;
        }

        $names = array_map('strval', (new Query())
            ->select(['name', 'id'])
            ->from(self::TABLE_LINE_ITEM_STATUSES)
            ->where(['id' => $ids])
            ->indexBy('id')
            ->column());
        $name = fn(mixed $id): mixed => is_int($id) ? ($names[$id] ?? sprintf('#%d', $id)) : $id;

        foreach (['added', 'removed'] as $list) {
            foreach ($diff[$list] as $i => $item) {
                $diff[$list][$i]['values']['status'] = $name($item['values']['status'] ?? null);
            }
        }
        foreach ($diff['changed'] as $i => $item) {
            if (isset($item['changes']['status'])) {
                $diff['changed'][$i]['changes']['status'] = [
                    'old' => $name($item['changes']['status']['old']),
                    'new' => $name($item['changes']['status']['new']),
                ];
            }
        }

        return $diff;
    }

    /**
     * A variant's stored price in the store of the site it is saved for. Commerce resolves and
     * keeps the store itself, so asking it costs no query here.
     */
    private function storedPrice(ElementInterface $element, string $column): ?float
    {
        try {
            $store = method_exists($element, 'getStore') ? $element->getStore() : null;
        } catch (Throwable) {
            return null;
        }
        if (!is_object($store) || !isset($store->id)) {
            return null;
        }

        $price = (new Query())
            ->select([$column])
            ->from(self::TABLE_PURCHASABLES_STORES)
            ->where(['purchasableId' => $element->id, 'storeId' => $store->id])
            ->scalar();

        return $price === null || $price === false ? null : (float)$price;
    }

    private static function text(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string)$value;
    }

    private static function id(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int)$value;
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private static function options(mixed $value): ?array
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        $options = Json::decodeIfJson($value);

        return is_array($options) && $options !== [] ? $options : null;
    }
}
