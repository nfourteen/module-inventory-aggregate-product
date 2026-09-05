<?php
declare(strict_types=1);
/**
 * Copyright © Nfourteen. All Rights Reserved.
 * See COPYING.txt for license details.
 **/

namespace Nfourteen\InventoryAggregateProduct\Test\Integration\Service;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\Checkout\Test\Fixture\PlaceOrder as PlaceOrderFixture;
use Magento\Checkout\Test\Fixture\SetBillingAddress as SetBillingAddressFixture;
use Magento\Checkout\Test\Fixture\SetDeliveryMethod as SetDeliveryMethodFixture;
use Magento\Checkout\Test\Fixture\SetGuestEmail as SetGuestEmailFixture;
use Magento\Checkout\Test\Fixture\SetPaymentMethod as SetPaymentMethodFixture;
use Magento\Checkout\Test\Fixture\SetShippingAddress as SetShippingAddressFixture;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\InventoryApi\Test\Fixture\SourceItem as SourceItemFixture;
use Magento\Quote\Api\CartRepositoryInterface;
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
 * Tests aggregate product quantity validation through add-to-cart and order runtime flows.
 *
 * Cart validation catches insufficient child stock at the child level because
 * child qty in cart = parent qty × configuration, and each child is validated individually.
 */
class AggregateSalableQtyTest extends TestCase
{
    private ?ProductRepositoryInterface $productRepository = null;
    private ?CartRepositoryInterface $cartRepository = null;
    private ?DataFixtureStorage $fixtures = null;

    protected function setUp(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $this->productRepository = $objectManager->get(ProductRepositoryInterface::class);
        $this->cartRepository = $objectManager->get(CartRepositoryInterface::class);
        $this->fixtures = DataFixtureStorageManager::getStorage();
    }

    /**
     * Child has 8 units, configuration=10 -> child needs 10 for 1 aggregate unit.
     * Cart correctly rejects because child qty (10) exceeds child stock (8).
     */
    #[
        DataFixture(ProductFixture::class, ['sku' => 'salqty-insuf-child', 'price' => 5.0], as: 'child'),
        DataFixture(SourceItemFixture::class, [
            'sku' => '$child.sku$',
            'source_code' => 'default',
            'quantity' => 8,
            'status' => 1,
        ]),
        DataFixture(AggregateProductFixture::class, [
            'sku' => 'salqty-insuf-parent',
            'price' => 10.0,
            '_children' => [
                ['product_id' => '$child.id$', 'qty' => 10],
            ],
        ], as: 'aggregate'),
        DataFixture(GuestCartFixture::class, as: 'cart'),
    ]
    public function testCartRejectsWhenChildStockInsufficientForQtyConfiguration(): void
    {
        $this->assertAddToCartRejectedForStockCause(1);
    }

    /**
     * Two children: child_a (configuration=2, stock=100) and child_b (configuration=5, stock=3).
     * Child_b needs 5 units for 1 aggregate but only has 3.
     * Cart correctly rejects at the child.
     */
    #[
        DataFixture(ProductFixture::class, ['sku' => 'salqty-bn-child-a', 'price' => 5.0], as: 'child_a'),
        DataFixture(ProductFixture::class, ['sku' => 'salqty-bn-child-b', 'price' => 3.0], as: 'child_b'),
        DataFixture(SourceItemFixture::class, [
            'sku' => '$child_a.sku$',
            'source_code' => 'default',
            'quantity' => 100,
            'status' => 1,
        ]),
        DataFixture(SourceItemFixture::class, [
            'sku' => '$child_b.sku$',
            'source_code' => 'default',
            'quantity' => 3,
            'status' => 1,
        ]),
        DataFixture(AggregateProductFixture::class, [
            'sku' => 'salqty-bn-parent',
            'price' => 10.0,
            '_children' => [
                ['product_id' => '$child_a.id$', 'qty' => 2],
                ['product_id' => '$child_b.id$', 'qty' => 5],
            ],
        ], as: 'aggregate'),
        DataFixture(GuestCartFixture::class, as: 'cart'),
    ]
    public function testCartRejectsWhenChildLacksStock(): void
    {
        $this->assertAddToCartRejectedForStockCause(1);
    }

    /**
     * Child has stock=8, configuration=2 -> supports floor(8/2)=4 aggregate units.
     * Requesting 5 aggregate units needs 10 child units. Cart correctly rejects.
     */
    #[
        DataFixture(ProductFixture::class, ['sku' => 'salqty-excess-child', 'price' => 5.0], as: 'child'),
        DataFixture(SourceItemFixture::class, [
            'sku' => '$child.sku$',
            'source_code' => 'default',
            'quantity' => 8,
            'status' => 1,
        ]),
        DataFixture(AggregateProductFixture::class, [
            'sku' => 'salqty-excess-parent',
            'price' => 10.0,
            '_children' => [
                ['product_id' => '$child.id$', 'qty' => 2],
            ],
        ], as: 'aggregate'),
        DataFixture(GuestCartFixture::class, as: 'cart'),
    ]
    public function testCartRejectsWhenAggregateQtyExceedsChildCapacity(): void
    {
        $this->assertAddToCartRejectedForStockCause(5);
    }

    /**
     * When a child is short at add-to-cart, the failure must surface AT add-to-cart with a
     * comprehensible stock message (CatalogInventory QuantityValidator on the child row) — not a
     * silent parent add that fails later at checkout. This asserts both: the add throws, and the
     * message names a stock/quantity problem rather than being empty or opaque.
     */
    #[
        DataFixture(ProductFixture::class, ['sku' => 'salqty-msg-child', 'price' => 5.0], as: 'child'),
        DataFixture(SourceItemFixture::class, [
            'sku' => '$child.sku$',
            'source_code' => 'default',
            'quantity' => 8,
            'status' => 1,
        ]),
        DataFixture(AggregateProductFixture::class, [
            'sku' => 'salqty-msg-parent',
            'price' => 10.0,
            '_children' => [
                ['product_id' => '$child.id$', 'qty' => 10],
            ],
        ], as: 'aggregate'),
        DataFixture(GuestCartFixture::class, as: 'cart'),
    ]
    public function testAddToCartRejectionMessageIsComprehensible(): void
    {
        $this->assertAddToCartRejectedForStockCause(1);
    }

    #[
        DbIsolation(false),
        AppIsolation(true),
        Config('carriers/flatrate/active', 1, 'store', 'default'),
        Config('payment/checkmo/active', 1, 'store', 'default'),
        DataFixture(ProductFixture::class, ['sku' => 'salqty-ok-child', 'price' => 5.0], as: 'child'),
        DataFixture(SourceItemFixture::class, [
            'sku' => '$child.sku$',
            'source_code' => 'default',
            'quantity' => 100,
            'status' => 1,
        ]),
        DataFixture(AggregateProductFixture::class, [
            'sku' => 'salqty-ok-parent',
            'price' => 10.0,
            '_children' => [
                ['product_id' => '$child.id$', 'qty' => 10],
            ],
        ], as: 'aggregate'),
        DataFixture(GuestCartFixture::class, as: 'cart'),
        DataFixture(AddProductToCartFixture::class, ['cart_id' => '$cart.id$', 'product_id' => '$aggregate.id$', 'qty' => 1]),
        DataFixture(SetBillingAddressFixture::class, ['cart_id' => '$cart.id$']),
        DataFixture(SetShippingAddressFixture::class, ['cart_id' => '$cart.id$']),
        DataFixture(SetGuestEmailFixture::class, ['cart_id' => '$cart.id$']),
        DataFixture(SetDeliveryMethodFixture::class, ['cart_id' => '$cart.id$', 'carrier_code' => 'flatrate', 'method_code' => 'flatrate']),
        DataFixture(SetPaymentMethodFixture::class, ['cart_id' => '$cart.id$', 'method' => 'checkmo']),
        DataFixture(PlaceOrderFixture::class, ['cart_id' => '$cart.id$'], as: 'order'),
    ]
    public function testOrderSucceedsWhenChildStockSufficientForQtyConfiguration(): void
    {
        $order = $this->fixtures->get('order');
        $this->assertNotNull($order->getEntityId(), 'Order should be placed successfully');
    }

    /**
     * A broad expectException(LocalizedException) would also pass on unrelated failures
     * (NoSuchEntityException is a subclass; addProduct throws it for many non-stock causes),
     * so the rejection must be pinned to a stock message and an unchanged cart.
     */
    private function assertAddToCartRejectedForStockCause(int $requestedQty): void
    {
        $cart = $this->cartRepository->get($this->fixtures->get('cart')->getId());
        $product = $this->productRepository->getById(
            (int) $this->fixtures->get('aggregate')->getId()
        );

        try {
            $cart->addProduct($product, new DataObject(['qty' => $requestedQty]));
            $this->fail('Add-to-cart must reject when a child cannot satisfy the configured qty');
        } catch (LocalizedException $e) {
            $this->assertMatchesRegularExpression(
                '/stock|available|qty|quantity|sale/i',
                $e->getMessage(),
                'Rejection message must name the stock/quantity shortfall, not be opaque'
            );
        }

        $this->assertCount(
            0,
            $cart->getAllVisibleItems(),
            'no aggregate line should be added on a failed child validation'
        );
    }
}
