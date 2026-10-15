<?php
declare(strict_types=1);
/**
 * Copyright © David Nimorwicz. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Nfourteen\InventoryAggregateProduct\Indexer;

use Magento\Framework\App\ResourceConnection;
use Magento\InventoryIndexer\Indexer\SiblingProductsProviderInterface;

/**
 * Finds the aggregates that bundle the given child SKUs, so they are reindexed with their children.
 */
class SiblingProductsProvider implements SiblingProductsProviderInterface
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    public function getSkus(array $skus): array
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->distinct()
            ->from(
                ['child' => $this->resourceConnection->getTableName('catalog_product_entity')],
                []
            )->joinInner(
                ['link' => $this->resourceConnection->getTableName('catalog_product_aggregate_link')],
                'link.product_id = child.entity_id',
                []
            )->joinInner(
                ['parent' => $this->resourceConnection->getTableName('catalog_product_entity')],
                'parent.entity_id = link.parent_id',
                ['sku' => 'parent.sku']
            )->where(
                'child.sku IN (?)',
                $skus
            );

        return $connection->fetchCol($select);
    }
}
