<?php
declare(strict_types=1);
/**
 * Copyright © David Nimorwicz. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Nfourteen\InventoryAggregateProduct\Service\IsProductSalableCondition;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\InventoryCatalogApi\Model\GetProductIdsBySkusInterface;
use Magento\InventoryCatalogApi\Model\GetProductTypesBySkusInterface;
use Magento\InventoryCatalogApi\Model\GetSkusByProductIdsInterface;
use Magento\InventoryConfigurationApi\Api\Data\StockItemConfigurationInterface;
use Magento\InventoryConfigurationApi\Api\GetStockItemConfigurationInterface;
use Magento\InventorySalesApi\Api\AreProductsSalableInterface;
use Magento\InventorySalesApi\Api\GetProductSalableQtyInterface;
use Nfourteen\AggregateProduct\Model\Product\Child\ChildStatusResolver;
use Nfourteen\AggregateProduct\Model\Product\Type\Aggregate;
use Nfourteen\AggregateProduct\Model\ResourceModel\RelationMetadata;

class IsAggregateProductChildrenSalable
{
    public function __construct(
        private readonly RelationMetadata $relationMetadata,
        private readonly AreProductsSalableInterface $areProductsSalable,
        private readonly GetProductIdsBySkusInterface $getProductIdsBySkus,
        private readonly GetSkusByProductIdsInterface $getSkusByProductIds,
        private readonly GetProductTypesBySkusInterface $getProductTypesBySkus,
        private readonly GetProductSalableQtyInterface $getProductSalableQty,
        private readonly GetStockItemConfigurationInterface $getStockItemConfiguration,
        private readonly ChildStatusResolver $childStatusResolver
    ) {
    }

    /**
     * Check whether every enabled child of an aggregate is salable with enough qty for its link requirement.
     *
     * @param string $sku
     * @param int $stockId
     * @return bool
     */
    public function execute(string $sku, int $stockId): bool
    {
        try {
            $productTypes = $this->getProductTypesBySkus->execute([$sku]);
            if (!isset($productTypes[$sku]) || $productTypes[$sku] !== Aggregate::TYPE_CODE) {
                return true;
            }

            $productId = $this->getProductIdsBySkus->execute([$sku])[$sku];
            $childrenIdsWithQty = $this->relationMetadata->getChildrenIdsWithQty((int)$productId);

            if (empty($childrenIdsWithQty)) {
                return false;
            }

            $childrenIds = array_keys($childrenIdsWithQty);
            $childrenSkus = $this->getSkusByProductIds->execute($childrenIds);

            if (count($childrenSkus) !== count($childrenIds)) {
                return false;
            }

            // MSI salability ignores product status, so a disabled child would otherwise pass.
            // Any child that is not enabled makes the aggregate unsalable.
            if (!$this->childStatusResolver->allEnabled($childrenIds)) {
                return false;
            }

            $salabilityResults = $this->areProductsSalable->execute($childrenSkus, $stockId);

            foreach ($salabilityResults as $salabilityResult) {
                if ($salabilityResult && !$salabilityResult->isSalable()) {
                    return false;
                }
            }

            // Check each child has enough salable qty for the configured qty requirement.
            $skuToProductId = array_flip($childrenSkus);
            foreach ($childrenSkus as $childSku) {
                $childProductId = $skuToProductId[$childSku];
                $requiredQty = $childrenIdsWithQty[$childProductId];

                // Mirror the stock indexer's rule for unmanaged/backorder children (see
                // ResourceModel\Indexer\Stock\Aggregate)
                $childStockConfig = $this->getStockItemConfiguration->execute($childSku, $stockId);
                if (!$childStockConfig->isManageStock()
                    || $childStockConfig->getBackorders() !== StockItemConfigurationInterface::BACKORDERS_NO
                ) {
                    continue;
                }

                $salableQty = $this->getProductSalableQty->execute($childSku, $stockId);
                if ($salableQty < $requiredQty) {
                    return false;
                }
            }

            return true;
        } catch (NoSuchEntityException $e) {
            return false;
        }
    }
}
