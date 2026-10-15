<?php
declare(strict_types=1);
/**
 * Copyright © David Nimorwicz. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Nfourteen\InventoryAggregateProduct\Plugin\AggregateProduct;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\InventoryCatalogApi\Model\GetSkusByProductIdsInterface;
use Magento\InventoryIndexer\Indexer\SourceItem\SkuListInStockFactory;
use Magento\InventoryIndexer\Indexer\Stock\GetAllStockIds;
use Magento\InventoryIndexer\Indexer\Stock\SkuListsProcessor;
use Nfourteen\AggregateProduct\Model\RelationReindexer;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * MSI only reindexes an aggregate on a non-default stock when one of its children's source items
 * changes. Adding, removing or requantifying a child changes no source item, so the aggregate's
 * rows are rebuilt here on every stock, like MSI does for bundles after a selection change.
 */
class ReindexStocksAfterRelationChangePlugin
{
    public function __construct(
        private readonly GetSkusByProductIdsInterface $getSkusByProductIds,
        private readonly GetAllStockIds $getAllStockIds,
        private readonly SkuListInStockFactory $skuListInStockFactory,
        private readonly SkuListsProcessor $skuListsProcessor,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param int[] $parentIds
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterReindex(
        RelationReindexer $subject,
        null $result,
        array $parentIds,
        bool $forceReindex = false
    ): void {
        $skus = $this->getExistingSkus($parentIds);
        if ($skus === []) {
            return;
        }

        try {
            $this->skuListsProcessor->reindexList(array_map(
                fn (int $stockId) => $this->skuListInStockFactory->create(['stockId' => $stockId, 'skuList' => $skus]),
                $this->getAllStockIds->execute()
            ));
        } catch (Throwable $e) {
            $this->logger->error('Aggregate stock index update failed', [
                'skus' => $skus,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param int[] $productIds
     * @return string[]
     */
    private function getExistingSkus(array $productIds): array
    {
        $skus = [];
        foreach (array_unique(array_map('intval', $productIds)) as $productId) {
            try {
                $skus[] = (string)$this->getSkusByProductIds->execute([$productId])[$productId];
            } catch (NoSuchEntityException $e) {
                continue;
            }
        }

        return $skus;
    }
}
