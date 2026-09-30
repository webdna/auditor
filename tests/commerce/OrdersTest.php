<?php

namespace webdna\auditor\tests\commerce;

use Codeception\Test\Unit;
use Craft;
use craft\commerce\elements\Order;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\fieldlayoutelements\ProductTitleField;
use craft\commerce\models\LineItemStatus;
use craft\commerce\models\OrderAdjustment;
use craft\commerce\models\ProductType;
use craft\commerce\models\ProductTypeSite;
use craft\commerce\Plugin as Commerce;
use craft\db\Connection;
use craft\elements\Address;
use craft\helpers\ElementHelper;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use webdna\auditor\Auditor;
use webdna\auditor\db\Table;
use webdna\auditor\models\Event;
use webdna\auditor\services\Differ;
use webdna\auditor\services\Recorder;
use webdna\auditor\tests\integration\SecondConnection;
use webdna\auditor\watchers\CommerceWatcher;
use webdna\auditor\watchers\ElementWatcher;
use yii\base\Event as YiiEvent;

/**
 * Commerce orders with Commerce installed (TS-10, BR-9, BR-23, BR-24): a completed order's line
 * item and adjustment changes are on its row, carts and what they own are never recorded, and
 * variants track their SKU and prices.
 *
 * Runs only in the Commerce leg (tests/commerce/run.sh); TN-14, without Commerce, is in the
 * integration suite. As in the integration suite, the recorder is swapped for one on a second
 * connection, so the rows a save records are ready at once.
 */
class OrdersTest extends Unit
{
    use SecondConnection;

    private Auditor $plugin;

    private Recorder $recorder;

    private Connection $db;

    private ProductType $type;

    /**
     * @var string[] uids of rows a test flushed to the table, removed afterwards
     */
    private array $written = [];

    protected function _before(): void
    {
        $this->plugin = Auditor::getInstance();
        Craft::$app->getProjectConfig()->writeYamlAutomatically = false;

        $this->db = $this->secondConnection();
        $this->recorder = new Recorder(['db' => $this->db]);
        $this->plugin->set('recorder', $this->recorder);
        $this->plugin->set('differ', Differ::class);

        $this->type = $this->productType();
        // Commerce creates its store's location address the first time a cart is recalculated: a
        // real element, recorded, but not the cart's. Made here so no test mistakes it for one.
        Commerce::getInstance()->getStores()->getPrimaryStore()->getSettings()->getLocationAddress();
    }

    protected function _after(): void
    {
        if ($this->written) {
            $this->db->createCommand()->delete(Table::EVENTS, ['uid' => $this->written])->execute();
        }
        $this->db->close();
        $this->plugin->set('recorder', Recorder::class);
        $this->plugin->set('differ', Differ::class);
    }

    public function testTheWatcherIsAttachedWithCommerce(): void
    {
        $this->assertTrue(CommerceWatcher::applies(), 'this leg runs with Commerce');
        $this->assertTrue(YiiEvent::hasHandlers(Differ::class, Differ::EVENT_DEFINE_ELEMENT_SNAPSHOT));
    }

    /**
     * TS-10 steps 1-2: a completed order's first line item goes from 1 to 3 and its second is
     * removed; the row says both, and nothing else about the line items.
     */
    public function testALineItemChangedAndOneRemovedAreBothOnTheRow(): void
    {
        [$boot, $cap] = $this->variants();
        $order = $this->completedOrder([$boot, $cap]);
        $this->clear();

        [$first, $second] = $order->getLineItems();
        $first->qty = 3;
        $order->removeLineItem($second);
        $this->save($order);
        $this->assertNotSame($first->sku, $second->sku);

        $row = $this->onlyRowFor($order);
        $this->assertSame(ElementWatcher::EVENT_SAVED, $row->event);
        $lineItems = $row->data[CommerceWatcher::LINE_ITEMS] ?? null;
        $this->assertIsArray($lineItems, 'the row carries lineItems');
        $this->assertSame('Line items', $lineItems['label']);
        $this->assertSame([], $lineItems['added']);

        $this->assertCount(1, $lineItems['changed']);
        $this->assertSame((int)$first->id, $lineItems['changed'][0]['id']);
        $this->assertSame(['qty' => ['old' => 1, 'new' => 3]], $lineItems['changed'][0]['changes'], 'only the quantity changed');

        $this->assertCount(1, $lineItems['removed']);
        $this->assertSame((int)$second->id, $lineItems['removed'][0]['id']);
        $this->assertSame($second->sku, $lineItems['removed'][0]['values']['sku']);
        $this->assertSame(1, $lineItems['removed'][0]['values']['qty']);

        $this->assertArrayNotHasKey(CommerceWatcher::ADJUSTMENTS, $row->data, 'no adjustment changed');
    }

    /**
     * A line item added to a completed order, with a note and a status, is listed with its values;
     * a status is recorded by name.
     */
    public function testALineItemAddedIsListedWithItsValues(): void
    {
        [$boot, $cap] = $this->variants();
        $order = $this->completedOrder([$boot]);
        $this->clear();

        $status = new LineItemStatus(['name' => 'Packed', 'handle' => 'packed' . bin2hex(random_bytes(3)), 'storeId' => $order->storeId]);
        $this->assertTrue(Commerce::getInstance()->getLineItemStatuses()->saveLineItemStatus($status), implode(' ', $status->getFirstErrors()));
        $item = Commerce::getInstance()->getLineItems()->create($order, ['purchasableId' => $cap->id, 'qty' => 2, 'note' => 'Gift wrap']);
        $item->lineItemStatusId = $status->id;
        $order->addLineItem($item);
        $this->save($order);

        $added = $this->onlyRowFor($order)->data[CommerceWatcher::LINE_ITEMS]['added'] ?? [];
        $this->assertCount(1, $added);
        $this->assertSame($cap->sku, $added[0]['values']['sku']);
        $this->assertSame(2, $added[0]['values']['qty']);
        $this->assertSame('Gift wrap', $added[0]['values']['note']);
        $this->assertSame((int)$cap->id, $added[0]['values']['purchasableId']);
        $this->assertSame('Packed', $added[0]['values']['status'], 'a status by its name');
    }

    /**
     * Adjustments added, changed and removed on a completed order. One written again under a new
     * id with the same type and name reads as a change, as Commerce's recalculation does it.
     */
    public function testAdjustmentsAddedChangedAndRemoved(): void
    {
        [$boot] = $this->variants();
        $order = $this->completedOrder([$boot]);
        $this->clear();

        $order->setAdjustments([$this->adjustment($order, 'Goodwill', -5)]);
        $this->save($order);
        $added = $this->onlyRowFor($order)->data[CommerceWatcher::ADJUSTMENTS] ?? null;
        $this->assertIsArray($added);
        $this->assertCount(1, $added['added']);
        $this->assertSame(['type' => 'discount', 'name' => 'Goodwill', 'amount' => -5.0, 'included' => false, 'lineItem' => null], $added['added'][0]['values']);
        $this->clear();

        // Replaced by a new adjustment row: same type and name, a new amount.
        $order->setAdjustments([$this->adjustment($order, 'Goodwill', -7.5)]);
        $this->save($order);
        $changed = $this->onlyRowFor($order)->data[CommerceWatcher::ADJUSTMENTS] ?? null;
        $this->assertIsArray($changed);
        $this->assertSame([], $changed['added']);
        $this->assertSame([], $changed['removed']);
        $this->assertSame(['amount' => ['old' => -5.0, 'new' => -7.5]], $changed['changed'][0]['changes']);
        $this->clear();

        $order->setAdjustments([]);
        $this->save($order);
        $removed = $this->onlyRowFor($order)->data[CommerceWatcher::ADJUSTMENTS] ?? null;
        $this->assertIsArray($removed);
        $this->assertCount(1, $removed['removed']);
        $this->assertSame('Goodwill', $removed['removed'][0]['label']);
    }

    /**
     * TS-10 step 3, and BR-23: a cart is never recorded — its creation, its line items, its
     * addresses, its deletion. The save that completes it is its first row.
     */
    public function testACartIsNeverRecordedAndItsCompletionIsTheFirstRow(): void
    {
        [$boot] = $this->variants();
        $order = $this->cart();
        $this->clear();
        $order->addLineItem(Commerce::getInstance()->getLineItems()->create($order, ['purchasableId' => $boot->id]));
        $this->save($order);

        $address = new Address(['countryCode' => 'GB', 'addressLine1' => '1 High Street', 'locality' => 'Bath', 'postalCode' => 'BA1 1AA']);
        $address->setOwner($order);
        $order->setShippingAddress($address);
        $this->save($order);
        $this->assertSame([], $this->recorder->getReady(), 'TS-10 step 3: a cart, and what it owns, records nothing');

        $this->assertTrue($order->markAsComplete());
        $rows = $this->recorder->getReady();
        $orderRows = array_values(array_filter($rows, fn(Event $row) => $row->elementId === (int)$order->id));
        $this->assertCount(1, $orderRows, 'completing the order is its first row');
        $this->assertSame(ElementWatcher::EVENT_SAVED, $orderRows[0]->event);
        $this->assertArrayHasKey('orderStatusId', $orderRows[0]->data ?? [], 'it takes its first status');
        $this->assertSame(Order::class, $orderRows[0]->elementType);

        $cart = $this->cart();
        $this->save($cart);
        $this->clear();
        $this->assertTrue(Craft::$app->getElements()->deleteElement($cart, true));
        $this->assertSame([], $this->recorder->getReady(), 'a deleted cart records nothing');
    }

    /**
     * BR-9: a variant's SKU and prices, and a product's title, are tracked attributes.
     */
    public function testVariantAndProductAttributesAreTracked(): void
    {
        [$boot] = $this->variants();
        $this->clear();

        $boot->sku = $boot->sku . '-B';
        $boot->basePrice = 12.5;
        $boot->basePromotionalPrice = 9;
        $this->save($boot);

        $row = $this->onlyRowFor($boot);
        $this->assertSame(Variant::class, $row->elementType);
        $this->assertSame(['old' => 10.0, 'new' => 12.5], array_intersect_key($row->data['basePrice'] ?? [], ['old' => 1, 'new' => 1]));
        $this->assertSame(9.0, (float)($row->data['basePromotionalPrice']['new'] ?? 0));
        $this->assertStringEndsWith('-B', (string)($row->data['sku']['new'] ?? ''));
        $this->assertArrayNotHasKey('stock', $row->data, 'stock is not an attribute of a save');

        /** @var Product $product */
        $product = $boot->getOwner();
        $product->title = 'Harbour boot, waxed';
        $this->clear();
        $this->save($product);
        $productRow = $this->onlyRowFor($product);
        $this->assertSame(['label' => 'Title', 'old' => 'Harbour boot', 'new' => 'Harbour boot, waxed'], $productRow->data['title'] ?? null);
    }

    /**
     * The detail screen draws line items as a table: one row per item added or removed, one per
     * field changed.
     */
    public function testTheDetailDrawsTheLineItemsTable(): void
    {
        [$boot, $cap] = $this->variants();
        $order = $this->completedOrder([$boot, $cap]);
        $this->clear();

        [$first, $second] = $order->getLineItems();
        $first->qty = 3;
        $order->removeLineItem($second);
        $this->save($order);

        $described = $this->plugin->getEvents()->describe($this->onlyRowFor($order));
        $this->assertCount(1, $described['collections']);
        $table = $described['collections'][0];
        $this->assertSame(CommerceWatcher::LINE_ITEMS, $table['key']);
        $this->assertSame(
            [['Removed', true, ''], ['Quantity', '1', '3']],
            array_map(fn(array $row) => [$row['change'], $row['change'] === 'Removed' ? str_contains($row['old'], 'Quantity: 1') : $row['old'], $row['new']], $table['rows']),
        );
    }

    /**
     * @param Variant[] $variants
     */
    private function completedOrder(array $variants): Order
    {
        $order = $this->cart();
        foreach ($variants as $variant) {
            $order->addLineItem(Commerce::getInstance()->getLineItems()->create($order, ['purchasableId' => $variant->id, 'qty' => 1]));
        }
        $this->save($order);
        $this->assertCount(count($variants), $order->getLineItems());
        $this->assertTrue($order->markAsComplete(), implode(' ', $order->getFirstErrors()));

        return $order;
    }

    private function cart(): Order
    {
        $order = new Order();
        $order->number = Commerce::getInstance()->getCarts()->generateCartNumber();
        $order->setEmail('shopper-' . bin2hex(random_bytes(3)) . '@example.com');

        return $order;
    }

    private function adjustment(Order $order, string $name, float $amount): OrderAdjustment
    {
        $adjustment = new OrderAdjustment(['type' => 'discount', 'name' => $name, 'description' => $name, 'amount' => $amount]);
        $adjustment->setOrder($order);

        return $adjustment;
    }

    /**
     * One product with two variants, priced 10.
     *
     * @return Variant[]
     */
    private function variants(): array
    {
        $suffix = bin2hex(random_bytes(3));
        $product = new Product([
            'typeId' => $this->type->id,
            'title' => 'Harbour boot',
            'slug' => ElementHelper::generateSlug('Harbour boot ' . $suffix),
        ]);
        $product->setVariants([
            new Variant(['sku' => 'BOOT-' . $suffix, 'basePrice' => 10, 'isDefault' => true]),
            new Variant(['sku' => 'CAP-' . $suffix, 'basePrice' => 10]),
        ]);
        // setVariants() does not mark them dirty, and Craft saves nested elements only when it is.
        $product->setDirtyAttributes(['variants']);
        $this->save($product);

        /** @var Variant[] $variants */
        $variants = Variant::find()->ownerId($product->id)->status(null)->orderBy(['sku' => SORT_ASC])->all();
        $this->assertCount(2, $variants);

        return $variants;
    }

    private function productType(): ProductType
    {
        $handle = 'shoes' . bin2hex(random_bytes(3));
        $type = new ProductType([
            'name' => 'Shoes ' . $handle,
            'handle' => $handle,
            'hasVariantTitleField' => false,
            'variantTitleFormat' => '{sku}',
        ]);
        $layout = new FieldLayout(['type' => Product::class]);
        $layout->setTabs([new FieldLayoutTab(['name' => 'Content', 'layout' => $layout, 'elements' => [new ProductTitleField()]])]);
        $type->setFieldLayout($layout);

        $siteId = (int)Craft::$app->getSites()->getPrimarySite()->id;
        $type->setSiteSettings([$siteId => new ProductTypeSite([
            'siteId' => $siteId,
            'hasUrls' => true,
            'uriFormat' => 'shoes/{slug}',
            'template' => '_product',
        ])]);
        $this->assertTrue(Commerce::getInstance()->getProductTypes()->saveProductType($type), implode(' ', $type->getFirstErrors()));

        return $type;
    }

    private function save(\craft\base\ElementInterface $element): void
    {
        $this->assertTrue(Craft::$app->getElements()->saveElement($element), implode(' ', $element->getFirstErrors()));
    }

    private function onlyRowFor(\craft\base\ElementInterface $element): Event
    {
        $rows = array_values(array_filter($this->recorder->getReady(), fn(Event $row) => $row->elementId === (int)$element->id));
        $this->assertCount(1, $rows, 'one row for the element');

        return $rows[0];
    }

    /**
     * Forgets the rows recorded so far — the test's own setting-up.
     */
    private function clear(): void
    {
        foreach ($this->recorder->getReady() as $row) {
            $this->written[] = (string)$row->uid;
        }
        $this->recorder->flush();
    }
}
