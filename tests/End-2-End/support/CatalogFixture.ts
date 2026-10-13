import { runMagentoPhp } from './MagentoCommand';

export type ChildType = 'simple' | 'virtual';

export interface ChildProduct {
    sku: string;
    name: string;
    type: ChildType;
    linkQty: number;
}

/**
 * Creates the products a spec starts from, reads back the stock Magento keeps for them, and
 * removes everything the run created.
 *
 * The products are set up through Magento rather than the browser because they are not what is
 * under test. Every SKU starts with a per-run prefix, so cleanup removes exactly this run's
 * products and nothing else, even after a failure halfway through.
 */
export class CatalogFixture {
    static readonly STORE_ID = 1;
    static readonly DEFAULT_SOURCE = 'default';

    readonly prefix: string;

    constructor() {
        this.prefix = `e2e-aggr-inv-${Date.now()}-${Math.random().toString(36).slice(2, 6)}`;
    }

    sku(suffix: string): string {
        return `${this.prefix}-${suffix}`;
    }

    createChildProduct(child: ChildProduct, price: number, stockQty: number): void {
        runMagentoPhp(`
            $product = $om->create(\\Magento\\Catalog\\Api\\Data\\ProductInterfaceFactory::class)->create();
            $product->setTypeId(${JSON.stringify(child.type)})
                ->setAttributeSetId(4)
                ->setSku(${JSON.stringify(child.sku)})
                ->setName(${JSON.stringify(child.name)})
                ->setUrlKey(${JSON.stringify(child.sku)})
                ->setPrice(${price})
                ->setWeight(${child.type === 'simple' ? 1 : 'null'})
                ->setStatus(\\Magento\\Catalog\\Model\\Product\\Attribute\\Source\\Status::STATUS_ENABLED)
                ->setVisibility(\\Magento\\Catalog\\Model\\Product\\Visibility::VISIBILITY_NOT_VISIBLE)
                ->setWebsiteIds([1])
                ->setStockData(['qty' => ${stockQty}, 'is_in_stock' => 1, 'manage_stock' => 1]);
            $om->get(\\Magento\\Catalog\\Api\\ProductRepositoryInterface::class)->save($product);
        `);
    }

    /**
     * Links go through the core module's own reconciler, the same path the admin save takes.
     */
    createAggregateProduct(sku: string, name: string, price: number, children: ChildProduct[]): void {
        const links = children.map((child) => ({ sku: child.sku, qty: child.linkQty }));
        runMagentoPhp(`
            $repository = $om->get(\\Magento\\Catalog\\Api\\ProductRepositoryInterface::class);
            $product = $om->create(\\Magento\\Catalog\\Api\\Data\\ProductInterfaceFactory::class)->create();
            $product->setTypeId(\\Nfourteen\\AggregateProduct\\Model\\Product\\Type\\Aggregate::TYPE_CODE)
                ->setAttributeSetId(4)
                ->setSku(${JSON.stringify(sku)})
                ->setName(${JSON.stringify(name)})
                ->setUrlKey(${JSON.stringify(sku)})
                ->setPrice(${price})
                ->setStatus(\\Magento\\Catalog\\Model\\Product\\Attribute\\Source\\Status::STATUS_ENABLED)
                ->setVisibility(\\Magento\\Catalog\\Model\\Product\\Visibility::VISIBILITY_BOTH)
                ->setWebsiteIds([1])
                ->setStockData(['use_config_manage_stock' => 0, 'manage_stock' => 1, 'is_in_stock' => 1]);
            $parentId = (int) $repository->save($product)->getId();
            $links = array_map(
                static fn (array $link) => $om->create(\\Nfourteen\\AggregateProduct\\Model\\RelationMetadataFactory::class)
                    ->create()
                    ->setData([
                        'parent_id' => $parentId,
                        'product_id' => (int) $repository->get($link['sku'])->getId(),
                        'qty' => $link['qty'],
                    ]),
                json_decode(${JSON.stringify(JSON.stringify(links))}, true)
            );
            $om->get(\\Nfourteen\\AggregateProduct\\Model\\RelationMetadataReconciler::class)->reconcile($parentId, $links);
        `);
    }

    /**
     * Does what the `indexer_update_all_views` cron job does. Stores with indexers on "Update by
     * Schedule" otherwise show a new product without a price or stock status until cron runs.
     */
    processScheduledIndexes(): void {
        runMagentoPhp(`
            $om->get(\\Magento\\Indexer\\Model\\Processor::class)->updateMview();
        `);
    }

    /**
     * The storefront URL as the store itself builds it, so the suite works with or without store
     * codes in URLs and regardless of the URL suffix configuration.
     */
    productUrl(sku: string): string {
        return runMagentoPhp(`
            echo $om->get(\\Magento\\Catalog\\Api\\ProductRepositoryInterface::class)
                ->get(${JSON.stringify(sku)}, false, ${CatalogFixture.STORE_ID})
                ->getProductUrl();
        `).trim();
    }

    /**
     * The physical quantity on the default source, what "Quantity per Source" shows in the admin.
     */
    sourceQty(sku: string): number {
        return parseFloat(runMagentoPhp(`
            $items = $om->get(\\Magento\\InventoryApi\\Api\\GetSourceItemsBySkuInterface::class)->execute(${JSON.stringify(sku)});
            echo array_values(array_filter(
                $items,
                static fn ($item) => $item->getSourceCode() === ${JSON.stringify(CatalogFixture.DEFAULT_SOURCE)}
            ))[0]->getQuantity();
        `).trim());
    }

    /**
     * The sum of every open reservation for the SKU. Once an order is fully processed its
     * reservations cancel each other out, so anything but zero is stock held for nothing.
     */
    openReservationQty(sku: string): number {
        return parseFloat(runMagentoPhp(`
            $connection = $om->get(\\Magento\\Framework\\App\\ResourceConnection::class)->getConnection();
            $select = $connection->select()
                ->from($connection->getTableName('inventory_reservation'), ['total' => new \\Magento\\Framework\\DB\\Sql\\Expression('COALESCE(SUM(quantity), 0)')])
                ->where('sku = ?', ${JSON.stringify(sku)});
            echo $connection->fetchOne($select);
        `).trim());
    }

    salableQty(sku: string): number {
        return parseFloat(runMagentoPhp(`
            echo $om->get(\\Magento\\InventorySalesApi\\Api\\GetProductSalableQtyInterface::class)
                ->execute(${JSON.stringify(sku)}, 1);
        `).trim());
    }

    removeAll(): void {
        runMagentoPhp(`
            $om->get(\\Magento\\Framework\\Registry::class)->register('isSecureArea', true);
            $collection = $om->create(\\Magento\\Catalog\\Model\\ResourceModel\\Product\\Collection::class)
                ->addAttributeToFilter('sku', ['like' => ${JSON.stringify(`${this.prefix}-%`)}]);
            $repository = $om->get(\\Magento\\Catalog\\Api\\ProductRepositoryInterface::class);
            array_map(
                static fn ($product) => $repository->delete($product),
                array_reverse($collection->getItems())
            );
        `);
    }
}
