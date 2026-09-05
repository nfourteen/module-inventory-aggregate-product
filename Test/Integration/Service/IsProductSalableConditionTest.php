<?php
declare(strict_types=1);
/**
 * Copyright © Nfourteen. All Rights Reserved.
 * See COPYING.txt for license details.
 **/

namespace Nfourteen\InventoryAggregateProduct\Test\Integration\Service;

use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\Checkout\Test\Fixture\PlaceOrder as PlaceOrderFixture;
use Magento\Checkout\Test\Fixture\SetBillingAddress as SetBillingAddressFixture;
use Magento\Checkout\Test\Fixture\SetDeliveryMethod as SetDeliveryMethodFixture;
use Magento\Checkout\Test\Fixture\SetGuestEmail as SetGuestEmailFixture;
use Magento\Checkout\Test\Fixture\SetPaymentMethod as SetPaymentMethodFixture;
use Magento\Checkout\Test\Fixture\SetShippingAddress as SetShippingAddressFixture;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Indexer\IndexerInterface;
use Magento\InventoryApi\Api\Data\SourceItemInterface;
use Magento\InventoryApi\Api\Data\SourceItemInterfaceFactory;
use Magento\InventoryApi\Api\SourceItemRepositoryInterface;
use Magento\InventoryApi\Api\SourceItemsDeleteInterface;
use Magento\InventoryApi\Api\SourceItemsSaveInterface;
use Magento\InventoryApi\Test\Fixture\Source as SourceFixture;
use Magento\InventoryApi\Test\Fixture\SourceItem as SourceItemFixture;
use Magento\InventoryApi\Test\Fixture\Stock as StockFixture;
use Magento\InventoryApi\Test\Fixture\StockSourceLinks as StockSourceLinksFixture;
use Magento\InventoryCatalogApi\Api\DefaultSourceProviderInterface;
use Magento\InventoryIndexer\Indexer\InventoryIndexer;
use Magento\InventorySalesApi\Api\IsProductSalableInterface;
use Magento\InventorySalesApi\Test\Fixture\StockSalesChannels as StockSalesChannelsFixture;
use Magento\Quote\Test\Fixture\AddProductToCart as AddProductToCartFixture;
use Magento\Quote\Test\Fixture\GuestCart as GuestCartFixture;
use Magento\Store\Test\Fixture\Group as GroupFixture;
use Magento\Store\Test\Fixture\Store as StoreFixture;
use Magento\Store\Test\Fixture\Website as WebsiteFixture;
use Magento\TestFramework\Fixture\AppIsolation;
use Magento\TestFramework\Fixture\Config;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorage;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use Nfourteen\AggregateProduct\Test\Fixture\AggregateProduct as AggregateProductFixture;
use PHPUnit\Framework\TestCase;

/**
 * Tests MSI salability condition for aggregate products.
 *
 * Uses IsProductSalableInterface to verify aggregate salability based on
 * child product stock status, multi-stock availability, and qty-configuration awareness.
 */
class IsProductSalableConditionTest extends TestCase
{
    private ?IsProductSalableInterface $isProductSalable = null;
    private ?DataFixtureStorage $fixtures = null;

    protected function setUp(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $this->isProductSalable = $objectManager->get(IsProductSalableInterface::class);
        $this->fixtures = DataFixtureStorageManager::getStorage();
    }

    #[
        DataFixture(ProductFixture::class, ['sku' => 'child_salable_1'], as: 'child1'),
        DataFixture(ProductFixture::class, ['sku' => 'child_salable_2'], as: 'child2'),
        DataFixture(SourceItemFixture::class, [
            'sku' => '$child1.sku$',
            'source_code' => 'default',
            'quantity' => 100,
            'status' => 1,
        ]),
        DataFixture(SourceItemFixture::class, [
            'sku' => '$child2.sku$',
            'source_code' => 'default',
            'quantity' => 100,
            'status' => 1,
        ]),
        DataFixture(AggregateProductFixture::class, [
            'sku' => 'aggregate_all_children_salable',
            '_children' => [
                ['product_id' => '$child1.id$', 'qty' => 1],
                ['product_id' => '$child2.id$', 'qty' => 1],
            ],
        ], as: 'aggregate'),
    ]
    public function testMsiSalabilityReturnsTrueWhenAllChildrenSalable(): void
    {
        $aggregateProduct = $this->fixtures->get('aggregate');

        $isSalable = $this->isProductSalable->execute($aggregateProduct->getSku(), 1);

        $this->assertTrue($isSalable, 'Aggregate should be salable when all children are salable');
    }

    #[
        DataFixture(ProductFixture::class, ['sku' => 'child_salable_test'], as: 'child1'),
        DataFixture(ProductFixture::class, ['sku' => 'child_unsalable_test'], as: 'child2'),
        DataFixture(SourceItemFixture::class, [
            'sku' => '$child1.sku$',
            'source_code' => 'default',
            'quantity' => 100,
            'status' => 1,
        ]),
        DataFixture(SourceItemFixture::class, [
            'sku' => '$child2.sku$',
            'source_code' => 'default',
            'quantity' => 0,
            'status' => 0,
        ]),
        DataFixture(AggregateProductFixture::class, [
            'sku' => 'aggregate_child_unsalable_test',
            '_children' => [
                ['product_id' => '$child1.id$', 'qty' => 1],
                ['product_id' => '$child2.id$', 'qty' => 1],
            ],
        ], as: 'aggregate'),
    ]
    public function testMsiSalabilityReturnsFalseWhenChildUnsalable(): void
    {
        $aggregateProduct = $this->fixtures->get('aggregate');

        $isSalable = $this->isProductSalable->execute($aggregateProduct->getSku(), 1);

        $this->assertFalse($isSalable, 'Aggregate should NOT be salable when any child is unsalable');
    }

    #[DataFixture(AggregateProductFixture::class, ['sku' => 'aggregate_no_children'], as: 'aggregate')]
    public function testMsiSalabilityReturnsFalseWhenAggregateHasNoChildren(): void
    {
        $aggregateProduct = $this->fixtures->get('aggregate');

        $isSalable = $this->isProductSalable->execute($aggregateProduct->getSku(), 1);

        $this->assertFalse($isSalable, 'Aggregate with no children should NOT be salable');
    }

    #[
        DbIsolation(false),
        // Websites
        DataFixture(WebsiteFixture::class, ['code' => 'eu_website', 'name' => 'EU Website'], as: 'eu_website'),
        DataFixture(GroupFixture::class, ['website_id' => '$eu_website.id$', 'name' => 'EU Group'], as: 'eu_group'),
        DataFixture(StoreFixture::class, ['store_group_id' => '$eu_group.id$', 'code' => 'store_for_eu_website', 'name' => 'EU Store']),
        DataFixture(WebsiteFixture::class, ['code' => 'us_website', 'name' => 'US Website'], as: 'us_website'),
        DataFixture(GroupFixture::class, ['website_id' => '$us_website.id$', 'name' => 'US Group'], as: 'us_group'),
        DataFixture(StoreFixture::class, ['store_group_id' => '$us_group.id$', 'code' => 'store_for_us_website', 'name' => 'US Store']),
        DataFixture(WebsiteFixture::class, ['code' => 'global_website', 'name' => 'Global Website'], as: 'global_website'),
        DataFixture(GroupFixture::class, ['website_id' => '$global_website.id$', 'name' => 'Global Group'], as: 'global_group'),
        DataFixture(StoreFixture::class, ['store_group_id' => '$global_group.id$', 'code' => 'store_for_global_website', 'name' => 'Global Store']),
        // Sources
        DataFixture(SourceFixture::class, ['source_code' => 'eu-1', 'name' => 'EU-source-1', 'enabled' => true, 'country_id' => 'FR']),
        DataFixture(SourceFixture::class, ['source_code' => 'us-1', 'name' => 'US-source-1', 'enabled' => true, 'country_id' => 'US']),
        // Stocks
        DataFixture(StockFixture::class, ['name' => 'EU-stock'], as: 'eu_stock'),
        DataFixture(StockFixture::class, ['name' => 'US-stock'], as: 'us_stock'),
        DataFixture(StockFixture::class, ['name' => 'Global-stock'], as: 'global_stock'),
        // Stock-source links
        DataFixture(StockSourceLinksFixture::class, [['stock_id' => '$eu_stock.stock_id$', 'source_code' => 'eu-1']]),
        DataFixture(StockSourceLinksFixture::class, [['stock_id' => '$us_stock.stock_id$', 'source_code' => 'us-1']]),
        DataFixture(StockSourceLinksFixture::class, [
            ['stock_id' => '$global_stock.stock_id$', 'source_code' => 'eu-1'],
            ['stock_id' => '$global_stock.stock_id$', 'source_code' => 'us-1'],
        ]),
        // Sales channels
        DataFixture(StockSalesChannelsFixture::class, ['stock_id' => '$eu_stock.stock_id$', 'sales_channels' => ['eu_website']]),
        DataFixture(StockSalesChannelsFixture::class, ['stock_id' => '$us_stock.stock_id$', 'sales_channels' => ['us_website']]),
        DataFixture(StockSalesChannelsFixture::class, ['stock_id' => '$global_stock.stock_id$', 'sales_channels' => ['global_website']]),
        // Products + aggregate
        DataFixture(ProductFixture::class, ['sku' => 'child_eu_only'], as: 'child1'),
        DataFixture(ProductFixture::class, ['sku' => 'child_us_only'], as: 'child2'),
        DataFixture(AggregateProductFixture::class, [
            'sku' => 'aggregate_multi_stock_test',
            '_children' => [
                ['product_id' => '$child1.id$', 'qty' => 1],
                ['product_id' => '$child2.id$', 'qty' => 1],
            ],
        ], as: 'aggregate'),
    ]
    public function testAggregateSalabilityVariesByStock(): void
    {
        $aggregate = $this->fixtures->get('aggregate');
        $euStockId = (int) $this->fixtures->get('eu_stock')->getStockId();
        $usStockId = (int) $this->fixtures->get('us_stock')->getStockId();
        $globalStockId = (int) $this->fixtures->get('global_stock')->getStockId();

        $this->unassignFromDefaultSource(['child_eu_only', 'child_us_only']);

        $this->assignProductToSource('child_eu_only', 'eu-1', 100);
        $this->assignProductToSource('child_us_only', 'us-1', 100);

        $this->reindexInventory();

        $this->assertFalse(
            $this->isProductSalable->execute($aggregate->getSku(), $euStockId),
            'Aggregate should NOT be salable on EU stock (missing child_us_only)'
        );

        $this->assertFalse(
            $this->isProductSalable->execute($aggregate->getSku(), $usStockId),
            'Aggregate should NOT be salable on US stock (missing child_eu_only)'
        );

        $this->assertTrue(
            $this->isProductSalable->execute($aggregate->getSku(), $globalStockId),
            'Aggregate should be salable on Global stock (both children available)'
        );
    }

    /**
     * Child has 8 units, configuration=10 -> zero complete aggregate units can be assembled.
     * IsAggregateProductChildrenSalable gates on GetProductSalableQty vs the configured link
     * qty (not just binary in-stock status), so the PDP shows out of stock instead of an
     * "Add to Cart" the cart would reject.
     */
    #[
        DataFixture(ProductFixture::class, ['sku' => 'salqty-pdp-child'], as: 'child'),
        DataFixture(SourceItemFixture::class, [
            'sku' => '$child.sku$',
            'source_code' => 'default',
            'quantity' => 8,
            'status' => 1,
        ]),
        DataFixture(AggregateProductFixture::class, [
            'sku' => 'salqty-pdp-parent',
            '_children' => [
                ['product_id' => '$child.id$', 'qty' => 10],
            ],
        ], as: 'aggregate'),
    ]
    public function testNotSalableWhenChildStockInsufficientForQtyConfiguration(): void
    {
        $aggregate = $this->fixtures->get('aggregate');

        $isSalable = $this->isProductSalable->execute($aggregate->getSku(), 1);

        $this->assertFalse(
            $isSalable,
            'Aggregate should show as not salable when child stock (8) '
            . 'cannot form a complete unit at configuration 10'
        );
    }

    /**
     * After placing an order that fully depletes child stock via reservation,
     * the aggregate should no longer be salable.
     */
    #[
        DbIsolation(false),
        AppIsolation(true),
        Config('carriers/flatrate/active', 1, 'store', 'default'),
        Config('payment/checkmo/active', 1, 'store', 'default'),
        DataFixture(ProductFixture::class, ['sku' => 'salqty-dep-child', 'price' => 5.0], as: 'child'),
        DataFixture(SourceItemFixture::class, [
            'sku' => '$child.sku$',
            'source_code' => 'default',
            'quantity' => 20,
            'status' => 1,
        ]),
        DataFixture(AggregateProductFixture::class, [
            'sku' => 'salqty-dep-parent',
            'price' => 10.0,
            '_children' => [
                ['product_id' => '$child.id$', 'qty' => 5],
            ],
        ], as: 'aggregate'),
        DataFixture(GuestCartFixture::class, as: 'cart'),
        DataFixture(AddProductToCartFixture::class, ['cart_id' => '$cart.id$', 'product_id' => '$aggregate.id$', 'qty' => 4]),
        DataFixture(SetBillingAddressFixture::class, ['cart_id' => '$cart.id$']),
        DataFixture(SetShippingAddressFixture::class, ['cart_id' => '$cart.id$']),
        DataFixture(SetGuestEmailFixture::class, ['cart_id' => '$cart.id$']),
        DataFixture(SetDeliveryMethodFixture::class, ['cart_id' => '$cart.id$', 'carrier_code' => 'flatrate', 'method_code' => 'flatrate']),
        DataFixture(SetPaymentMethodFixture::class, ['cart_id' => '$cart.id$', 'method' => 'checkmo']),
        DataFixture(PlaceOrderFixture::class, ['cart_id' => '$cart.id$'], as: 'order'),
    ]
    public function testNotSalableAfterOrderFullyDepletsChildStock(): void
    {
        $aggregate = $this->fixtures->get('aggregate');

        $isSalable = $this->isProductSalable->execute($aggregate->getSku(), 1);

        $this->assertFalse(
            $isSalable,
            'Aggregate should NOT be salable after order depletes all child stock '
            . '(stock=20, ordered 4 × configuration 5 = 20 reserved)'
        );
    }

    #[
        DataFixture(ProductFixture::class, ['sku' => 'agg-managed-child'], as: 'child1'),
        DataFixture(SourceItemFixture::class, [
            'sku' => '$child1.sku$',
            'source_code' => 'default',
            'quantity' => 100,
            'status' => 1,
        ]),
        DataFixture(
            ProductFixture::class,
            ['sku' => 'agg-unmanaged-child', 'extension_attributes' => ['stock_item' => [
                'use_config_manage_stock' => 0,
                'manage_stock' => 0,
                'qty' => 0,
            ]]],
            as: 'child2'
        ),
        DataFixture(AggregateProductFixture::class, [
            'sku' => 'agg-unmanaged-parent',
            '_children' => [
                ['product_id' => '$child1.id$', 'qty' => 1],
                ['product_id' => '$child2.id$', 'qty' => 10],
            ],
        ], as: 'aggregate'),
    ]
    public function testMsiSalableWhenChildDoesNotManageStock(): void
    {
        $aggregate = $this->fixtures->get('aggregate');

        $this->assertTrue(
            $this->isProductSalable->execute($aggregate->getSku(), 1),
            'Aggregate must stay salable when a child does not manage stock, even at qty 0 vs link qty 10 '
            . '(MSI runtime must agree with the stock indexer\'s unmanaged-stock rule)'
        );
    }

    #[
        DataFixture(ProductFixture::class, ['sku' => 'agg-normal-child'], as: 'child1'),
        DataFixture(SourceItemFixture::class, [
            'sku' => '$child1.sku$',
            'source_code' => 'default',
            'quantity' => 100,
            'status' => 1,
        ]),
        DataFixture(
            ProductFixture::class,
            ['sku' => 'agg-backorder-child', 'extension_attributes' => ['stock_item' => [
                'use_config_backorders' => 0,
                'backorders' => 1,
                'qty' => 0,
                'is_in_stock' => true,
            ]]],
            as: 'child2'
        ),
        DataFixture(SourceItemFixture::class, [
            'sku' => '$child2.sku$',
            'source_code' => 'default',
            'quantity' => 0,
            'status' => 1,
        ]),
        DataFixture(AggregateProductFixture::class, [
            'sku' => 'agg-backorder-parent',
            '_children' => [
                ['product_id' => '$child1.id$', 'qty' => 1],
                ['product_id' => '$child2.id$', 'qty' => 2],
            ],
        ], as: 'aggregate'),
    ]
    public function testMsiSalableWhenChildAllowsBackorders(): void
    {
        $aggregate = $this->fixtures->get('aggregate');

        $this->assertTrue(
            $this->isProductSalable->execute($aggregate->getSku(), 1),
            'Aggregate must stay salable when a child allows backorders, even at qty 0 vs link qty 2'
        );
    }

    private function assignProductToSource(string $sku, string $sourceCode, float $qty): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $factory = $objectManager->get(SourceItemInterfaceFactory::class);
        $save = $objectManager->get(SourceItemsSaveInterface::class);

        $sourceItem = $factory->create();
        $sourceItem->setSku($sku);
        $sourceItem->setSourceCode($sourceCode);
        $sourceItem->setQuantity($qty);
        $sourceItem->setStatus(SourceItemInterface::STATUS_IN_STOCK);

        $save->execute([$sourceItem]);
    }

    private function reindexInventory(): void
    {
        $indexer = Bootstrap::getObjectManager()->get(IndexerInterface::class);
        $indexer->load(InventoryIndexer::INDEXER_ID);
        $indexer->reindexAll();
    }

    private function unassignFromDefaultSource(array $skus): void
    {
        $objectManager = Bootstrap::getObjectManager();

        $searchCriteriaBuilder = $objectManager->get(SearchCriteriaBuilder::class);
        $defaultSourceProvider = $objectManager->get(DefaultSourceProviderInterface::class);
        $sourceItemRepository = $objectManager->get(SourceItemRepositoryInterface::class);
        $sourceItemsDelete = $objectManager->get(SourceItemsDeleteInterface::class);

        $searchCriteria = $searchCriteriaBuilder
            ->addFilter(SourceItemInterface::SKU, $skus, 'in')
            ->addFilter(SourceItemInterface::SOURCE_CODE, $defaultSourceProvider->getCode())
            ->create();
        $sourceItems = $sourceItemRepository->getList($searchCriteria)->getItems();

        if (count($sourceItems)) {
            $sourceItemsDelete->execute($sourceItems);
        }
    }
}
