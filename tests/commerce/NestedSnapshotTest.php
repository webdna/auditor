<?php

namespace webdna\auditor\tests\commerce;

use Closure;
use Codeception\Test\Unit;
use Craft;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\fieldlayoutelements\ProductTitleField;
use craft\commerce\models\ProductType;
use craft\commerce\models\ProductTypeSite;
use craft\commerce\Plugin as Commerce;
use craft\db\Connection;
use craft\helpers\ElementHelper;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use webdna\auditor\Auditor;
use webdna\auditor\db\Table;
use webdna\auditor\services\Differ;
use webdna\auditor\services\Recorder;
use webdna\auditor\tests\integration\SecondConnection;
use webdna\auditor\tests\Support\AuditorListeners;
use Yii;
use yii\log\Logger;

/**
 * BR-35 for an owner's nested elements (#7): the before-state of the variants a product saves in
 * its own save is read in one query per table, whatever their number — so a product saved the way
 * the control panel saves it, every variant posted, costs as many extra queries with sixteen
 * variants as with one, and every changed variant still has its row.
 */
class NestedSnapshotTest extends Unit
{
    use SecondConnection;

    /**
     * The product's own row; its variants' rows, in one read; both their prices, in one read; and
     * the request's one insert. Before #7 each variant cost three reads of its own.
     */
    private const SAVE_BUDGET = 4;

    private Auditor $plugin;

    private Connection $db;

    private ProductType $type;

    private int $lastId;

    protected function _before(): void
    {
        $this->plugin = Auditor::getInstance();
        Craft::$app->getProjectConfig()->writeYamlAutomatically = false;

        $this->db = $this->secondConnection();
        $this->lastId = (int)$this->db->createCommand('SELECT MAX([[id]]) FROM ' . Table::EVENTS)->queryScalar();
        $this->plugin->set('recorder', new Recorder(['db' => $this->db]));
        $this->plugin->set('differ', Differ::class);
        $this->type = $this->productType();
    }

    protected function _after(): void
    {
        $this->db->createCommand()->delete(Table::EVENTS, ['>', 'id', $this->lastId])->execute();
        $this->db->close();
        $this->plugin->set('recorder', Recorder::class);
        $this->plugin->set('differ', Differ::class);
    }

    public function testAProductSavesSixteenChangedVariantsForTheQueriesOfOne(): void
    {
        $extra = [];
        foreach ([1, 16] as $count) {
            $product = $this->product($count);

            // Once each way unmeasured, so neither measured save pays Craft's first-save caches.
            AuditorListeners::detached(fn() => $this->resave($product, 11));
            $this->resave($product, 12);
            $this->plugin->getRecorder()->flush();

            $off = count($this->queriesDuring(fn() => AuditorListeners::detached(fn() => $this->resave($product, 13))));
            $on = count($this->queriesDuring(function() use ($product) {
                $this->resave($product, 14);
                $this->plugin->getRecorder()->flush();
            }));
            $extra[$count] = $on - $off;

            $rows = $this->db->createCommand('SELECT [[data]] FROM ' . Table::EVENTS . ' WHERE [[id]] > :id AND [[elementType]] = :type ORDER BY [[id]] DESC LIMIT ' . $count, [
                ':id' => $this->lastId,
                ':type' => Variant::class,
            ])->queryColumn();
            $this->assertCount($count, $rows, "$count variants: one row each");
            foreach ($rows as $data) {
                $data = json_decode((string)$data, true);
                $this->assertEquals(13, $data['basePrice']['old'] ?? null, "$count variants: the stored price before the save");
                $this->assertEquals(14, $data['basePrice']['new'] ?? null, "$count variants: the price saved");
            }
        }

        $this->assertSame($extra[1], $extra[16], sprintf('Extra queries: %d with one variant, %d with sixteen', $extra[1], $extra[16]));
        $this->assertLessThanOrEqual(self::SAVE_BUDGET, $extra[16], sprintf('%d extra queries', $extra[16]));
    }

    /**
     * A product saved again with every variant posted and each one's price set, as the control
     * panel's product form saves it.
     */
    private function resave(Product $product, int $price): void
    {
        $fresh = Product::find()->id($product->id)->status(null)->one();
        $this->assertInstanceOf(Product::class, $fresh);
        $variants = $fresh->getVariants(true);
        foreach ($variants as $variant) {
            $variant->basePrice = $price;
        }
        $fresh->setVariants($variants);
        $fresh->setDirtyAttributes(['variants']);
        $this->assertTrue(Craft::$app->getElements()->saveElement($fresh), implode(' ', $fresh->getFirstErrors()));
    }

    private function product(int $variants): Product
    {
        $suffix = bin2hex(random_bytes(3));
        $product = new Product([
            'typeId' => $this->type->id,
            'title' => 'Harbour boot',
            'slug' => ElementHelper::generateSlug("Harbour boot $variants $suffix"),
        ]);
        $product->setVariants(array_map(
            fn(int $n) => new Variant(['sku' => "BOOT-$suffix-$n", 'basePrice' => 10, 'isDefault' => $n === 1]),
            range(1, $variants),
        ));
        $product->setDirtyAttributes(['variants']);
        $this->assertTrue(Craft::$app->getElements()->saveElement($product), implode(' ', $product->getFirstErrors()));
        $this->assertCount($variants, Variant::find()->ownerId($product->id)->status(null)->ids());

        return $product;
    }

    private function productType(): ProductType
    {
        $handle = 'boots' . bin2hex(random_bytes(3));
        $type = new ProductType([
            'name' => 'Boots ' . $handle,
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
            'uriFormat' => 'boots/{slug}',
            'template' => '_product',
        ])]);
        $this->assertTrue(Commerce::getInstance()->getProductTypes()->saveProductType($type), implode(' ', $type->getFirstErrors()));

        return $type;
    }

    /**
     * The database commands run during a callback, counted from Yii's profiling log, as
     * `SpeedTest` counts them.
     *
     * @return string[]
     */
    private function queriesDuring(Closure $run): array
    {
        $harness = Yii::getLogger();
        // Never flushed mid-count: a product's save logs more than the thousand messages a logger
        // keeps by default before it empties itself.
        $logger = new Logger(['flushInterval' => PHP_INT_MAX]);
        Yii::setLogger($logger);
        try {
            $run();
        } finally {
            Yii::setLogger($harness);
        }

        $queries = array_filter($logger->messages, fn(array $message) => $message[1] === Logger::LEVEL_PROFILE_BEGIN
            && str_starts_with((string)$message[2], 'yii\db\Command::'));

        return array_values(array_map(fn(array $message) => (string)$message[0], $queries));
    }
}
