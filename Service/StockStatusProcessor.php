<?php
declare(strict_types=1);
/**
 * Copyright © Nfourteen. All Rights Reserved.
 * See COPYING.txt for license details.
 **/

namespace Nfourteen\InventoryAggregateProduct\Service;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\InventoryCatalogApi\Model\CompositeProductStockStatusProcessorInterface;
use Magento\InventoryCatalogApi\Model\GetProductIdsBySkusInterface;
use Nfourteen\AggregateProduct\Model\Inventory\ChangeParentStockStatus;

/**
 * Updates the parent aggregate's stock status when a child's stock changes through MSI.
 */
class StockStatusProcessor implements CompositeProductStockStatusProcessorInterface
{
    public function __construct(
        private readonly ChangeParentStockStatus $changeParentStockStatus,
        private readonly GetProductIdsBySkusInterface $getProductIdsBySkus
    ) {
    }

    public function execute(array $skus): void
    {
        // Resolve per SKU so one unknown or deleted SKU cannot skip the parent-status
        // recompute for the rest of the batch.
        $productIds = [];
        foreach ($skus as $sku) {
            try {
                $productIds[] = (int)$this->getProductIdsBySkus->execute([$sku])[$sku];
            } catch (NoSuchEntityException $e) {
                continue;
            }
        }

        $this->changeParentStockStatus->execute($productIds);
    }
}
