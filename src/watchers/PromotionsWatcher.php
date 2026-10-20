<?php

namespace webdna\auditor\watchers;

use Craft;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\Inflector;
use craft\helpers\Json;
use Throwable;
use webdna\auditor\Auditor;
use webdna\auditor\models\Event;
use yii\base\Event as YiiEvent;
use yii\db\ActiveRecord;
use yii\db\AfterSaveEvent;
use yii\db\BaseActiveRecord;

/**
 * Commerce's catalog pricing rules and discounts (BR-40). They are models, not elements, and
 * Commerce's catalog pricing rules service raises no events, so both are heard from Commerce's
 * own records as they are written: an update's event carries the old values of what it changed,
 * so a save costs the log no read. Attached by {@see CommerceWatcher}, only while Commerce is
 * enabled, and naming Commerce's classes as strings, as it does (TN-14).
 *
 * - A rule or discount is `created` with its non-empty values, `saved` with each value that
 *   changed, before and after, and `deleted`, in the `element` family, with no subject id: the
 *   row's type is the model's class, its label the model's name, and the model's id is in `data`.
 * - A value is compared in its stored form, so a decimal stored as `10.0000` equals `10`, and a
 *   condition is compared by its rules, without their uids. A save that changes nothing writes no
 *   row (BR-7).
 * - A discount's coupon codes are counted, never recorded: the codes added and removed in a save
 *   are added to the discount's row as two numbers.
 * - What Commerce works out from them is not recorded: the catalog prices it regenerates in the
 *   queue, the users a customer condition matches, a discount's order of application and its
 *   count of uses.
 *
 * @since 1.1.0
 */
class PromotionsWatcher
{
    public const PRICING_RULE = 'craft\\commerce\\models\\CatalogPricingRule';
    public const DISCOUNT = 'craft\\commerce\\models\\Discount';

    /**
     * The key a discount's row carries its coupon codes under, as `{label, added, removed}`.
     */
    public const COUPONS = 'coupons';

    private const RECORD_PRICING_RULE = 'craft\\commerce\\records\\CatalogPricingRule';
    private const RECORD_DISCOUNT = 'craft\\commerce\\records\\Discount';
    private const RECORD_COUPON = 'craft\\commerce\\records\\Coupon';

    private const TABLE_DISCOUNTS = '{{%commerce_discounts}}';
    private const TABLE_STORES = '{{%commerce_stores}}';

    /**
     * Each record's model, and the columns that are not its settings: bookkeeping, a value nobody
     * sets in a save (a discount's place in the order and its count of uses), or one Commerce no
     * longer reads (a rule's metadata).
     */
    private const MODELS = [
        self::RECORD_PRICING_RULE => [self::PRICING_RULE, ['metadata']],
        self::RECORD_DISCOUNT => [self::DISCOUNT, ['sortOrder', 'totalDiscountUses']],
    ];

    private const BOOKKEEPING = ['id', 'uid', 'dateCreated', 'dateUpdated'];

    /**
     * Columns whose stored value is a condition's config, or a list of ids.
     */
    private const CONDITIONS = [
        'productCondition', 'variantCondition', 'purchasableCondition', 'customerCondition',
        'orderCondition', 'shippingAddressCondition', 'billingAddressCondition',
    ];
    private const ID_LISTS = ['purchasableIds', 'categoryIds'];

    /**
     * A new model's values recorded even when empty, because "off" is what it says.
     */
    private const ALWAYS_CREATED = ['enabled'];

    /**
     * Labels for the columns, in English as stored; any other column is labelled from its name.
     */
    private const LABELS = [
        'storeId' => 'Store',
        'dateFrom' => 'Start date',
        'dateTo' => 'End date',
        'apply' => 'Price adjustment',
        'applyAmount' => 'Amount',
        'applyPriceType' => 'Applied to',
        'isPromotionalPrice' => 'Sets the promotional price',
        'purchasableIds' => 'Purchasables',
        'categoryIds' => 'Categories',
        'allPurchasables' => 'All purchasables',
        'allCategories' => 'All categories',
        'stopProcessing' => 'Stops later discounts',
        'requireCouponCode' => 'Requires a coupon code',
        'orderConditionFormula' => 'Order condition formula',
        'perItemDiscount' => 'Per item discount',
        'percentDiscount' => 'Percent discount',
        'baseDiscount' => 'Base discount',
        'purchaseTotal' => 'Purchase total',
        'purchaseQty' => 'Minimum purchase quantity',
        'maxPurchaseQty' => 'Maximum purchase quantity',
        'percentageOffSubject' => 'Percentage off',
        'hasFreeShippingForMatchingItems' => 'Free shipping for matching items',
        'hasFreeShippingForOrder' => 'Free shipping for the order',
        'excludeOnPromotion' => 'Excludes items on promotion',
        'ignorePromotions' => 'Ignores promotional prices',
        'perUserLimit' => 'Per user limit',
        'perEmailLimit' => 'Per email limit',
        'totalDiscountUseLimit' => 'Total use limit',
        'appliedTo' => 'Applied to',
        'categoryRelationshipType' => 'Category relationship',
        'couponFormat' => 'Coupon format',
    ];

    /**
     * The columns that hold one of Commerce's set values, each value in words, as Commerce's own
     * settings screens put it — in English, translated when shown. A value not here is shown as
     * stored.
     */
    public const VALUE_LABELS = [
        'apply' => [
            'byPercent' => 'Reduce the price by a percentage of the original price',
            'byFlat' => 'Reduce the price by a fixed amount',
            'toPercent' => 'Set the price to a percentage of the original price',
            'toFlat' => 'Set the price to a flat amount',
        ],
        'applyPriceType' => [
            'price' => 'Original price',
            'promotionalPrice' => 'Original promotional price',
        ],
        'appliedTo' => [
            'matchingLineItems' => 'Discount the matching items only',
            'allLineItems' => 'Discount all line items',
        ],
        'percentageOffSubject' => [
            'discounted' => 'Off the discounted item price',
            'original' => 'Off the original item price',
        ],
        'categoryRelationshipType' => [
            'sourceElement' => 'The purchasable defines the relationship',
            'targetElement' => 'The purchasable is related by another element',
            'element' => 'Either way',
        ],
    ];

    /**
     * Each discount's row for the request, by discount id, so the coupon codes its save writes
     * after it join it. A discount saved with nothing changed has a row here too, not yet recorded.
     *
     * @var array<int, Event>
     */
    private array $discountRows = [];

    /**
     * Discounts whose row above was not recorded, because their save changed nothing.
     *
     * @var array<int, true>
     */
    private array $unrecorded = [];

    public function attach(): void
    {
        foreach ([self::RECORD_PRICING_RULE, self::RECORD_DISCOUNT] as $record) {
            YiiEvent::on($record, BaseActiveRecord::EVENT_AFTER_INSERT, [$this, 'afterSave']);
            YiiEvent::on($record, BaseActiveRecord::EVENT_AFTER_UPDATE, [$this, 'afterSave']);
            YiiEvent::on($record, BaseActiveRecord::EVENT_AFTER_DELETE, [$this, 'afterDelete']);
        }
        YiiEvent::on(self::RECORD_COUPON, BaseActiveRecord::EVENT_AFTER_INSERT, [$this, 'couponSaved']);
        YiiEvent::on(self::RECORD_COUPON, BaseActiveRecord::EVENT_AFTER_UPDATE, [$this, 'couponSaved']);
        YiiEvent::on(self::RECORD_COUPON, BaseActiveRecord::EVENT_AFTER_DELETE, [$this, 'couponDeleted']);
    }

    /**
     * A rule or discount written: created with its values, or saved with what changed.
     */
    public function afterSave(AfterSaveEvent $event): void
    {
        try {
            $record = $event->sender;
            if (!$record instanceof ActiveRecord || !$this->plugin()->getRecorder()->isRecording()) {
                return;
            }
            [$model, $ignored] = self::model($record) ?? [null, []];
            if ($model === null) {
                return;
            }
            $skip = array_merge(self::BOOKKEEPING, $ignored);

            $data = [];
            if ($event->name === BaseActiveRecord::EVENT_AFTER_INSERT) {
                foreach ($record->getAttributes() as $name => $value) {
                    $new = $this->stored($record, $name, $value);
                    $empty = $new === null || $new === false || $new === 0;
                    if (!in_array($name, $skip, true) && (!$empty || in_array($name, self::ALWAYS_CREATED, true))) {
                        $data[$name] = $this->entry($name, null, $new);
                    }
                }
                $action = ElementWatcher::EVENT_CREATED;
            } else {
                foreach ($event->changedAttributes as $name => $oldValue) {
                    if (in_array($name, $skip, true)) {
                        continue;
                    }
                    $old = $this->stored($record, $name, $oldValue);
                    $new = $this->stored($record, $name, $record->getAttribute($name));
                    if ($old !== $new) {
                        $data[$name] = $this->entry($name, $old, $new);
                    }
                }
                $action = ElementWatcher::EVENT_SAVED;
            }

            $row = $this->row($action, $model, $record, $data);
            $records = $data !== [] || $action === ElementWatcher::EVENT_CREATED || $this->plugin()->getSettings()->recordNoChangeSaves;
            if ($model === self::DISCOUNT) {
                $id = (int)$record->getPrimaryKey();
                $this->discountRows[$id] = $row;
                if ($records) {
                    unset($this->unrecorded[$id]);
                } else {
                    $this->unrecorded[$id] = true;
                }
            }
            if ($records) {
                $this->plugin()->getRecorder()->record($row);
            }
        } catch (Throwable $e) {
            $this->fail('Could not record a pricing rule or discount', $e);
        }
    }

    public function afterDelete(YiiEvent $event): void
    {
        try {
            $record = $event->sender;
            if (!$record instanceof ActiveRecord || !$this->plugin()->getRecorder()->isRecording()) {
                return;
            }
            $model = self::model($record)[0] ?? null;
            if ($model === null) {
                return;
            }
            unset($this->discountRows[(int)$record->getPrimaryKey()], $this->unrecorded[(int)$record->getPrimaryKey()]);
            $this->plugin()->getRecorder()->record($this->row(ElementWatcher::EVENT_DELETED, $model, $record, []));
        } catch (Throwable $e) {
            $this->fail('Could not record a deleted pricing rule or discount', $e);
        }
    }

    /**
     * A coupon code written. Commerce saves every code of a discount each time the discount is
     * saved, so only a new code, or a code changed, counts.
     */
    public function couponSaved(AfterSaveEvent $event): void
    {
        $record = $event->sender;
        if (!$record instanceof ActiveRecord) {
            return;
        }
        if ($event->name === BaseActiveRecord::EVENT_AFTER_INSERT) {
            $this->countCoupon($record, 1, 0);
        } elseif (array_key_exists('code', $event->changedAttributes) && (string)$event->changedAttributes['code'] !== (string)$record->getAttribute('code')) {
            $this->countCoupon($record, 1, 1);
        }
    }

    public function couponDeleted(YiiEvent $event): void
    {
        $record = $event->sender;
        if ($record instanceof ActiveRecord) {
            $this->countCoupon($record, 0, 1);
        }
    }

    /**
     * Adds coupon codes to their discount's row: the one its save made in this request while it is
     * still buffered, or else a new `saved` row, named from the stored discount (one read).
     */
    private function countCoupon(ActiveRecord $coupon, int $added, int $removed): void
    {
        try {
            $recorder = $this->plugin()->getRecorder();
            $discountId = (int)$coupon->getAttribute('discountId');
            if (!$discountId || !$recorder->isRecording()) {
                return;
            }

            $row = $this->discountRows[$discountId] ?? null;
            $unrecorded = isset($this->unrecorded[$discountId]);
            if ($row === null || (!$unrecorded && !$recorder->holds($row))) {
                // Never seen, or already written (BR-1): the codes get a row of their own.
                $name = (new Query())->select(['name'])->from(self::TABLE_DISCOUNTS)->where(['id' => $discountId])->scalar();
                $row = $this->row(ElementWatcher::EVENT_SAVED, self::DISCOUNT, null, [], $discountId, is_string($name) ? $name : null);
                $this->discountRows[$discountId] = $row;
                $unrecorded = true;
            }

            $data = $row->data ?? [];
            $coupons = $data[self::COUPONS] ?? ['label' => 'Coupon codes', 'added' => 0, 'removed' => 0];
            $coupons['added'] += $added;
            $coupons['removed'] += $removed;
            $data[self::COUPONS] = $coupons;
            $row->data = $data;
            if ($unrecorded) {
                unset($this->unrecorded[$discountId]);
                $recorder->record($row);
            }
        } catch (Throwable $e) {
            $this->fail('Could not record a discount’s coupon codes', $e);
        }
    }

    /**
     * The record's model and the columns that are not its settings, or null for any other record.
     * Typed loosely on purpose: without Commerce, PHPStan knows none of these classes exists.
     *
     * @return array{0: string, 1: list<string>}|null
     */
    private static function model(ActiveRecord $record): ?array
    {
        foreach (self::MODELS as $class => $model) {
            if (strcasecmp($record::class, $class) === 0) {
                return $model;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function row(string $action, string $model, ?ActiveRecord $record, array $data, ?int $id = null, ?string $name = null): Event
    {
        $id ??= (int)$record?->getPrimaryKey();
        $name ??= $record === null ? null : (string)$record->getAttribute('name');

        return new Event([
            'family' => Event::FAMILY_ELEMENT,
            'event' => $action,
            'elementType' => $model,
            'elementLabel' => $name !== null && $name !== '' ? $name : sprintf('%s #%d', self::typeName($model), $id),
            'data' => ['id' => $id] + $data,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(string $name, mixed $old, mixed $new): array
    {
        $label = self::LABELS[$name] ?? ucfirst(mb_strtolower(Inflector::camel2words($name, false)));
        if ($this->plugin()->getDiffer()->isRedacted($name)) {
            return ['label' => $label, 'redacted' => true];
        }
        if ($name === 'storeId') {
            [$old, $new] = [$this->storeName($old), $this->storeName($new)];
        }

        return ['label' => $label, 'old' => $old, 'new' => $new];
    }

    /**
     * A column's value as it is stored, read back into PHP so two forms of one value compare equal:
     * the before value is the stored one, the after value what Commerce gave the record (a date, an
     * array, a float). Empty is `null`. A boolean column is told by its type, since Craft has already
     * turned the record's value into the number MySQL stores.
     */
    private function stored(ActiveRecord $record, string $name, mixed $value): mixed
    {
        $column = $record::getTableSchema()->getColumn($name);
        $value = Db::prepareValueForDb($value, $column?->dbType);
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        if (in_array($name, self::CONDITIONS, true)) {
            return $this->conditionRules($value);
        }
        if (in_array($name, self::ID_LISTS, true)) {
            $ids = is_string($value) ? Json::decodeIfJson($value) : $value;
            $ids = array_map('intval', is_array($ids) ? array_values($ids) : []);
            sort($ids);
            return $ids === [] ? null : $ids;
        }
        if (is_bool($value) || $column?->type === 'boolean' || ($column !== null && stripos($column->dbType, 'tinyint(1)') === 0)) {
            return (bool)$value;
        }
        if (is_numeric($value) && ($column === null || in_array($column->type, ['integer', 'smallint', 'tinyint', 'bigint', 'decimal', 'float', 'double', 'money'], true))) {
            $number = (float)$value;
            return floor($number) === $number && abs($number) < 1e15 ? (int)$number : $number;
        }

        return is_scalar($value) ? (string)$value : $value;
    }

    /**
     * A condition by its rules, each without its uid: a condition with none is no condition.
     *
     * @return list<array<string, mixed>>|null
     */
    private function conditionRules(mixed $config): ?array
    {
        $config = is_string($config) ? Json::decodeIfJson($config) : $config;
        $rules = is_array($config) && is_array($config['conditionRules'] ?? null) ? $config['conditionRules'] : [];

        $out = [];
        foreach ($rules as $rule) {
            if (is_array($rule)) {
                unset($rule['uid']);
                ksort($rule);
                $out[] = $rule;
            }
        }

        return $out === [] ? null : $out;
    }

    /**
     * A store by name, as a status is: a store may be renamed or deleted. One read, only when the
     * store is among the values recorded.
     */
    private function storeName(mixed $id): mixed
    {
        if (!is_int($id)) {
            return $id;
        }
        $name = (new Query())->select(['name'])->from(self::TABLE_STORES)->where(['id' => $id])->scalar();

        return is_string($name) && $name !== '' ? $name : sprintf('#%d', $id);
    }

    /**
     * A model's name, in English as stored, for a row whose model had none.
     */
    public static function typeName(string $model): string
    {
        return $model === self::DISCOUNT ? 'Discount' : 'Catalog pricing rule';
    }

    private function plugin(): Auditor
    {
        /** @var Auditor */
        return Auditor::getInstance();
    }

    private function fail(string $message, Throwable $e): void
    {
        try {
            Craft::error(sprintf('%s: %s', $message, $e->getMessage()), Auditor::HANDLE);
        } catch (Throwable) {
            // Nowhere left to report it, and BR-2 forbids throwing.
        }
    }
}
