<?php
declare(strict_types=1);
/**
 * Copyright © Nfourteen. All Rights Reserved.
 * See COPYING.txt for license details.
 **/

namespace Nfourteen\InventoryAggregateProduct\Test\Integration\Model;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\CatalogInventory\Api\Data\StockItemInterface;
use Magento\CatalogInventory\Api\Data\StockStatusInterface;
use Magento\CatalogInventory\Api\StockItemCriteriaInterfaceFactory;
use Magento\CatalogInventory\Api\StockItemRepositoryInterface;
use Magento\CatalogInventory\Api\StockStatusCriteriaInterfaceFactory;
use Magento\CatalogInventory\Api\StockStatusRepositoryInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorage;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Helper\Bootstrap;
use Nfourteen\AggregateProduct\Test\Fixture\AggregateProduct as AggregateProductFixture;
use Nfourteen\InventoryAggregateProduct\Service\StockStatusProcessor;
use PHPUnit\Framework\TestCase;

class StockStatusProcessorTest extends TestCase
{
    private ?StockStatusProcessor $stockStatusProcessor = null;
    private ?StockStatusRepositoryInterface $stockStatusRepository = null;
    private ?StockStatusCriteriaInterfaceFactory $stockStatusCriteriaFactory = null;
    private ?StockItemRepositoryInterface $stockItemRepository = null;
    private ?StockItemCriteriaInterfaceFactory $stockItemCriteriaFactory = null;
    private ?ResourceConnection $resourceConnection = null;
    private ?DataFixtureStorage $fixtures = null;

    protected function setUp(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $this->stockStatusProcessor = $objectManager->get(StockStatusProcessor::class);
        $this->stockStatusRepository = $objectManager->get(StockStatusRepositoryInterface::class);
        $this->stockStatusCriteriaFactory = $objectManager->get(StockStatusCriteriaInterfaceFactory::class);
        $this->stockItemRepository = $objectManager->get(StockItemRepositoryInterface::class);
        $this->stockItemCriteriaFactory = $objectManager->get(StockItemCriteriaInterfaceFactory::class);
        $this->resourceConnection = $objectManager->get(ResourceConnection::class);
        $this->fixtures = DataFixtureStorageManager::getStorage();
    }

    #[DataFixture(ProductFixture::class, ['sku' => 'child_processor_test_1'], 'child1')]
    #[DataFixture(ProductFixture::class, ['sku' => 'child_processor_test_2'], 'child2')]
    #[DataFixture(
        AggregateProductFixture::class,
        [
            'sku' => 'aggregate_processor_test',
            '_children' => [
                ['product_id' => '$child1.id$', 'qty' => 1],
                ['product_id' => '$child2.id$', 'qty' => 1],
            ],
        ],
        'aggregate'
    )]
    public function testProcessorUpdatesParentWhenChildStockChanges(): void
    {
        $childSku1 = 'child_processor_test_1';
        $parentId = (int)$this->fixtures->get('aggregate')->getId();

        $parentStockStatus = $this->getStockStatus($parentId);
        $this->assertEquals(
            StockStatusInterface::STATUS_IN_STOCK,
            $parentStockStatus->getStockStatus(),
            'Parent should initially be in stock'
        );

        $this->setProductOutOfStock($childSku1);
        $this->forceParentInStock($parentId);

        $this->stockStatusProcessor->execute([$childSku1]);

        $this->assertFalse(
            (bool)$this->getStockItem($parentId)->getIsInStock(),
            'Processor must write parent is_in_stock from child availability'
        );
        $parentStockStatus = $this->getStockStatus($parentId);
        $this->assertEquals(
            StockStatusInterface::STATUS_OUT_OF_STOCK,
            $parentStockStatus->getStockStatus(),
            'Parent should be out of stock after child becomes out of stock'
        );
    }

    #[DataFixture(ProductFixture::class, ['sku' => 'child_processor_batch_1'], 'child1')]
    #[DataFixture(
        AggregateProductFixture::class,
        [
            'sku' => 'aggregate_processor_batch',
            '_children' => [
                ['product_id' => '$child1.id$', 'qty' => 1],
            ],
        ],
        'aggregate'
    )]
    public function testProcessorSkipsNonExistentSkuWithoutSkippingBatch(): void
    {
        $childSku = 'child_processor_batch_1';
        $parentId = (int)$this->fixtures->get('aggregate')->getId();

        $this->setProductOutOfStock($childSku);
        $this->forceParentInStock($parentId);

        $this->stockStatusProcessor->execute(['non_existent_sku_12345', $childSku]);

        $this->assertFalse(
            (bool)$this->getStockItem($parentId)->getIsInStock(),
            'An unknown SKU in the batch must not skip the parent recompute for valid SKUs'
        );
    }

    /**
     * Saving the child stock item already flips the parent as a side effect (legacy stock
     * indexer relation walk plus the SourceItemsSave composite-status pool), which would let
     * a no-op processor pass. Forcing the parent back in stock with raw SQL — no observers,
     * no reindex — makes the processor's own write the only thing that can flip it again.
     */
    private function forceParentInStock(int $productId): void
    {
        $connection = $this->resourceConnection->getConnection();
        $connection->update(
            $this->resourceConnection->getTableName('cataloginventory_stock_item'),
            ['is_in_stock' => 1, 'stock_status_changed_auto' => 1],
            ['product_id = ?' => $productId]
        );
        $connection->update(
            $this->resourceConnection->getTableName('cataloginventory_stock_status'),
            ['stock_status' => StockStatusInterface::STATUS_IN_STOCK],
            ['product_id = ?' => $productId]
        );

        $this->assertTrue(
            (bool)$this->getStockItem($productId)->getIsInStock(),
            'Baseline: parent must read in-stock before the processor runs'
        );
        $this->assertEquals(
            StockStatusInterface::STATUS_IN_STOCK,
            $this->getStockStatus($productId)->getStockStatus(),
            'Baseline: parent stock index must read in-stock before the processor runs'
        );
    }

    private function getStockItem(int $productId): ?StockItemInterface
    {
        $criteria = $this->stockItemCriteriaFactory->create();
        $criteria->setProductsFilter($productId);
        $items = $this->stockItemRepository->getList($criteria)->getItems();

        return !empty($items) ? reset($items) : null;
    }

    private function getStockStatus(int $productId): ?StockStatusInterface
    {
        $criteria = $this->stockStatusCriteriaFactory->create();
        $criteria->setProductsFilter($productId);
        $result = $this->stockStatusRepository->getList($criteria);
        $items = $result->getItems();

        return !empty($items) ? reset($items) : null;
    }

    private function setProductOutOfStock(string $sku): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $productRepository = $objectManager->get(ProductRepositoryInterface::class);
        $product = $productRepository->get($sku);

        $criteria = $this->stockItemCriteriaFactory->create();
        $criteria->setProductsFilter($product->getId());
        $stockItemCollection = $this->stockItemRepository->getList($criteria);
        $items = $stockItemCollection->getItems();

        if (!empty($items)) {
            $stockItem = reset($items);
            $stockItem->setIsInStock(false);
            $stockItem->setQty(0);
            $this->stockItemRepository->save($stockItem);
        }
    }
}
