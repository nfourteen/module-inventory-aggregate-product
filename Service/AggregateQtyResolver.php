<?php
declare(strict_types=1);
/**
 * Copyright © David Nimorwicz. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Nfourteen\InventoryAggregateProduct\Service;

use Magento\Framework\Serialize\Serializer\Json;
use Magento\Sales\Api\Data\OrderItemInterface;
use Psr\Log\LoggerInterface;

/**
 * Resolve the per-parent quantity for an aggregate child order item.
 *
 * Derives the ratio from ordered quantities and falls back to the aggregate_config
 * product option only when the parent ordered qty is unavailable.
 */
class AggregateQtyResolver
{
    public function __construct(
        private readonly Json $jsonSerializer,
        private readonly LoggerInterface $logger
    ) {
    }

    public function getQtyPerParent(OrderItemInterface $child, OrderItemInterface $parent): float
    {
        // Derive ratio from ordered quantities — this matches what core Magento uses
        // for reservation placement, ensuring shipment/cancellation compensation stays
        // consistent even if aggregate_config disagrees due to a stale cart edit.
        $parentQty = (float) $parent->getQtyOrdered();
        if ($parentQty > 0.0) {
            return (float) $child->getQtyOrdered() / $parentQty;
        }

        // A parent with no ordered qty should never reach the deduction/compensation path;
        // log it so the data anomaly is visible before we fall back to the configured ratio.
        $this->logger->warning('Aggregate parent order item has non-positive ordered qty', [
            'parent_order_item_id' => $parent->getItemId(),
            'child_order_item_id' => $child->getItemId(),
        ]);

        // Fallback to aggregate_config when parent qty is unavailable
        $productOptions = $child->getProductOptions();

        if (isset($productOptions['aggregate_config'])) {
            $aggregateConfig = $productOptions['aggregate_config'];
            if (is_string($aggregateConfig)) {
                try {
                    $aggregateConfig = $this->jsonSerializer->unserialize($aggregateConfig);
                } catch (\InvalidArgumentException $e) {
                    $this->logger->warning('Malformed aggregate_config JSON on order item', [
                        'order_item_id' => $child->getItemId(),
                        'error' => $e->getMessage(),
                    ]);
                    $aggregateConfig = [];
                }
            }
            if (isset($aggregateConfig['qty'])) {
                return (float) $aggregateConfig['qty'];
            }
        }

        // Last resort: callers multiply this ratio by the parent qty, so a per-parent ratio of
        // 1.0 (one child unit per parent) is the only unit-correct default here.
        return 1.0;
    }
}
