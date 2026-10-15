<?php
declare(strict_types=1);
/**
 * Copyright © David Nimorwicz. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Nfourteen\InventoryAggregateProduct\Indexer;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status as ProductStatus;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\InventoryCatalogApi\Api\DefaultStockProviderInterface;
use Magento\InventoryConfigurationApi\Model\InventoryConfigurationInterface;
use Magento\InventoryIndexer\Indexer\IndexStructure;
use Magento\InventoryIndexer\Indexer\InventoryIndexer;
use Magento\InventoryIndexer\Indexer\SiblingSelectBuilderInterface;
use Magento\InventoryIndexer\Indexer\Stock\ReservationsIndexTable;
use Magento\InventoryMultiDimensionalIndexerApi\Model\IndexAlias;
use Magento\InventoryMultiDimensionalIndexerApi\Model\IndexNameBuilder;
use Magento\InventoryMultiDimensionalIndexerApi\Model\IndexNameResolverInterface;
use Nfourteen\AggregateProduct\Model\Product\Type\Aggregate;

/**
 * Builds the stock index rows of aggregates from the already indexed rows of their children.
 *
 * An aggregate is salable on a stock only when every child is enabled, salable on that stock and
 * holds its link qty, the same rule the legacy aggregate indexer applies to the default stock.
 * Children that don't manage stock or allow backorders skip the qty gate, as they do for bundles.
 */
class SelectBuilder implements SiblingSelectBuilderInterface
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly IndexNameBuilder $indexNameBuilder,
        private readonly IndexNameResolverInterface $indexNameResolver,
        private readonly MetadataPool $metadataPool,
        private readonly DefaultStockProviderInterface $defaultStockProvider,
        private readonly InventoryConfigurationInterface $inventoryConfiguration,
        private readonly ReservationsIndexTable $reservationsIndexTable,
        private readonly EavConfig $eavConfig
    ) {
    }

    public function getSelect(int $stockId, array $skuList = [], IndexAlias $indexAlias = IndexAlias::MAIN): Select
    {
        $connection = $this->resourceConnection->getConnection();
        $linkField = $this->metadataPool->getMetadata(ProductInterface::class)->getLinkField();
        $defaultStockId = $this->defaultStockProvider->getId();

        $select = $connection->select()
            ->from(
                ['parent' => $this->resourceConnection->getTableName('catalog_product_entity')],
                [IndexStructure::SKU => 'parent.sku']
            )->joinLeft(
                ['link' => $this->resourceConnection->getTableName('catalog_product_aggregate_link')],
                'link.parent_id = parent.entity_id',
                []
            )->joinLeft(
                ['child' => $this->resourceConnection->getTableName('catalog_product_entity')],
                'child.entity_id = link.product_id AND child.required_options = 0',
                []
            )->joinLeft(
                ['child_status' => $this->resourceConnection->getTableName('catalog_product_entity_int')],
                "child_status.{$linkField} = child.{$linkField}"
                . ' AND child_status.attribute_id = ' . $this->getStatusAttributeId()
                . ' AND child_status.store_id = 0',
                []
            )->joinLeft(
                ['child_stock' => $this->getIndexTableName($stockId, $indexAlias)],
                'child_stock.sku = child.sku',
                []
            )->joinLeft(
                ['child_stock_item' => $this->resourceConnection->getTableName('cataloginventory_stock_item')],
                'child_stock_item.product_id = child.entity_id AND child_stock_item.stock_id = ' . $defaultStockId,
                []
            )->joinLeft(
                ['reservations' => $this->resourceConnection->getTableName(
                    $this->reservationsIndexTable->getTableName($stockId)
                )],
                'reservations.sku = child.sku',
                []
            )->joinLeft(
                ['parent_stock_item' => $this->resourceConnection->getTableName('cataloginventory_stock_item')],
                'parent_stock_item.product_id = parent.entity_id AND parent_stock_item.stock_id = ' . $defaultStockId,
                []
            )->where(
                'parent.type_id = ?',
                Aggregate::TYPE_CODE
            )->group(
                ['parent.sku']
            )->columns([
                IndexStructure::QUANTITY => new \Zend_Db_Expr('0'),
                IndexStructure::IS_SALABLE => $connection->getCheckSql(
                    'COUNT(link.product_id) = 0 OR MIN(COALESCE(parent_stock_item.is_in_stock, 1)) = 0',
                    '0',
                    'MIN(' . $this->getChildAvailableExpression() . ')'
                ),
            ]);

        if ($skuList !== []) {
            $select->where('parent.sku IN (?)', $skuList);
        }

        return $select;
    }

    private function getChildAvailableExpression(): string
    {
        return (string)$this->resourceConnection->getConnection()->getCheckSql(
            'child_status.value = ' . ProductStatus::STATUS_ENABLED,
            $this->getManageStockExpression(),
            '0'
        );
    }

    private function getManageStockExpression(): string
    {
        $connection = $this->resourceConnection->getConnection();

        if ($this->inventoryConfiguration->getManageStock()) {
            return (string)$connection->getCheckSql(
                'child_stock_item.use_config_manage_stock = 0 AND child_stock_item.manage_stock = 0',
                '1',
                $this->getBackordersExpression()
            );
        }

        return (string)$connection->getCheckSql(
            'child_stock_item.use_config_manage_stock = 0 AND child_stock_item.manage_stock = 1',
            $this->getBackordersExpression(),
            '1'
        );
    }

    private function getBackordersExpression(): string
    {
        $connection = $this->resourceConnection->getConnection();
        $isSalable = 'COALESCE(child_stock.is_salable, 0)';

        if ($this->inventoryConfiguration->getBackorders()) {
            return (string)$connection->getCheckSql(
                'child_stock_item.use_config_backorders = 0 AND child_stock_item.backorders = 0',
                $this->getHoldsLinkQtyExpression(),
                $isSalable
            );
        }

        return (string)$connection->getCheckSql(
            'child_stock_item.use_config_backorders = 0 AND child_stock_item.backorders > 0',
            $isSalable,
            $this->getHoldsLinkQtyExpression()
        );
    }

    private function getHoldsLinkQtyExpression(): string
    {
        $connection = $this->resourceConnection->getConnection();
        $salableQty = '(COALESCE(child_stock.quantity, 0) - COALESCE(child_stock_item.min_qty, 0) + '
            . $connection->getIfNullSql('reservations.reservation_qty') . ')';

        return (string)$connection->getCheckSql(
            'link.qty > ' . $salableQty,
            '0',
            'COALESCE(child_stock.is_salable, 0)'
        );
    }

    private function getIndexTableName(int $stockId, IndexAlias $indexAlias): string
    {
        $indexName = $this->indexNameBuilder->setIndexId(InventoryIndexer::INDEXER_ID)
            ->addDimension('stock_', (string)$stockId)
            ->setAlias($indexAlias->value)
            ->build();

        return $this->indexNameResolver->resolveName($indexName);
    }

    private function getStatusAttributeId(): int
    {
        return (int)$this->eavConfig->getAttribute(Product::ENTITY, ProductInterface::STATUS)->getId();
    }
}
