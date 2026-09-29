<?php
declare(strict_types=1);
/**
 * Copyright © David Nimorwicz. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Nfourteen\InventoryAggregateProduct\Test\Unit\Plugin\CatalogInventory\Helper\Stock;

use Magento\Catalog\Model\Product;
use Magento\CatalogInventory\Helper\Stock;
use Magento\InventoryCatalog\Model\GetStockIdForByStoreId;
use Magento\InventorySalesApi\Model\GetStockItemDataInterface;
use Nfourteen\AggregateProduct\Model\Product\Type\Aggregate as AggregateProductType;
use Nfourteen\InventoryAggregateProduct\Plugin\CatalogInventory\Helper\Stock\AdaptAssignStatusToProductForAggregate;
use Nfourteen\InventoryAggregateProduct\Service\IsProductSalableCondition\IsAggregateProductChildrenSalable;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AdaptAssignStatusToProductForAggregateTest extends TestCase
{
    private $plugin;
    private (GetStockItemDataInterface&MockObject)|null $getStockItemData = null;
    private (GetStockIdForByStoreId&MockObject)|null $getStockIdForByStoreId = null;
    private (IsAggregateProductChildrenSalable&MockObject)|null $isAggregateProductChildrenSalable = null;

    protected function setUp(): void
    {
        $this->getStockItemData = $this->createMock(GetStockItemDataInterface::class);
        $this->getStockIdForByStoreId = $this->createMock(GetStockIdForByStoreId::class);
        $this->isAggregateProductChildrenSalable = $this->createMock(IsAggregateProductChildrenSalable::class);

        $this->plugin = new AdaptAssignStatusToProductForAggregate(
            $this->getStockItemData,
            $this->getStockIdForByStoreId,
            $this->isAggregateProductChildrenSalable
        );
    }

    public function testNonAggregateIsLeftUnchanged(): void
    {
        $product = $this->createMock(Product::class);
        $product->method('getTypeId')->willReturn('simple');

        $this->getStockIdForByStoreId->expects($this->never())->method('execute');

        $this->assertSame(
            [$product, 1],
            $this->plugin->beforeAssignStatusToProduct($this->createMock(Stock::class), $product, 1)
        );
    }

    public function testIndexUnsalableAggregateIsLeftUnchanged(): void
    {
        $product = $this->aggregateProduct();

        $this->getStockItemData->method('execute')
            ->with('aggregate_sku', 10)
            ->willReturn([GetStockItemDataInterface::IS_SALABLE => 0]);

        $this->isAggregateProductChildrenSalable->expects($this->never())->method('execute');

        $this->assertSame(
            [$product, null],
            $this->plugin->beforeAssignStatusToProduct($this->createMock(Stock::class), $product, null)
        );
    }

    /**
     * The stock index can be stale, so an index-salable aggregate must be rechecked through the
     * same qty-aware child rule the indexer and GetIsQtySalable plugin use.
     */
    public function testUnsalableChildrenForceAggregateOutOfStockEvenWhenIndexSalable(): void
    {
        $product = $this->aggregateProduct();

        $this->getStockItemData->method('execute')
            ->with('aggregate_sku', 10)
            ->willReturn([GetStockItemDataInterface::IS_SALABLE => 1]);

        $this->isAggregateProductChildrenSalable->method('execute')
            ->with('aggregate_sku', 10)
            ->willReturn(false);

        $this->assertSame(
            [$product, 0],
            $this->plugin->beforeAssignStatusToProduct($this->createMock(Stock::class), $product, 1)
        );
    }

    public function testSalableChildrenKeepAggregateEnabled(): void
    {
        $product = $this->aggregateProduct();

        $this->getStockItemData->method('execute')
            ->with('aggregate_sku', 10)
            ->willReturn([GetStockItemDataInterface::IS_SALABLE => 1]);

        $this->isAggregateProductChildrenSalable->method('execute')
            ->with('aggregate_sku', 10)
            ->willReturn(true);

        $this->assertSame(
            [$product, 1],
            $this->plugin->beforeAssignStatusToProduct($this->createMock(Stock::class), $product, 0)
        );
    }

    private function aggregateProduct(): Product&MockObject
    {
        $product = $this->createMock(Product::class);
        $product->method('getTypeId')->willReturn(AggregateProductType::TYPE_CODE);
        $product->method('getStoreId')->willReturn(1);
        $product->method('getSku')->willReturn('aggregate_sku');

        $this->getStockIdForByStoreId->method('execute')->with(1)->willReturn(10);

        return $product;
    }
}
