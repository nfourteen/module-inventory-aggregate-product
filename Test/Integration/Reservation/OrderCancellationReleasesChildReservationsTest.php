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
use Magento\InventoryApi\Test\Fixture\SourceItem as SourceItemFixture;
use Magento\InventoryReservationsApi\Model\AppendReservationsInterface;
use Magento\InventoryReservationsApi\Model\CleanupReservationsInterface;
use Magento\InventoryReservationsApi\Model\GetReservationsQuantityInterface;
use Magento\InventoryReservationsApi\Model\ReservationBuilderInterface;
use Magento\InventorySalesApi\Api\Data\SalesChannelInterface;
use Magento\InventorySalesApi\Api\StockResolverInterface;
use Magento\Quote\Test\Fixture\AddProductToCart as AddProductToCartFixture;
use Magento\Quote\Test\Fixture\GuestCart as GuestCartFixture;
use Magento\Sales\Api\OrderManagementInterface;
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
 * Cancellation must reverse the child-SKU reservations made at placement:
 * GetItemsToCancelFromOrderItemPlugin swaps the parent SKU for the child SKUs at the scaled
 * qty. If that compensation is wrong or unwired, child reservations stay locked forever.
 */
class OrderCancellationReleasesChildReservationsTest extends TestCase
{
    private ?GetReservationsQuantityInterface $getReservationsQuantity = null;
    private ?StockResolverInterface $stockResolver = null;
    private ?OrderManagementInterface $orderManagement = null;
    private ?DataFixtureStorage $fixtures = null;

    protected function setUp(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $this->getReservationsQuantity = $objectManager->get(GetReservationsQuantityInterface::class);
        $this->stockResolver = $objectManager->get(StockResolverInterface::class);
        $this->orderManagement = $objectManager->get(OrderManagementInterface::class);
        $this->fixtures = DataFixtureStorageManager::getStorage();
    }

    /**
     * DbIsolation is off, and order-fixture rollback does not compensate the reservation ledger —
     * leftovers would accumulate and fail the exact-sum assertions on a rerun against the same DB.
     */
    protected function tearDown(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $reservationBuilder = $objectManager->get(ReservationBuilderInterface::class);
        $appendReservations = $objectManager->get(AppendReservationsInterface::class);

        $stockId = (int) $this->stockResolver
            ->execute(SalesChannelInterface::TYPE_WEBSITE, 'base')
            ->getStockId();

        $compensations = [];
        foreach (['agg-cancel-res-child-a', 'agg-cancel-res-child-b', 'agg-cancel-res-parent'] as $sku) {
            $reservedQty = (float) $this->getReservationsQuantity->execute($sku, $stockId);
            if ($reservedQty !== 0.0) {
                $compensations[] = $reservationBuilder
                    ->setSku($sku)
                    ->setQuantity(-$reservedQty)
                    ->setStockId($stockId)
                    ->setMetadata('test cleanup: OrderCancellationReleasesChildReservationsTest')
                    ->build();
            }
        }
        if ($compensations !== []) {
            $appendReservations->execute($compensations);
        }
        $objectManager->get(CleanupReservationsInterface::class)->execute();

        parent::tearDown();
    }

    #[
        DbIsolation(false),
        AppIsolation(true),
        Config('carriers/flatrate/active', 1, 'store', 'default'),
        Config('payment/checkmo/active', 1, 'store', 'default'),
        DataFixture(ProductFixture::class, ['sku' => 'agg-cancel-res-child-a', 'price' => 5.0], as: 'child_a'),
        DataFixture(ProductFixture::class, ['sku' => 'agg-cancel-res-child-b', 'price' => 3.0], as: 'child_b'),
        DataFixture(SourceItemFixture::class, ['sku' => '$child_a.sku$', 'source_code' => 'default', 'quantity' => 100, 'status' => 1]),
        DataFixture(SourceItemFixture::class, ['sku' => '$child_b.sku$', 'source_code' => 'default', 'quantity' => 100, 'status' => 1]),
        DataFixture(AggregateProductFixture::class, [
            'sku' => 'agg-cancel-res-parent',
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
    public function testCancellationReleasesChildReservationsAndKeepsParentAtZero(): void
    {
        $stockId = (int) $this->stockResolver
            ->execute(SalesChannelInterface::TYPE_WEBSITE, 'base')
            ->getStockId();

        // Precondition mirrors OrderReservationParentFilterTest so a failure here
        // pinpoints placement, not cancellation.
        $this->assertSame(
            -4.0,
            (float) $this->getReservationsQuantity->execute('agg-cancel-res-child-a', $stockId),
            'precondition: child_a must be reserved at parentQty (2) × linkQty (2) before cancellation'
        );
        $this->assertSame(
            -6.0,
            (float) $this->getReservationsQuantity->execute('agg-cancel-res-child-b', $stockId),
            'precondition: child_b must be reserved at parentQty (2) × linkQty (3) before cancellation'
        );

        $cancelled = $this->orderManagement->cancel((int) $this->fixtures->get('order')->getId());
        $this->assertTrue($cancelled, 'precondition: order cancellation must succeed');

        $this->assertSame(
            0.0,
            (float) $this->getReservationsQuantity->execute('agg-cancel-res-child-a', $stockId),
            'cancellation must fully compensate child_a reservation at the scaled qty'
        );
        $this->assertSame(
            0.0,
            (float) $this->getReservationsQuantity->execute('agg-cancel-res-child-b', $stockId),
            'cancellation must fully compensate child_b reservation at the scaled qty'
        );
        $this->assertSame(
            0.0,
            (float) $this->getReservationsQuantity->execute('agg-cancel-res-parent', $stockId),
            'parent SKU must stay out of the reservation ledger through cancellation'
        );
    }
}
