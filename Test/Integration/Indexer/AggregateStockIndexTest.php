<?php
declare(strict_types=1);
/**
 * Copyright © David Nimorwicz. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Nfourteen\InventoryAggregateProduct\Test\Integration\Indexer;

use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\Framework\Indexer\IndexerInterface;
use Magento\InventoryApi\Api\Data\SourceItemInterface;
use Magento\InventoryApi\Api\Data\SourceItemInterfaceFactory;
use Magento\InventoryApi\Api\SourceItemsSaveInterface;
use Magento\InventoryApi\Test\Fixture\Source as SourceFixture;
use Magento\InventoryApi\Test\Fixture\SourceItem as SourceItemFixture;
use Magento\InventoryApi\Test\Fixture\Stock as StockFixture;
use Magento\InventoryApi\Test\Fixture\StockSourceLinks as StockSourceLinksFixture;
use Magento\InventoryIndexer\Indexer\InventoryIndexer;
use Magento\InventorySalesApi\Model\GetStockItemDataInterface;
use Magento\InventorySalesApi\Test\Fixture\StockSalesChannels as StockSalesChannelsFixture;
use Magento\Store\Test\Fixture\Group as GroupFixture;
use Magento\Store\Test\Fixture\Store as StoreFixture;
use Magento\Store\Test\Fixture\Website as WebsiteFixture;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorage;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use Nfourteen\AggregateProduct\Test\Fixture\AggregateProduct as AggregateProductFixture;
use PHPUnit\Framework\TestCase;

/**
 * The stock index of a non-default stock is what category listings, search and the salable checks
 * on that website read. An aggregate has no source items of its own, so its row there must be
 * derived from its children.
 */
class AggregateStockIndexTest extends TestCase
{
    private ?GetStockItemDataInterface $getStockItemData = null;
    private ?DataFixtureStorage $fixtures = null;

    protected function setUp(): void
    {
        $this->getStockItemData = Bootstrap::getObjectManager()->get(GetStockItemDataInterface::class);
        $this->fixtures = DataFixtureStorageManager::getStorage();
    }

    #[
        DbIsolation(false),
        DataFixture(WebsiteFixture::class, ['code' => 'agg_index_website'], as: 'website'),
        DataFixture(GroupFixture::class, ['website_id' => '$website.id$'], as: 'group'),
        DataFixture(StoreFixture::class, ['store_group_id' => '$group.id$', 'code' => 'agg_index_store']),
        DataFixture(SourceFixture::class, ['source_code' => 'agg-index-source']),
        DataFixture(StockFixture::class, as: 'stock'),
        DataFixture(StockSourceLinksFixture::class, [['stock_id' => '$stock.stock_id$', 'source_code' => 'agg-index-source']]),
        DataFixture(StockSalesChannelsFixture::class, ['stock_id' => '$stock.stock_id$', 'sales_channels' => ['agg_index_website']]),
        DataFixture(ProductFixture::class, ['sku' => 'agg-index-child-a'], as: 'childA'),
        DataFixture(ProductFixture::class, ['sku' => 'agg-index-child-b'], as: 'childB'),
        DataFixture(SourceItemFixture::class, ['sku' => 'agg-index-child-a', 'source_code' => 'agg-index-source', 'quantity' => 10]),
        DataFixture(SourceItemFixture::class, ['sku' => 'agg-index-child-b', 'source_code' => 'agg-index-source', 'quantity' => 10]),
        DataFixture(AggregateProductFixture::class, [
            'sku' => 'agg-index-pack',
            '_children' => [
                ['product_id' => '$childA.id$', 'qty' => 1],
                ['product_id' => '$childB.id$', 'qty' => 2],
            ],
        ]),
    ]
    public function testAggregateIsSalableInTheStockIndexWhenEveryChildIsStockedThere(): void
    {
        $this->reindexInventory();

        $this->assertTrue($this->isSalableInIndex('agg-index-pack'));
    }

    #[
        DbIsolation(false),
        DataFixture(WebsiteFixture::class, ['code' => 'agg_index_website'], as: 'website'),
        DataFixture(GroupFixture::class, ['website_id' => '$website.id$'], as: 'group'),
        DataFixture(StoreFixture::class, ['store_group_id' => '$group.id$', 'code' => 'agg_index_store']),
        DataFixture(SourceFixture::class, ['source_code' => 'agg-index-source']),
        DataFixture(StockFixture::class, as: 'stock'),
        DataFixture(StockSourceLinksFixture::class, [['stock_id' => '$stock.stock_id$', 'source_code' => 'agg-index-source']]),
        DataFixture(StockSalesChannelsFixture::class, ['stock_id' => '$stock.stock_id$', 'sales_channels' => ['agg_index_website']]),
        DataFixture(ProductFixture::class, ['sku' => 'agg-index-child-a'], as: 'childA'),
        DataFixture(ProductFixture::class, ['sku' => 'agg-index-child-b'], as: 'childB'),
        DataFixture(SourceItemFixture::class, ['sku' => 'agg-index-child-a', 'source_code' => 'agg-index-source', 'quantity' => 10]),
        DataFixture(AggregateProductFixture::class, [
            'sku' => 'agg-index-pack',
            '_children' => [
                ['product_id' => '$childA.id$', 'qty' => 1],
                ['product_id' => '$childB.id$', 'qty' => 2],
            ],
        ]),
    ]
    public function testAggregateIsNotSalableInTheStockIndexWhenAChildIsNotStockedThere(): void
    {
        $this->reindexInventory();

        $this->assertFalse($this->isSalableInIndex('agg-index-pack'));
    }

    #[
        DbIsolation(false),
        DataFixture(WebsiteFixture::class, ['code' => 'agg_index_website'], as: 'website'),
        DataFixture(GroupFixture::class, ['website_id' => '$website.id$'], as: 'group'),
        DataFixture(StoreFixture::class, ['store_group_id' => '$group.id$', 'code' => 'agg_index_store']),
        DataFixture(SourceFixture::class, ['source_code' => 'agg-index-source']),
        DataFixture(StockFixture::class, as: 'stock'),
        DataFixture(StockSourceLinksFixture::class, [['stock_id' => '$stock.stock_id$', 'source_code' => 'agg-index-source']]),
        DataFixture(StockSalesChannelsFixture::class, ['stock_id' => '$stock.stock_id$', 'sales_channels' => ['agg_index_website']]),
        DataFixture(ProductFixture::class, ['sku' => 'agg-index-child-a'], as: 'childA'),
        DataFixture(ProductFixture::class, ['sku' => 'agg-index-child-b'], as: 'childB'),
        DataFixture(SourceItemFixture::class, ['sku' => 'agg-index-child-a', 'source_code' => 'agg-index-source', 'quantity' => 10]),
        DataFixture(SourceItemFixture::class, ['sku' => 'agg-index-child-b', 'source_code' => 'agg-index-source', 'quantity' => 1]),
        DataFixture(AggregateProductFixture::class, [
            'sku' => 'agg-index-pack',
            '_children' => [
                ['product_id' => '$childA.id$', 'qty' => 1],
                ['product_id' => '$childB.id$', 'qty' => 2],
            ],
        ]),
    ]
    public function testAggregateIsNotSalableInTheStockIndexWhenAChildHoldsLessThanTheLinkQty(): void
    {
        $this->reindexInventory();

        $this->assertFalse($this->isSalableInIndex('agg-index-pack'));
    }

    #[
        DbIsolation(false),
        DataFixture(WebsiteFixture::class, ['code' => 'agg_index_website'], as: 'website'),
        DataFixture(GroupFixture::class, ['website_id' => '$website.id$'], as: 'group'),
        DataFixture(StoreFixture::class, ['store_group_id' => '$group.id$', 'code' => 'agg_index_store']),
        DataFixture(SourceFixture::class, ['source_code' => 'agg-index-source']),
        DataFixture(StockFixture::class, as: 'stock'),
        DataFixture(StockSourceLinksFixture::class, [['stock_id' => '$stock.stock_id$', 'source_code' => 'agg-index-source']]),
        DataFixture(StockSalesChannelsFixture::class, ['stock_id' => '$stock.stock_id$', 'sales_channels' => ['agg_index_website']]),
        DataFixture(ProductFixture::class, ['sku' => 'agg-index-child-a'], as: 'childA'),
        DataFixture(ProductFixture::class, ['sku' => 'agg-index-child-b'], as: 'childB'),
        DataFixture(SourceItemFixture::class, ['sku' => 'agg-index-child-a', 'source_code' => 'agg-index-source', 'quantity' => 10]),
        DataFixture(SourceItemFixture::class, ['sku' => 'agg-index-child-b', 'source_code' => 'agg-index-source', 'quantity' => 10]),
        DataFixture(AggregateProductFixture::class, [
            'sku' => 'agg-index-pack',
            '_children' => [
                ['product_id' => '$childA.id$', 'qty' => 1],
                ['product_id' => '$childB.id$', 'qty' => 2],
            ],
        ]),
    ]
    public function testAggregateIndexFollowsAChildRunningOutOnThatStock(): void
    {
        $this->reindexInventory();
        $this->assertTrue($this->isSalableInIndex('agg-index-pack'), 'pack starts salable');

        $this->saveSourceItem('agg-index-child-b', 0, SourceItemInterface::STATUS_OUT_OF_STOCK);

        $this->assertFalse($this->isSalableInIndex('agg-index-pack'));
    }

    #[
        DbIsolation(false),
        DataFixture(WebsiteFixture::class, ['code' => 'agg_index_website'], as: 'website'),
        DataFixture(GroupFixture::class, ['website_id' => '$website.id$'], as: 'group'),
        DataFixture(StoreFixture::class, ['store_group_id' => '$group.id$', 'code' => 'agg_index_store']),
        DataFixture(SourceFixture::class, ['source_code' => 'agg-index-source']),
        DataFixture(StockFixture::class, as: 'stock'),
        DataFixture(StockSourceLinksFixture::class, [['stock_id' => '$stock.stock_id$', 'source_code' => 'agg-index-source']]),
        DataFixture(StockSalesChannelsFixture::class, ['stock_id' => '$stock.stock_id$', 'sales_channels' => ['agg_index_website']]),
        DataFixture(ProductFixture::class, ['sku' => 'agg-index-child-a'], as: 'childA'),
        DataFixture(ProductFixture::class, ['sku' => 'agg-index-child-b'], as: 'childB'),
        DataFixture(SourceItemFixture::class, ['sku' => 'agg-index-child-a', 'source_code' => 'agg-index-source', 'quantity' => 10]),
        DataFixture(SourceItemFixture::class, ['sku' => 'agg-index-child-b', 'source_code' => 'agg-index-source', 'quantity' => 10]),
        DataFixture(AggregateProductFixture::class, [
            'sku' => 'agg-index-pack',
            '_children' => [
                ['product_id' => '$childA.id$', 'qty' => 1],
                ['product_id' => '$childB.id$', 'qty' => 2],
            ],
        ]),
    ]
    public function testANewAggregateIsIndexedWithoutAFullReindex(): void
    {
        $this->assertTrue($this->isSalableInIndex('agg-index-pack'));
    }

    private function isSalableInIndex(string $sku): bool
    {
        $stockItemData = $this->getStockItemData->execute($sku, (int)$this->fixtures->get('stock')->getStockId());
        $this->assertNotNull($stockItemData, "{$sku} has a row in the stock index");

        return (bool)$stockItemData[GetStockItemDataInterface::IS_SALABLE];
    }

    private function saveSourceItem(string $sku, float $quantity, int $status): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $sourceItem = $objectManager->get(SourceItemInterfaceFactory::class)->create();
        $sourceItem->setSku($sku);
        $sourceItem->setSourceCode('agg-index-source');
        $sourceItem->setQuantity($quantity);
        $sourceItem->setStatus($status);

        $objectManager->get(SourceItemsSaveInterface::class)->execute([$sourceItem]);
    }

    private function reindexInventory(): void
    {
        $indexer = Bootstrap::getObjectManager()->create(IndexerInterface::class);
        $indexer->load(InventoryIndexer::INDEXER_ID);
        $indexer->reindexAll();
    }
}
