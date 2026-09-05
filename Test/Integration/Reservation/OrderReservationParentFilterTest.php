<?php
declare(strict_types=1);
/**
 * Copyright © Nfourteen. All Rights Reserved.
 * See COPYING.txt for license details.
 **/

namespace Nfourteen\InventoryAggregateProduct\Test\Integration\Reservation;

use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\Checkout\Test\Fixture\PlaceOrder as PlaceOrderFixture;
use Magento\Checkout\Test\Fixture\SetBillingAddress as SetBillingAddressFixture;
use Magento\Checkout\Test\Fixture\SetDeliveryMethod as SetDeliveryMethodFixture;
use Magento\Checkout\Test\Fixture\SetGuestEmail as SetGuestEmailFixture;
use Magento\Checkout\Test\Fixture\SetPaymentMethod as SetPaymentMethodFixture;
use Magento\Checkout\Test\Fixture\SetShippingAddress as SetShippingAddressFixture;
use Magento\Framework\App\ResourceConnection;
use Magento\InventoryApi\Test\Fixture\SourceItem as SourceItemFixture;
use Magento\Quote\Test\Fixture\AddProductToCart as AddProductToCartFixture;
use Magento\Quote\Test\Fixture\GuestCart as GuestCartFixture;
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
 * Placement reservations must land on the child SKUs only: source-item management is disabled
 * for the aggregate type, so MSI's item-to-sell extraction excludes the parent SKU on both the
 * deferred and non-deferred branches. Assertions are scoped to this order's reservation rows
 * (by metadata increment id) so historical ledger state in a reused DB (TESTS_CLEANUP disabled)
 * cannot skew them; the PlaceOrder fixture revert cancels the order, which compensates the
 * ledger by itself.
 */
class OrderReservationParentFilterTest extends TestCase
{
    private ?ResourceConnection $resourceConnection = null;
    private ?DataFixtureStorage $fixtures = null;

    protected function setUp(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $this->resourceConnection = $objectManager->get(ResourceConnection::class);
        $this->fixtures = DataFixtureStorageManager::getStorage();
    }

    #[
        DbIsolation(false),
        AppIsolation(true),
        Config('carriers/flatrate/active', 1, 'store', 'default'),
        Config('payment/checkmo/active', 1, 'store', 'default'),
        DataFixture(ProductFixture::class, ['sku' => 'agg-res-child-a', 'price' => 5.0], as: 'child_a'),
        DataFixture(ProductFixture::class, ['sku' => 'agg-res-child-b', 'price' => 3.0], as: 'child_b'),
        DataFixture(SourceItemFixture::class, ['sku' => '$child_a.sku$', 'source_code' => 'default', 'quantity' => 100, 'status' => 1]),
        DataFixture(SourceItemFixture::class, ['sku' => '$child_b.sku$', 'source_code' => 'default', 'quantity' => 100, 'status' => 1]),
        DataFixture(AggregateProductFixture::class, [
            'sku' => 'agg-res-parent',
            'price' => 10.0,
            '_children' => [
                ['product_id' => '$child_a.id$', 'qty' => 2],
                ['product_id' => '$child_b.id$', 'qty' => 3],
            ],
        ], as: 'aggregate'),
        DataFixture(GuestCartFixture::class, as: 'cart'),
        DataFixture(AddProductToCartFixture::class, ['cart_id' => '$cart.id$', 'product_id' => '$aggregate.id$', 'qty' => 2]),
        DataFixture(SetBillingAddressFixture::class, ['cart_id' => '$cart.id$']),
        DataFixture(SetShippingAddressFixture::class, ['cart_id' => '$cart.id$']),
        DataFixture(SetGuestEmailFixture::class, ['cart_id' => '$cart.id$']),
        DataFixture(SetDeliveryMethodFixture::class, ['cart_id' => '$cart.id$', 'carrier_code' => 'flatrate', 'method_code' => 'flatrate']),
        DataFixture(SetPaymentMethodFixture::class, ['cart_id' => '$cart.id$', 'method' => 'checkmo']),
        DataFixture(PlaceOrderFixture::class, ['cart_id' => '$cart.id$'], as: 'order'),
    ]
    public function testReservationsHitChildrenAndExcludeParent(): void
    {
        $order = $this->fixtures->get('order');
        $reservedBySku = $this->getPlacementReservations((string)$order->getIncrementId());

        // Ordered 2 aggregate units: child_a reserved 2 × 2 = 4, child_b reserved 2 × 3 = 6.
        $this->assertSame(
            -4.0,
            $reservedBySku['agg-res-child-a'] ?? null,
            'child_a reservation must equal parentQty (2) × linkQty (2)'
        );
        $this->assertSame(
            -6.0,
            $reservedBySku['agg-res-child-b'] ?? null,
            'child_b reservation must equal parentQty (2) × linkQty (3)'
        );

        // The parent SKU has no source-item management, so it must never be reserved against.
        $this->assertArrayNotHasKey(
            'agg-res-parent',
            $reservedBySku,
            'aggregate parent SKU must be filtered out of the order reservation set'
        );
    }

    /**
     * Sum of order_placed reservation rows written for the given order, keyed by SKU.
     *
     * @param string $incrementId
     * @return array<string, float>
     */
    private function getPlacementReservations(string $incrementId): array
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->resourceConnection->getTableName('inventory_reservation'), ['sku', 'SUM(quantity)'])
            ->where('metadata LIKE ?', '%"event_type":"order_placed"%')
            ->where('metadata LIKE ?', '%"object_increment_id":"' . $incrementId . '"%')
            ->group('sku');

        $result = [];
        foreach ($connection->fetchPairs($select) as $sku => $quantity) {
            $result[(string)$sku] = (float)$quantity;
        }

        return $result;
    }
}
