<?php
declare(strict_types=1);
/**
 * Copyright © Nfourteen. All Rights Reserved.
 * See COPYING.txt for license details.
 **/

namespace Nfourteen\InventoryAggregateProduct\Plugin\InventorySales\Model\IsProductSalableCondition;

use Magento\InventoryCatalogApi\Model\GetProductTypesBySkusInterface;
use Magento\InventorySalesApi\Model\GetIsQtySalableInterface;
use Nfourteen\AggregateProduct\Model\Product\Type\Aggregate as AggregateProductType;
use Nfourteen\InventoryAggregateProduct\Service\IsProductSalableCondition\IsAggregateProductChildrenSalable;

/**
 * Aggregate parents have no source items, so the core qty-based salability check always reports
 * them unsalable. Replace the result with the child-level salability check.
 */
class GetIsQtySalableForAggregateProduct
{
    public function __construct(
        private readonly IsAggregateProductChildrenSalable $isAggregateProductChildrenSalable,
        private readonly GetProductTypesBySkusInterface $getProductTypesBySkus
    ) {
    }

    public function afterExecute(
        GetIsQtySalableInterface $subject,
        bool $isSalable,
        string $sku,
        int $stockId
    ): bool {
        $productTypes = $this->getProductTypesBySkus->execute([$sku]);
        if (($productTypes[$sku] ?? null) !== AggregateProductType::TYPE_CODE) {
            return $isSalable;
        }

        return $this->isAggregateProductChildrenSalable->execute($sku, $stockId);
    }
}
