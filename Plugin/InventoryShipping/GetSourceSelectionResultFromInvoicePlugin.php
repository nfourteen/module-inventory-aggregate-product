<?php
declare(strict_types=1);
/**
 * Copyright © David Nimorwicz. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Nfourteen\InventoryAggregateProduct\Plugin\InventoryShipping;

use Magento\InventorySalesApi\Model\GetSkuFromOrderItemInterface;
use Magento\InventoryShipping\Model\GetSourceSelectionResultFromInvoice;
use Magento\InventorySourceSelectionApi\Api\Data\ItemRequestInterfaceFactory;
use Magento\InventorySourceSelectionApi\Api\Data\SourceSelectionResultInterface;
use Magento\InventorySourceSelectionApi\Api\Data\SourceSelectionResultInterfaceFactory;
use Magento\InventorySourceSelectionApi\Api\GetDefaultSourceSelectionAlgorithmCodeInterface;
use Magento\InventorySourceSelectionApi\Api\SourceSelectionServiceInterface;
use Magento\InventorySourceSelectionApi\Model\GetInventoryRequestFromOrder;
use Magento\Sales\Api\Data\InvoiceInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use Nfourteen\AggregateProduct\Model\Product\Type\Aggregate;
use Nfourteen\InventoryAggregateProduct\Service\AggregateQtyResolver;

/**
 * Handle virtual child source deduction for aggregate products during invoice.
 *
 * Core skips aggregate parents (not virtual) and virtual children (isDummy=true).
 * This plugin expands aggregate parent invoice items into their virtual child SKUs
 * for source selection, then merges with the core result.
 */
class GetSourceSelectionResultFromInvoicePlugin
{
    public function __construct(
        private readonly GetInventoryRequestFromOrder $getInventoryRequestFromOrder,
        private readonly GetDefaultSourceSelectionAlgorithmCodeInterface $getDefaultSourceSelectionAlgorithmCode,
        private readonly SourceSelectionServiceInterface $sourceSelectionService,
        private readonly ItemRequestInterfaceFactory $itemRequestFactory,
        private readonly GetSkuFromOrderItemInterface $getSkuFromOrderItem,
        private readonly AggregateQtyResolver $aggregateQtyResolver,
        private readonly SourceSelectionResultInterfaceFactory $sourceSelectionResultFactory
    ) {
    }

    public function afterExecute(
        GetSourceSelectionResultFromInvoice $subject,
        SourceSelectionResultInterface $result,
        InvoiceInterface $invoice
    ): SourceSelectionResultInterface {
        $selectionRequestItems = [];

        foreach ($invoice->getItems() as $invoiceItem) {
            $orderItem = $invoiceItem->getOrderItem();
            if ($orderItem === null
                || $orderItem->getProductType() !== Aggregate::TYPE_CODE
                || empty($orderItem->getChildrenItems())
            ) {
                continue;
            }

            foreach ($orderItem->getChildrenItems() as $child) {
                if (!$child->getIsVirtual()) {
                    continue;
                }

                $qtyPerParent = $this->aggregateQtyResolver->getQtyPerParent($child, $orderItem);
                $deductQty = $qtyPerParent * (float) $invoiceItem->getQty();
                $childSku = $this->getSkuFromOrderItem->execute($child);

                $selectionRequestItems[] = $this->itemRequestFactory->create([
                    'sku' => $childSku,
                    'qty' => $this->castQty($child, $deductQty),
                ]);
            }
        }

        if (empty($selectionRequestItems)) {
            return $result;
        }

        $order = $invoice->getOrder();
        $inventoryRequest = $this->getInventoryRequestFromOrder->execute(
            (int) $order->getEntityId(),
            $selectionRequestItems
        );

        $selectionAlgorithmCode = $this->getDefaultSourceSelectionAlgorithmCode->execute();
        $aggregateResult = $this->sourceSelectionService->execute($inventoryRequest, $selectionAlgorithmCode);

        $mergedItems = array_merge(
            $result->getSourceSelectionItems(),
            $aggregateResult->getSourceSelectionItems()
        );

        return $this->sourceSelectionResultFactory->create([
            'sourceItemSelections' => $mergedItems,
            'isShippable' => $result->isShippable() && $aggregateResult->isShippable(),
        ]);
    }

    private function castQty(OrderItemInterface $item, float $qty): float
    {
        if ($item->getIsQtyDecimal()) {
            return $qty > 0 ? $qty : 0.0;
        }

        $intQty = (int) $qty;
        return $intQty > 0 ? (float) $intQty : 0.0;
    }
}
