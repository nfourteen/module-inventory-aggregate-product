<?php
declare(strict_types=1);
/**
 * Copyright © Nfourteen. All Rights Reserved.
 * See COPYING.txt for license details.
 **/

namespace Nfourteen\InventoryAggregateProduct\Plugin\CatalogInventory\Helper\Stock;

use Magento\Catalog\Model\Product;
use Magento\CatalogInventory\Helper\Stock;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\InventoryCatalog\Model\GetStockIdForByStoreId;
use Magento\InventorySalesApi\Model\GetStockItemDataInterface;
use Nfourteen\AggregateProduct\Model\Product\Type\Aggregate as AggregateProductType;
use Nfourteen\InventoryAggregateProduct\Service\IsProductSalableCondition\IsAggregateProductChildrenSalable;

class AdaptAssignStatusToProductForAggregate
{
    public function __construct(
        private readonly GetStockItemDataInterface $getStockItemData,
        private readonly GetStockIdForByStoreId $getStockIdForByStoreId,
        private readonly IsAggregateProductChildrenSalable $isAggregateProductChildrenSalable
    ) {
    }

    /**
     * @param Stock $subject
     * @param Product $product
     * @param int|null $status
     * @return array
     */
    public function beforeAssignStatusToProduct(
        Stock $subject,
        Product $product,
        ?int $status = null
    ): array {
        if ($product->getTypeId() !== AggregateProductType::TYPE_CODE) {
            return [$product, $status];
        }

        try {
            $stockId = $this->getStockIdForByStoreId->execute((int)$product->getStoreId());
            $stockItemData = $this->getStockItemData->execute($product->getSku(), $stockId);
        } catch (NoSuchEntityException $exception) {
            return [$product, $status];
        }

        if ($stockItemData !== null
            && !((bool)$stockItemData[GetStockItemDataInterface::IS_SALABLE])
        ) {
            return [$product, $status];
        }

        // The index can be stale in scheduled mode; recheck through the same qty-aware child rule
        // the stock indexer and the GetIsQtySalable plugin apply, so every stock path agrees.
        $status = $this->isAggregateProductChildrenSalable->execute($product->getSku(), $stockId) ? 1 : 0;

        return [$product, $status];
    }
}
