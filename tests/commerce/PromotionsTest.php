<?php

namespace webdna\auditor\tests\commerce;

use Codeception\Test\Unit;
use craft\commerce\elements\conditions\purchasables\SkuConditionRule;
use craft\commerce\models\CatalogPricingRule;
use craft\commerce\models\Coupon;
use craft\commerce\models\Discount;
use craft\commerce\Plugin as Commerce;
use craft\commerce\records\CatalogPricingRule as CatalogPricingRuleRecord;
use craft\db\Connection;
use craft\helpers\StringHelper;
use DateTime;
use DateTimeZone;
use webdna\auditor\Auditor;
use webdna\auditor\db\Table;
use webdna\auditor\models\Event;
use webdna\auditor\services\Recorder;
use webdna\auditor\tests\integration\SecondConnection;
use webdna\auditor\watchers\ElementWatcher;
use webdna\auditor\watchers\PromotionsWatcher;

/**
 * Catalog pricing rules and discounts with Commerce installed (BR-40, BR-7, BR-10): created with
 * their values, saved with what changed, deleted; a save that changes nothing, or one rolled back,
 * writes nothing; coupon codes are counted, never recorded.
 *
 * The recorder is on a second connection, as in OrdersTest, so a row is ready as soon as it is
 * recorded, and a transaction on that connection stands for the request's.
 */
class PromotionsTest extends Unit
{
    use SecondConnection;

    private Auditor $plugin;

    private Recorder $recorder;

    private Connection $db;

    /**
     * @var array<string, mixed>
     */
    private array $settings;

    /**
     * @var string[] uids of rows a test flushed to the table, removed afterwards
     */
    private array $written = [];

    protected function _before(): void
    {
        $this->plugin = Auditor::getInstance();
        $this->settings = $this->plugin->getSettings()->toArray();
        $this->db = $this->secondConnection();
        $this->recorder = new Recorder(['db' => $this->db]);
        $this->plugin->set('recorder', $this->recorder);
    }

    protected function _after(): void
    {
        $this->clear();
        if ($this->written) {
            $this->db->createCommand()->delete(Table::EVENTS, ['uid' => $this->written])->execute();
        }
        $this->db->close();
        $this->plugin->set('recorder', Recorder::class);
        $this->plugin->getSettings()->setAttributes($this->settings, false);
    }

    public function testANewRuleIsCreatedWithItsValues(): void
    {
        $rule = $this->rule('Spring sale');

        $row = $this->onlyRow();
        $this->assertSame(Event::FAMILY_ELEMENT, $row->family);
        $this->assertSame(ElementWatcher::EVENT_CREATED, $row->event);
        $this->assertSame(PromotionsWatcher::PRICING_RULE, $row->elementType);
        $this->assertNull($row->elementId, 'a rule is not an element');
        $this->assertSame('Spring sale', $row->elementLabel);
        $this->assertSame($rule->id, $row->data['id']);

        $this->assertSame(['label' => 'Name', 'old' => null, 'new' => 'Spring sale'], $row->data['name']);
        $this->assertSame('byPercent', $row->data['apply']['new']);
        $this->assertSame(-0.1, $row->data['applyAmount']['new']);
        $this->assertSame(true, $row->data['enabled']['new']);
        $this->assertSame(Commerce::getInstance()->getStores()->getPrimaryStore()->getName(), $row->data['storeId']['new'], 'the store by name');
        $this->assertSame([['class' => SkuConditionRule::class, 'operator' => '=', 'value' => 'BOOT-1']], array_map(
            fn(array $r) => array_intersect_key($r, ['class' => 1, 'operator' => 1, 'value' => 1]),
            $row->data['purchasableCondition']['new'],
        ), 'the purchasable condition by its rules');
        $this->assertArrayNotHasKey('customerCondition', $row->data, 'an empty condition is not a value');
        $this->assertArrayNotHasKey('isPromotionalPrice', $row->data, 'off is empty');
        $this->assertArrayNotHasKey('metadata', $row->data);
        $this->assertArrayNotHasKey('dateCreated', $row->data);

        $this->assertSame('Catalog pricing rule', $this->plugin->getEvents()->typeLabel($row->elementType));

        // #3: a set value reads in words; a condition's rules read one by one.
        $changes = array_column($this->plugin->getEvents()->describe($row)['changes'], null, 'key');
        $this->assertSame('Reduce the price by a percentage of the original price', $changes['apply']['new']);
        $this->assertSame(['Item 1'], array_column($changes['purchasableCondition']['keys'] ?? [], 'key'));
    }

    /**
     * Each value BR-40 names that a save changed, before and after; nothing else.
     */
    public function testASavedRuleRecordsEachChange(): void
    {
        $rule = $this->rule('Spring sale');
        $this->clear();

        $rule->name = 'Summer sale';
        $rule->description = 'Ten percent off boots';
        $rule->applyAmount = -0.25;
        $rule->applyPriceType = CatalogPricingRuleRecord::APPLY_PRICE_TYPE_PROMOTIONAL_PRICE;
        $rule->isPromotionalPrice = true;
        $rule->enabled = false;
        $rule->dateFrom = new DateTime('2026-11-01 09:00:00', new DateTimeZone('UTC'));
        $rule->setPurchasableCondition($this->skuCondition('BOOT-2'));
        $this->saveRule($rule);

        $row = $this->onlyRow();
        $this->assertSame(ElementWatcher::EVENT_SAVED, $row->event);
        $this->assertSame('Summer sale', $row->elementLabel);
        $data = $row->data;
        $this->assertSame($rule->id, $data['id']);
        unset($data['id']);
        $this->assertEqualsCanonicalizing(
            ['name', 'description', 'applyAmount', 'applyPriceType', 'isPromotionalPrice', 'enabled', 'dateFrom', 'purchasableCondition'],
            array_keys($data),
        );
        $this->assertSame(['label' => 'Name', 'old' => 'Spring sale', 'new' => 'Summer sale'], $data['name']);
        $this->assertSame(['label' => 'Amount', 'old' => -0.1, 'new' => -0.25], $data['applyAmount']);
        $this->assertSame(['label' => 'Enabled', 'old' => true, 'new' => false], $data['enabled']);
        $this->assertSame(['label' => 'Start date', 'old' => null, 'new' => '2026-11-01 09:00:00'], $data['dateFrom']);
        $this->assertSame('BOOT-1', $data['purchasableCondition']['old'][0]['value']);
        $this->assertSame('BOOT-2', $data['purchasableCondition']['new'][0]['value']);
    }

    /**
     * BR-7: saved again as it is — its decimal read back as `-0.1000`, its conditions given new
     * uids — the rule writes no row.
     */
    public function testARuleSavedWithNothingChangedWritesNothing(): void
    {
        $rule = $this->rule('Spring sale');
        $this->clear();

        $again = Commerce::getInstance()->getCatalogPricingRules()->getCatalogPricingRuleById($rule->id, $rule->storeId);
        $this->assertNotNull($again);
        $again->setPurchasableCondition($this->skuCondition('BOOT-1'));
        $this->saveRule($again);

        $this->assertSame([], $this->recorder->getReady());
    }

    /**
     * BR-4: a save in a transaction that rolls back writes nothing.
     */
    public function testARolledBackSaveWritesNothing(): void
    {
        $transaction = $this->db->beginTransaction();
        $this->rule('Spring sale');
        $this->assertNotEmpty($this->recorder->getPending());
        $transaction->rollBack();

        $this->assertSame([], $this->recorder->getPending());
        $this->assertSame([], $this->recorder->getReady());
    }

    public function testADeletedRuleIsRecorded(): void
    {
        $rule = $this->rule('Spring sale');
        $this->clear();

        $this->assertTrue(Commerce::getInstance()->getCatalogPricingRules()->deleteCatalogPricingRuleById($rule->id));

        $row = $this->onlyRow();
        $this->assertSame(ElementWatcher::EVENT_DELETED, $row->event);
        $this->assertSame('Spring sale', $row->elementLabel);
        $this->assertSame(['id' => $rule->id], $row->data);
    }

    /**
     * A discount, its own settings among the values, its coupon codes counted and never named.
     */
    public function testADiscountIsCreatedWithItsCouponsCounted(): void
    {
        $discount = $this->discount('Welcome', ['WELCOME-A', 'WELCOME-B']);

        $row = $this->onlyRow();
        $this->assertSame(ElementWatcher::EVENT_CREATED, $row->event);
        $this->assertSame(PromotionsWatcher::DISCOUNT, $row->elementType);
        $this->assertNull($row->elementId);
        $this->assertSame('Welcome', $row->elementLabel);
        $this->assertSame($discount->id, $row->data['id']);
        $this->assertSame(-5, $row->data['baseDiscount']['new']);
        $this->assertSame(true, $row->data['stopProcessing']['new']);
        $this->assertSame(3, $row->data['perUserLimit']['new']);
        $this->assertSame(true, $row->data['requireCouponCode']['new']);
        $this->assertSame(['label' => 'Coupon codes', 'added' => 2, 'removed' => 0], $row->data[PromotionsWatcher::COUPONS]);
        $this->assertArrayNotHasKey('sortOrder', $row->data);
        $this->assertStringNotContainsString('WELCOME-', (string)json_encode($row->data), 'no code is recorded');

        $described = $this->plugin->getEvents()->describe($row);
        $coupons = array_values(array_filter($described['changes'], fn(array $c) => $c['key'] === PromotionsWatcher::COUPONS));
        $this->assertSame('2 added', $coupons[0]['new'] ?? null);
        $this->assertContains('id', array_column($described['details'], 'key'), 'the id is a detail, not a change');
    }

    public function testASavedDiscountRecordsItsChangesAndItsCoupons(): void
    {
        $discount = $this->discount('Welcome', ['WELCOME-A', 'WELCOME-B']);
        $this->clear();

        $discount = Commerce::getInstance()->getDiscounts()->getDiscountById($discount->id);
        $this->assertNotNull($discount);
        $discount->stopProcessing = false;
        $discount->perUserLimit = 1;
        $coupons = $discount->getCoupons();
        $coupons = array_values(array_filter($coupons, fn(Coupon $c) => $c->code !== 'WELCOME-B'));
        $coupons[] = new Coupon(['code' => 'WELCOME-C']);
        $coupons[] = new Coupon(['code' => 'WELCOME-D']);
        $discount->setCoupons($coupons);
        $this->saveDiscount($discount);

        $row = $this->onlyRow();
        $this->assertSame(ElementWatcher::EVENT_SAVED, $row->event);
        $this->assertSame(['label' => 'Stops later discounts', 'old' => true, 'new' => false], $row->data['stopProcessing']);
        $this->assertSame(['label' => 'Per user limit', 'old' => 3, 'new' => 1], $row->data['perUserLimit']);
        $this->assertSame(['label' => 'Coupon codes', 'added' => 2, 'removed' => 1], $row->data[PromotionsWatcher::COUPONS]);
        $described = array_column($this->plugin->getEvents()->describe($row)['changes'], 'new', 'key');
        $this->assertSame('2 added, 1 removed', $described[PromotionsWatcher::COUPONS] ?? null);
    }

    /**
     * A discount saved with only its coupon codes changed has a row that says so; saved with
     * nothing changed at all, none.
     */
    public function testADiscountsCouponsAloneMakeARow(): void
    {
        $discount = $this->discount('Welcome', ['WELCOME-A']);
        $this->clear();

        $discount = Commerce::getInstance()->getDiscounts()->getDiscountById($discount->id);
        $this->assertNotNull($discount);
        $this->saveDiscount($discount);
        $this->assertSame([], $this->recorder->getReady(), 'nothing changed, no row');

        $discount->setCoupons([...$discount->getCoupons(), new Coupon(['code' => 'WELCOME-B'])]);
        $this->saveDiscount($discount);
        $row = $this->onlyRow();
        $this->assertSame(['id' => $discount->id, PromotionsWatcher::COUPONS => ['label' => 'Coupon codes', 'added' => 1, 'removed' => 0]], $row->data);
        $this->assertSame('Welcome', $row->elementLabel);
    }

    public function testADeletedDiscountIsRecordedAndARedactedValueIsNot(): void
    {
        $this->plugin->getSettings()->redact = [...$this->plugin->getSettings()->redact, 'baseDiscount'];
        $discount = $this->discount('Welcome', []);
        $this->assertSame(['label' => 'Base discount', 'redacted' => true], $this->onlyRow()->data['baseDiscount'], 'BR-10');
        $this->clear();

        $this->assertTrue(Commerce::getInstance()->getDiscounts()->deleteDiscountById($discount->id));
        $row = $this->onlyRow();
        $this->assertSame(ElementWatcher::EVENT_DELETED, $row->event);
        $this->assertSame(['id' => $discount->id], $row->data);
    }

    public function testTheLogOffersBothTypes(): void
    {
        $types = array_column($this->plugin->getEvents()->filterOptions()['elementType'], 'label', 'value');
        $this->assertSame('Catalog pricing rules', $types[PromotionsWatcher::PRICING_RULE] ?? null);
        $this->assertSame('Discounts', $types[PromotionsWatcher::DISCOUNT] ?? null);
    }

    private function rule(string $name): CatalogPricingRule
    {
        $rule = new CatalogPricingRule([
            'name' => $name,
            'storeId' => Commerce::getInstance()->getStores()->getPrimaryStore()->id,
            'apply' => 'byPercent',
            'applyAmount' => -0.1,
            'applyPriceType' => CatalogPricingRuleRecord::APPLY_PRICE_TYPE_PRICE,
            'enabled' => true,
        ]);
        $rule->setPurchasableCondition($this->skuCondition('BOOT-1'));
        $this->saveRule($rule);

        return $rule;
    }

    /**
     * @return array<string, mixed>
     */
    private function skuCondition(string $sku): array
    {
        return ['conditionRules' => [[
            'class' => SkuConditionRule::class,
            'uid' => StringHelper::UUID(),
            'operator' => '=',
            'value' => $sku,
        ]]];
    }

    private function saveRule(CatalogPricingRule $rule): void
    {
        $this->assertTrue(Commerce::getInstance()->getCatalogPricingRules()->saveCatalogPricingRule($rule), implode(' ', $rule->getFirstErrors()));
    }

    /**
     * @param string[] $codes
     */
    private function discount(string $name, array $codes): Discount
    {
        $discount = new Discount([
            'name' => $name,
            'storeId' => Commerce::getInstance()->getStores()->getPrimaryStore()->id,
            'enabled' => true,
            'baseDiscount' => -5,
            'stopProcessing' => true,
            'perUserLimit' => 3,
            'requireCouponCode' => $codes !== [],
            'couponFormat' => '######',
            'allPurchasables' => true,
            'allCategories' => true,
        ]);
        $discount->setCoupons(array_map(fn(string $code) => new Coupon(['code' => $code]), $codes));
        $this->saveDiscount($discount);

        return $discount;
    }

    private function saveDiscount(Discount $discount): void
    {
        $this->assertTrue(Commerce::getInstance()->getDiscounts()->saveDiscount($discount), implode(' ', $discount->getFirstErrors()));
    }

    private function onlyRow(): Event
    {
        $rows = $this->recorder->getReady();
        $this->assertCount(1, $rows, 'one row');

        return $rows[0];
    }

    private function clear(): void
    {
        foreach ($this->recorder->getReady() as $row) {
            $this->written[] = (string)$row->uid;
        }
        $this->recorder->flush();
    }
}
