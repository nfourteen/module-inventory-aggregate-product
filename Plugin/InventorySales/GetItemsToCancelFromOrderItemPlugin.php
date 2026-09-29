<?php
declare(strict_types=1);
/**
 * Copyright © David Nimorwicz. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Nfourteen\InventoryAggregateProduct\Plugin\InventorySales;

use Magento\InventorySales\Model\GetItemsToCancelFromOrderItem;
use Magento\InventorySalesApi\Api\Data\ItemToSellInterfaceFactory;
use Magento\InventorySalesApi\Model\GetSkuFromOrderItemInterface;
use Magento\Sales\Model\Order\Item as OrderItem;
use Nfourteen\AggregateProduct\Model\Product\Type\Aggregate;
use Nfourteen\InventoryAggregateProduct\Service\AggregateQtyResolver;

/**
 * Replace parent-SKU cancellation items with child-SKU items for aggregate products.
 *
 * Core processComplexItem() falls into the configurable else-branch for aggregate (no
 * bundle_selection_attributes) and emits parent-SKU items; the parent has no source items, so
 * the compensation reservation is silently dropped. Cover ALL children (virtual and physical)
 * to fully reverse the placement reservation.
 */
class GetItemsToCancelFromOrderItemPlugin
{
    public function __construct(
        private readonly GetSkuFromOrderItemInterface $getSkuFromOrderItem,
        private readonly ItemToSellInterfaceFactory $itemToSellFactory,
        private readonly AggregateQtyResolver $aggregateQtyResolver
    ) {
    }

    public function afterExecute(
        GetItemsToCancelFromOrderItem $subject,
        array $result,
        OrderItem $orderItem
    ): array {
        if ($orderItem->getProductType() !== Aggregate::TYPE_CODE) {
            return $result;
        }

        $children = $orderItem->getChildrenItems();
        if (empty($children)) {
            return $result;
        }

        $parentQtyToCancel = (float) $orderItem->getQtyOrdered()
            - max((float) $orderItem->getQtyShipped(), (float) $orderItem->getQtyInvoiced())
            - (float) $orderItem->getQtyCanceled();

        if ($parentQtyToCancel <= 0) {
            return [];
        }

        $childItems = [];
        foreach ($children as $child) {
            $qtyPerParent = $this->aggregateQtyResolver->getQtyPerParent($child, $orderItem);
            $cancelQty = $qtyPerParent * $parentQtyToCancel;
            $childSku = $this->getSkuFromOrderItem->execute($child);

            if (!isset($childItems[$childSku])) {
                $childItems[$childSku] = 0.0;
            }
            $childItems[$childSku] += $cancelQty;
        }

        $items = [];
        foreach ($childItems as $sku => $qty) {
            $items[] = $this->itemToSellFactory->create([
                'sku' => (string) $sku,
                'qty' => $qty,
            ]);
        }

        return $items;
    }
}
