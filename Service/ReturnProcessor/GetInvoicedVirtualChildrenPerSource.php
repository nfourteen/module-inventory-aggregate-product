<?php
declare(strict_types=1);
/**
 * Copyright © David Nimorwicz. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Nfourteen\InventoryAggregateProduct\Service\ReturnProcessor;

use Magento\Framework\Exception\LocalizedException;
use Magento\InventoryApi\Api\GetSourceItemsBySkuInterface;
use Magento\InventoryApi\Api\GetSourcesAssignedToStockOrderedByPriorityInterface;
use Magento\InventoryCatalogApi\Api\DefaultSourceProviderInterface;
use Magento\InventorySalesApi\Model\GetSkuFromOrderItemInterface;
use Magento\InventorySalesApi\Model\ReturnProcessor\GetSourceDeductedOrderItemsInterface;
use Magento\InventorySalesApi\Model\ReturnProcessor\Result\SourceDeductedOrderItemFactory;
use Magento\InventorySalesApi\Model\ReturnProcessor\Result\SourceDeductedOrderItemsResult;
use Magento\InventorySalesApi\Model\ReturnProcessor\Result\SourceDeductedOrderItemsResultFactory;
use Magento\InventorySalesApi\Model\StockByWebsiteIdResolverInterface;
use Magento\Sales\Api\Data\InvoiceItemInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use Nfourteen\AggregateProduct\Model\Product\Type\Aggregate;
use Nfourteen\InventoryAggregateProduct\Service\AggregateQtyResolver;

/**
 * Report the virtual children of a returned aggregate as taken off a source when it was invoiced.
 *
 * A virtual product leaves its source on invoice, so a refund puts it back on that source. Core's
 * GetInvoicedItemsPerSourceByPriority finds those deductions for ordinary virtual products, but
 * skips dummy order items, and every aggregate child is a dummy. Without this selector the
 * refund of a virtual child finds nothing deducted, leaves the source short and places a
 * positive reservation instead.
 *
 * Mirrors core: the quantity is what the invoices hold, scaled by the child's qty per pack, and
 * the source is the highest-priority source of the order's stock that holds the SKU.
 */
final class GetInvoicedVirtualChildrenPerSource implements GetSourceDeductedOrderItemsInterface
{
    public function __construct(
        private readonly GetSkuFromOrderItemInterface $getSkuFromOrderItem,
        private readonly AggregateQtyResolver $aggregateQtyResolver,
        private readonly StockByWebsiteIdResolverInterface $stockByWebsiteIdResolver,
        private readonly GetSourcesAssignedToStockOrderedByPriorityInterface $getSourcesAssignedToStockOrderedByPriority,
        private readonly GetSourceItemsBySkuInterface $getSourceItemsBySku,
        private readonly DefaultSourceProviderInterface $defaultSourceProvider,
        private readonly SourceDeductedOrderItemFactory $sourceDeductedOrderItemFactory,
        private readonly SourceDeductedOrderItemsResultFactory $sourceDeductedOrderItemsResultFactory
    ) {
    }

    /**
     * @param OrderInterface $order
     * @param array $returnToStockItems
     * @return SourceDeductedOrderItemsResult[]
     */
    public function execute(OrderInterface $order, array $returnToStockItems): array
    {
        $invoicedQtyPerSku = $this->getInvoicedQtyPerVirtualChildSku(
            $order,
            array_map('intval', $returnToStockItems)
        );
        if ($invoicedQtyPerSku === []) {
            return [];
        }

        $stockId = (int) $this->stockByWebsiteIdResolver
            ->execute((int) $order->getStore()->getWebsiteId())
            ->getStockId();

        $itemsPerSource = [];
        foreach ($invoicedQtyPerSku as $sku => $qty) {
            $itemsPerSource[$this->getSourceCodeWithHighestPriority((string) $sku, $stockId)][] =
                $this->sourceDeductedOrderItemFactory->create(['sku' => (string) $sku, 'quantity' => $qty]);
        }

        return array_map(
            fn (string $sourceCode) => $this->sourceDeductedOrderItemsResultFactory->create([
                'sourceCode' => $sourceCode,
                'items' => $itemsPerSource[$sourceCode],
            ]),
            array_map('strval', array_keys($itemsPerSource))
        );
    }

    /**
     * @param OrderInterface $order
     * @param int[] $returnToStockItemIds
     * @return array<string, float>
     */
    private function getInvoicedQtyPerVirtualChildSku(OrderInterface $order, array $returnToStockItemIds): array
    {
        $invoicedQtyPerSku = [];
        foreach ($order->getInvoiceCollection() as $invoice) {
            foreach ($invoice->getItems() as $invoiceItem) {
                $parent = $invoiceItem->getOrderItem();
                if (!$this->isReturnedAggregate($parent, $returnToStockItemIds)) {
                    continue;
                }

                foreach ($this->getVirtualChildren($parent) as $child) {
                    $sku = $this->getSkuFromOrderItem->execute($child);
                    $invoicedQtyPerSku[$sku] = ($invoicedQtyPerSku[$sku] ?? 0.0)
                        + $this->getInvoicedChildQty($invoiceItem, $child, $parent);
                }
            }
        }

        return $invoicedQtyPerSku;
    }

    /**
     * @param OrderItemInterface|null $orderItem
     * @param int[] $returnToStockItemIds
     * @return bool
     */
    private function isReturnedAggregate(?OrderItemInterface $orderItem, array $returnToStockItemIds): bool
    {
        return $orderItem !== null
            && $orderItem->getProductType() === Aggregate::TYPE_CODE
            && in_array((int) $orderItem->getItemId(), $returnToStockItemIds, true);
    }

    /**
     * @param OrderItemInterface $parent
     * @return OrderItemInterface[]
     */
    private function getVirtualChildren(OrderItemInterface $parent): array
    {
        return array_filter(
            $parent->getChildrenItems() ?? [],
            static fn (OrderItemInterface $child) => (bool) $child->getIsVirtual()
        );
    }

    private function getInvoicedChildQty(
        InvoiceItemInterface $invoiceItem,
        OrderItemInterface $child,
        OrderItemInterface $parent
    ): float {
        return $this->aggregateQtyResolver->getQtyPerParent($child, $parent) * (float) $invoiceItem->getQty();
    }

    private function getSourceCodeWithHighestPriority(string $sku, int $stockId): string
    {
        try {
            $sourceCodesHoldingSku = array_map(
                static fn ($sourceItem) => $sourceItem->getSourceCode(),
                $this->getSourceItemsBySku->execute($sku)
            );
            $sourceCodesOfStock = array_map(
                static fn ($source) => $source->getSourceCode(),
                $this->getSourcesAssignedToStockOrderedByPriority->execute($stockId)
            );
        } catch (LocalizedException) {
            return $this->defaultSourceProvider->getCode();
        }

        return array_values(array_intersect($sourceCodesOfStock, $sourceCodesHoldingSku))[0]
            ?? $this->defaultSourceProvider->getCode();
    }
}
