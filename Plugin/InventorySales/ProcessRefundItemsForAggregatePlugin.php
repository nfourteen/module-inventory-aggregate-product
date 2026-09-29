<?php
declare(strict_types=1);
/**
 * Copyright © David Nimorwicz. All rights reserved.
 * See LICENSE.txt for license details.
 */

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
 * Core skips the aggregate parent (no source items) and the hidden child order items carry an
 * unscaled credit-memo qty (createByOrder gives each dummy child qty 1), so inventory drifts on
 * every return; the admin return-to-stock cascade in CreditmemoLoader also copies the parent flag
 * onto the children, which would double-restore them.
 *
 * So beforeExecute() hides the children from core, and afterExecute() restores them at the scaled
 * qty once core has finished with the ordinary items.
 *
 * On the sortOrder="-1" in di.xml: core's ProcessReturnQtyOnCreditMemoPlugin is an aroundExecute
 * that never calls $proceed, so ReturnProcessor::execute never runs and neither does any plugin
 * ordered after core. A sortOrder below core's default 0 puts these listeners outside core's
 * around in the interceptor chain, which is what lets afterExecute() run at all.
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
     * Hide aggregate children from core so it cannot restore them at the unscaled qty.
     *
     * afterExecute() owns their restoration. Core still handles the parent (skipped as a disabled
     * type) and every ordinary item exactly as before. The parents stay in the list, which is what
     * keeps the children matched downstream: GetInvoicedItemsPerSourceByPriority::isValidItem()
     * accepts an item whose PARENT id is in $returnToStockItems, so removing the child ids costs
     * nothing there.
     *
     * @param ReturnProcessor $subject
     * @param CreditmemoInterface $creditmemo
     * @param OrderInterface $order
     * @param array $returnToStockItems
     * @param bool $isAutoReturn
     * @return array
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function beforeExecute(
        ReturnProcessor $subject,
        CreditmemoInterface $creditmemo,
        OrderInterface $order,
        array $returnToStockItems = [],
        bool $isAutoReturn = false
    ): array {
        $aggregateChildItemIds = $this->collectAggregateChildItemIds($creditmemo);

        $coreReturnToStockItems = array_values(array_filter(
            $returnToStockItems,
            static fn ($itemId) => !in_array((int) $itemId, $aggregateChildItemIds, true)
        ));

        return [$creditmemo, $order, $coreReturnToStockItems, $isAutoReturn];
    }

    /**
     * Expand each returned aggregate parent into child SKUs scaled by the ordered-qty ratio.
     *
     * $returnToStockItems arrives as beforeExecute() returned it, children already stripped: the
     * interceptor hands after-listeners the arguments as before-listeners left them. That is what
     * we want here, because the only ids this method reads are the parents', which are untouched.
     *
     * @param ReturnProcessor $subject
     * @param null $result
     * @param CreditmemoInterface $creditmemo
     * @param OrderInterface $order
     * @param array $returnToStockItems
     * @param bool $isAutoReturn
     * @return void
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterExecute(
        ReturnProcessor $subject,
        $result,
        CreditmemoInterface $creditmemo,
        OrderInterface $order,
        array $returnToStockItems = [],
        bool $isAutoReturn = false
    ): void {
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
            // "+ $childRefundQty" term added to $childProcessedQty below cancels that increment,
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
