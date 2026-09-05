<?php
declare(strict_types=1);
/**
 * Copyright © Nfourteen. All Rights Reserved.
 * See COPYING.txt for license details.
 **/

namespace Nfourteen\InventoryAggregateProduct\Plugin\InventoryShipping;

use Magento\InventorySalesApi\Model\GetSkuFromOrderItemInterface;
use Magento\InventoryShipping\Model\GetItemsToDeductFromShipment;
use Magento\InventorySourceDeductionApi\Model\ItemToDeductInterface;
use Magento\InventorySourceDeductionApi\Model\ItemToDeductInterfaceFactory;
use Magento\Sales\Api\Data\ShipmentItemInterface;
use Magento\Sales\Model\Order\Shipment;
use Nfourteen\AggregateProduct\Model\Product\Type\Aggregate;
use Nfourteen\InventoryAggregateProduct\Service\AggregateQtyResolver;

/**
 * Replace parent-SKU deduction items with child-SKU deduction items for aggregate products.
 *
 * The core GetItemsToDeductFromShipment::processComplexItem() falls into the configurable
 * else-branch for aggregate products (no bundle_selection_attributes), producing deduction
 * items with the parent SKU. Since aggregate parents have no source items, we must replace
 * those with the actual child SKU deductions using aggregate_config quantities.
 */
class GetItemsToDeductFromShipmentPlugin
{
    public function __construct(
        private readonly GetSkuFromOrderItemInterface $getSkuFromOrderItem,
        private readonly ItemToDeductInterfaceFactory $itemToDeductFactory,
        private readonly AggregateQtyResolver $aggregateQtyResolver
    ) {
    }

    public function afterExecute(
        GetItemsToDeductFromShipment $subject,
        array $result,
        Shipment $shipment
    ): array {
        $parentSkus = [];
        $childDeductions = [];

        /** @var ShipmentItemInterface $shipmentItem */
        foreach ($shipment->getAllItems() as $shipmentItem) {
            $orderItem = $shipmentItem->getOrderItem();
            if ($orderItem === null) {
                continue;
            }

            if ($orderItem->getProductType() !== Aggregate::TYPE_CODE) {
                continue;
            }

            $children = $orderItem->getChildrenItems();
            if (empty($children)) {
                continue;
            }

            $parentSkus[] = $orderItem->getSku();

            foreach ($orderItem->getChildrenItems() as $child) {
                if ($child->getIsVirtual() || $child->getLockedDoShip()) {
                    continue;
                }

                $qtyPerParent = $this->aggregateQtyResolver->getQtyPerParent($child, $orderItem);
                $deductQty = $qtyPerParent * (float) $shipmentItem->getQty();
                $childSku = $this->getSkuFromOrderItem->execute($child);

                if (!isset($childDeductions[$childSku])) {
                    $childDeductions[$childSku] = 0.0;
                }
                $childDeductions[$childSku] += $deductQty;
            }
        }

        if (empty($parentSkus)) {
            return $result;
        }

        $parentSkuSet = array_flip($parentSkus);

        // Remove parent SKU items produced by core's processComplexItem configurable fallback
        $filtered = [];
        foreach ($result as $item) {
            if (!isset($parentSkuSet[$item->getSku()])) {
                $filtered[] = $item;
            }
        }

        foreach ($childDeductions as $sku => $qty) {
            $filtered[] = $this->itemToDeductFactory->create([
                'sku' => (string) $sku,
                'qty' => $qty,
            ]);
        }

        return $this->groupItemsBySku($filtered);
    }

    /**
     * @param ItemToDeductInterface[] $items
     * @return ItemToDeductInterface[]
     */
    private function groupItemsBySku(array $items): array
    {
        $grouped = [];
        foreach ($items as $item) {
            $sku = $item->getSku();
            if (!isset($grouped[$sku])) {
                $grouped[$sku] = 0.0;
            }
            $grouped[$sku] += $item->getQty();
        }

        $result = [];
        foreach ($grouped as $sku => $qty) {
            $result[] = $this->itemToDeductFactory->create([
                'sku' => (string) $sku,
                'qty' => $qty,
            ]);
        }

        return $result;
    }
}
