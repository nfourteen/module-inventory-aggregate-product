<?php
declare(strict_types=1);
/**
 * Copyright © Nfourteen. All Rights Reserved.
 * See COPYING.txt for license details.
 **/

namespace Nfourteen\InventoryAggregateProduct\Plugin\InventorySales;

use Magento\InventorySalesApi\Model\GetSkuFromOrderItemInterface;
use Magento\InventorySalesApi\Model\ReturnProcessor\ProcessRefundItemsInterface;
use Magento\InventorySalesApi\Model\ReturnProcessor\Request\ItemsToRefundInterfaceFactory;
use Magento\Sales\Api\Data\CreditmemoInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\SalesInventory\Model\Order\ReturnProcessor;
use Nfourteen\AggregateProduct\Model\Product\Type\Aggregate;
use Nfourteen\InventoryAggregateProduct\Service\AggregateQtyResolver;

/**
 * Restore inventory for aggregate children on credit-memo return-to-stock.
 *
 * Core's ProcessReturnQtyOnCreditMemoPlugin is an around plugin that never calls $proceed, so an
 * afterExecute would be stranded behind it — this plugin must wrap it as the outermost around
 * (lower sortOrder in di.xml). Core skips the aggregate parent (no source items) and the hidden
 * child order items carry an unscaled credit-memo qty (createByOrder gives each dummy child qty
 * 1), so inventory drifts on every return; the admin return-to-stock cascade also copies the
 * parent flag onto the children, which would double-restore them.
 *
 * Strip aggregate child ids from the list core sees, let core handle ordinary items, then expand
 * each returned aggregate parent into child SKUs scaled by the ordered-qty ratio.
 */
class ProcessRefundItemsForAggregatePlugin
{
    public function __construct(
        private readonly GetSkuFromOrderItemInterface $getSkuFromOrderItem,
        private readonly ItemsToRefundInterfaceFactory $itemsToRefundFactory,
        private readonly ProcessRefundItemsInterface $processRefundItems,
        private readonly AggregateQtyResolver $aggregateQtyResolver
    ) {
    }

    /**
     * @param ReturnProcessor $subject
     * @param callable $proceed
     * @param CreditmemoInterface $creditmemo
     * @param OrderInterface $order
     * @param array $returnToStockItems
     * @param bool $isAutoReturn
     * @return void
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundExecute(
        ReturnProcessor $subject,
        callable $proceed,
        CreditmemoInterface $creditmemo,
        OrderInterface $order,
        array $returnToStockItems = [],
        bool $isAutoReturn = false
    ): void {
        $aggregateChildItemIds = $this->collectAggregateChildItemIds($creditmemo);

        // Hide aggregate children from core so it cannot restore them at the unscaled qty; we own
        // their restoration below. Core still handles the parent (skipped as a disabled type) and
        // every ordinary item exactly as before.
        $coreReturnToStockItems = array_values(array_filter(
            $returnToStockItems,
            static fn ($itemId) => !in_array((int) $itemId, $aggregateChildItemIds, true)
        ));

        $proceed($creditmemo, $order, $coreReturnToStockItems, $isAutoReturn);

        $items = [];
        foreach ($creditmemo->getItems() as $creditmemoItem) {
            $orderItem = $creditmemoItem->getOrderItem();
            if ($orderItem === null || $orderItem->getProductType() !== Aggregate::TYPE_CODE) {
                continue;
            }

            // Gate matches core: the admin checks "return to stock" on the visible parent row,
            // so its order-item id (not the hidden children) lands in $returnToStockItems.
            // Strict + int-normalized, consistent with the child-strip filter above, so a stray
            // 0/'' entry can never false-match a parent id.
            if (!$isAutoReturn
                && !in_array((int) $orderItem->getId(), array_map('intval', $returnToStockItems), true)
            ) {
                continue;
            }

            $children = $orderItem->getChildrenItems();
            if (empty($children)) {
                continue;
            }

            $parentRefundQty = (float) $creditmemoItem->getQty();
            if ($parentRefundQty <= 0) {
                continue;
            }

            // Cumulative invoiced-not-refunded at parent grain; scaled per child below to
            // reproduce core's processedQuantity = qtyInvoiced - qtyRefunded + refundQty.
            // INVARIANT: by the time this runs, getQtyRefunded() ALREADY includes the current
            // credit memo's qty (RefundOperation::register() increments it before the
            // sales_order_creditmemo_save_after observer fires ReturnProcessor). The
            // "+ $childRefundQty" term added to $childProcessedQty below cancels that increment —
            // exactly as core does. Do not "simplify" away that term or the math silently breaks.
            $parentNetInvoiced = (float) $orderItem->getQtyInvoiced() - (float) $orderItem->getQtyRefunded();

            foreach ($children as $child) {
                $ratio = $this->aggregateQtyResolver->getQtyPerParent($child, $orderItem);
                $childRefundQty = $ratio * $parentRefundQty;
                if ($childRefundQty <= 0) {
                    continue;
                }

                $childProcessedQty = ($ratio * $parentNetInvoiced) + $childRefundQty;
                $childSku = $this->getSkuFromOrderItem->execute($child);

                $items[$childSku] = [
                    'qty' => ($items[$childSku]['qty'] ?? 0.0) + $childRefundQty,
                    'processedQty' => ($items[$childSku]['processedQty'] ?? 0.0) + $childProcessedQty,
                ];
            }
        }

        if (empty($items)) {
            return;
        }

        $itemsToRefund = [];
        foreach ($items as $sku => $data) {
            $itemsToRefund[] = $this->itemsToRefundFactory->create([
                'sku' => (string) $sku,
                'qty' => $data['qty'],
                'processedQty' => $data['processedQty'],
            ]);
        }

        $this->processRefundItems->execute($order, $itemsToRefund, $returnToStockItems);
    }

    /**
     * Order-item ids of every hidden child belonging to an aggregate parent on the credit memo.
     *
     * @param CreditmemoInterface $creditmemo
     * @return int[]
     */
    private function collectAggregateChildItemIds(CreditmemoInterface $creditmemo): array
    {
        $childItemIds = [];
        foreach ($creditmemo->getItems() as $creditmemoItem) {
            $orderItem = $creditmemoItem->getOrderItem();
            if ($orderItem === null || $orderItem->getProductType() !== Aggregate::TYPE_CODE) {
                continue;
            }
            foreach ($orderItem->getChildrenItems() as $child) {
                $childItemIds[] = (int) $child->getId();
            }
        }

        return $childItemIds;
    }
}
