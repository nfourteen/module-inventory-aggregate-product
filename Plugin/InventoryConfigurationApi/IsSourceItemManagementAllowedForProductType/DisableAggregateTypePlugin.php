<?php
declare(strict_types=1);
/**
 * Copyright © Nfourteen. All Rights Reserved.
 * See COPYING.txt for license details.
 **/

namespace Nfourteen\InventoryAggregateProduct\Plugin\InventoryConfigurationApi\IsSourceItemManagementAllowedForProductType;

use Magento\InventoryConfigurationApi\Model\IsSourceItemManagementAllowedForProductTypeInterface;
use Nfourteen\AggregateProduct\Model\Product\Type\Aggregate;

/**
 * Disable Source items management for Aggregate product type.
 *
 * Aggregate products derive their stock status from child products,
 * so they should not have their own source items.
 */
class DisableAggregateTypePlugin
{
    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundExecute(
        IsSourceItemManagementAllowedForProductTypeInterface $subject,
        callable $proceed,
        string $productType
    ): bool {
        if ($productType === Aggregate::TYPE_CODE) {
            return false;
        }

        return $proceed($productType);
    }
}
