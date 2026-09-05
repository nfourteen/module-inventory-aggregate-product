<?php
declare(strict_types=1);
/**
 * Copyright © Nfourteen. All Rights Reserved.
 * See COPYING.txt for license details.
 **/

namespace Nfourteen\InventoryAggregateProduct\Test\Integration\Plugin\InventorySales;

use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\Checkout\Test\Fixture\PlaceOrder as PlaceOrderFixture;
use Magento\Checkout\Test\Fixture\SetBillingAddress as SetBillingAddressFixture;
use Magento\Checkout\Test\Fixture\SetDeliveryMethod as SetDeliveryMethodFixture;
use Magento\Checkout\Test\Fixture\SetGuestEmail as SetGuestEmailFixture;
use Magento\Checkout\Test\Fixture\SetPaymentMethod as SetPaymentMethodFixture;
use Magento\Checkout\Test\Fixture\SetShippingAddress as SetShippingAddressFixture;
use Magento\InventoryApi\Test\Fixture\SourceItem as SourceItemFixture;
use Magento\InventorySales\Model\GetItemsToCancelFromOrderItem;
use Magento\InventorySalesApi\Api\Data\ItemToSellInterface;
use Magento\Quote\Test\Fixture\AddProductToCart as AddProductToCartFixture;
use Magento\Quote\Test\Fixture\GuestCart as GuestCartFixture;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\ShipmentItemCreationInterface;
use Magento\Sales\Api\Data\ShipmentItemCreationInterfaceFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\ShipOrderInterface;
use Magento\Sales\Model\Order\Item as OrderItem;
use Magento\TestFramework\Fixture\AppIsolation;
use Magento\TestFramework\Fixture\Config;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorage;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use Nfourteen\AggregateProduct\Model\Product\Type\Aggregate;
use Nfourteen\AggregateProduct\Test\Fixture\AggregateProduct as AggregateProductFixture;
use PHPUnit\Framework\TestCase;

class GetItemsToCancelFromOrderItemPluginTest extends TestCase
{
    private ?object $objectManager = null;
    private ?GetItemsToCancelFromOrderItem $getItemsToCancelFromOrderItem = null;
    private ?ShipOrderInterface $shipOrder = null;
    private ?ShipmentItemCreationInterfaceFactory $shipmentItemCreationFactory = null;
    private ?OrderRepositoryInterface $orderRepository = null;
    private ?DataFixtureStorage $fixtures = null;

    protected function setUp(): void
    {
        $this->objectManager = Bootstrap::getObjectManager();
        $this->getItemsToCancelFromOrderItem = $this->objectManager->get(GetItemsToCancelFromOrderItem::class);
        $this->shipOrder = $this->objectManager->get(ShipOrderInterface::class);
        $this->shipmentItemCreationFactory = $this->objectManager->get(ShipmentItemCreationInterfaceFactory::class);
        $this->orderRepository = $this->objectManager->get(OrderRepositoryInterface::class);
        $this->fixtures = $this->objectManager->get(DataFixtureStorageManager::class)->getStorage();
    }

    #[
        DbIsolation(false),
        AppIsolation(true),
        Config('carriers/flatrate/active', 1, 'store', 'default'),
        Config('payment/checkmo/active', 1, 'store', 'default'),
        DataFixture(ProductFixture::class, ['sku' => 'agg-cancel-child-1', 'price' => 10.0], as: 'child1'),
        DataFixture(ProductFixture::class, ['sku' => 'agg-cancel-child-2', 'price' => 7.5], as: 'child2'),
        DataFixture(AggregateProductFixture::class, ['sku' => 'agg-cancel-parent', 'price' => 50.0, '_children' => [
            ['product_id' => '$child1.id$', 'qty' => 5],
            ['product_id' => '$child2.id$', 'qty' => 3],
        ]], as: 'aggregate'),
        DataFixture(SourceItemFixture::class, ['sku' => '$child1.sku$', 'source_code' => 'default', 'quantity' => 100, 'status' => 1]),
        DataFixture(SourceItemFixture::class, ['sku' => '$child2.sku$', 'source_code' => 'default', 'quantity' => 100, 'status' => 1]),
        DataFixture(GuestCartFixture::class, as: 'cart'),
        DataFixture(AddProductToCartFixture::class, ['cart_id' => '$cart.id$', 'product_id' => '$aggregate.id$', 'qty' => 1]),
        DataFixture(SetBillingAddressFixture::class, ['cart_id' => '$cart.id$']),
        DataFixture(SetShippingAddressFixture::class, ['cart_id' => '$cart.id$']),
        DataFixture(SetGuestEmailFixture::class, ['cart_id' => '$cart.id$']),
        DataFixture(SetDeliveryMethodFixture::class, ['cart_id' => '$cart.id$', 'carrier_code' => 'flatrate', 'method_code' => 'flatrate']),
        DataFixture(SetPaymentMethodFixture::class, ['cart_id' => '$cart.id$', 'method' => 'checkmo']),
        DataFixture(PlaceOrderFixture::class, ['cart_id' => '$cart.id$'], as: 'order'),
    ]
    public function testAggregateCancellationUsesChildSkus(): void
    {
        $order = $this->fixtures->get('order');
        $parentItem = $this->getAggregateParentItem($order);
        $this->assertNotNull($parentItem, 'Aggregate parent item should exist in order');

        $resultBySku = $this->indexItemsBySku(
            $this->getItemsToCancelFromOrderItem->execute($parentItem)
        );

        $this->assertArrayNotHasKey('agg-cancel-parent', $resultBySku, 'Parent SKU must not appear in cancellation items');
        $this->assertSame(5.0, $resultBySku['agg-cancel-child-1'] ?? null, 'Child SKU 1 cancel qty should be 5');
        $this->assertSame(3.0, $resultBySku['agg-cancel-child-2'] ?? null, 'Child SKU 2 cancel qty should be 3');
    }

    #[
        DbIsolation(false),
        AppIsolation(true),
        Config('carriers/flatrate/active', 1, 'store', 'default'),
        Config('payment/checkmo/active', 1, 'store', 'default'),
        DataFixture(ProductFixture::class, ['sku' => 'agg-cancel-partial-child', 'price' => 10.0], as: 'child'),
        DataFixture(AggregateProductFixture::class, ['sku' => 'agg-cancel-partial-parent', 'price' => 30.0, '_children' => [
            ['product_id' => '$child.id$', 'qty' => 2],
        ]], as: 'aggregate'),
        DataFixture(SourceItemFixture::class, ['sku' => '$child.sku$', 'source_code' => 'default', 'quantity' => 100, 'status' => 1]),
        DataFixture(GuestCartFixture::class, as: 'cart'),
        DataFixture(AddProductToCartFixture::class, ['cart_id' => '$cart.id$', 'product_id' => '$aggregate.id$', 'qty' => 3]),
        DataFixture(SetBillingAddressFixture::class, ['cart_id' => '$cart.id$']),
        DataFixture(SetShippingAddressFixture::class, ['cart_id' => '$cart.id$']),
        DataFixture(SetGuestEmailFixture::class, ['cart_id' => '$cart.id$']),
        DataFixture(SetDeliveryMethodFixture::class, ['cart_id' => '$cart.id$', 'carrier_code' => 'flatrate', 'method_code' => 'flatrate']),
        DataFixture(SetPaymentMethodFixture::class, ['cart_id' => '$cart.id$', 'method' => 'checkmo']),
        DataFixture(PlaceOrderFixture::class, ['cart_id' => '$cart.id$'], as: 'order'),
    ]
    public function testPartialCancellationAfterPartialShipment(): void
    {
        $order = $this->fixtures->get('order');
        $orderId = (int) $order->getEntityId();
        $parentItem = $this->getAggregateParentItem($order);
        $this->assertNotNull($parentItem, 'Aggregate parent item should exist in order');

        // Ship 1 parent -> parentQtyToCancel = 3 - max(1, 0) - 0 = 2
        $this->shipOrder->execute(
            $orderId,
            [$this->createShipmentItem((int) $parentItem->getItemId(), 1.0)]
        );

        // Reload order to get updated qty_shipped
        $order = $this->orderRepository->get($orderId);
        $parentItem = $this->getAggregateParentItem($order);

        $resultBySku = $this->indexItemsBySku(
            $this->getItemsToCancelFromOrderItem->execute($parentItem)
        );

        // Cancel qty = qtyPerParent(2) * parentQtyToCancel(2) = 4
        $this->assertArrayNotHasKey('agg-cancel-partial-parent', $resultBySku, 'Parent SKU must not appear');
        $this->assertSame(4.0, $resultBySku['agg-cancel-partial-child'] ?? null, 'Child cancel qty should be 4 (2 per-parent * 2 unshipped)');
    }

    #[
        DbIsolation(false),
        AppIsolation(true),
        Config('carriers/flatrate/active', 1, 'store', 'default'),
        Config('payment/checkmo/active', 1, 'store', 'default'),
        DataFixture(ProductFixture::class, ['sku' => 'agg-cancel-physical-child', 'price' => 8.0], as: 'physical'),
        DataFixture(ProductFixture::class, ['sku' => 'agg-cancel-virtual-child', 'type_id' => 'virtual', 'weight' => null, 'price' => 6.0], as: 'virtual'),
        DataFixture(AggregateProductFixture::class, ['sku' => 'agg-cancel-mixed-parent', 'price' => 40.0, '_children' => [
            ['product_id' => '$physical.id$', 'qty' => 2],
            ['product_id' => '$virtual.id$', 'qty' => 3],
        ]], as: 'aggregate'),
        DataFixture(SourceItemFixture::class, ['sku' => '$physical.sku$', 'source_code' => 'default', 'quantity' => 100, 'status' => 1]),
        DataFixture(SourceItemFixture::class, ['sku' => '$virtual.sku$', 'source_code' => 'default', 'quantity' => 100, 'status' => 1]),
        DataFixture(GuestCartFixture::class, as: 'cart'),
        DataFixture(AddProductToCartFixture::class, ['cart_id' => '$cart.id$', 'product_id' => '$aggregate.id$', 'qty' => 1]),
        DataFixture(SetBillingAddressFixture::class, ['cart_id' => '$cart.id$']),
        DataFixture(SetShippingAddressFixture::class, ['cart_id' => '$cart.id$']),
        DataFixture(SetGuestEmailFixture::class, ['cart_id' => '$cart.id$']),
        DataFixture(SetDeliveryMethodFixture::class, ['cart_id' => '$cart.id$', 'carrier_code' => 'flatrate', 'method_code' => 'flatrate']),
        DataFixture(SetPaymentMethodFixture::class, ['cart_id' => '$cart.id$', 'method' => 'checkmo']),
        DataFixture(PlaceOrderFixture::class, ['cart_id' => '$cart.id$'], as: 'order'),
    ]
    public function testMixedVirtualAndPhysicalChildrenAllCompensated(): void
    {
        $order = $this->fixtures->get('order');
        $parentItem = $this->getAggregateParentItem($order);
        $this->assertNotNull($parentItem, 'Aggregate parent item should exist in order');

        $resultBySku = $this->indexItemsBySku(
            $this->getItemsToCancelFromOrderItem->execute($parentItem)
        );

        $this->assertArrayNotHasKey('agg-cancel-mixed-parent', $resultBySku, 'Parent SKU must not appear');
        $this->assertSame(2.0, $resultBySku['agg-cancel-physical-child'] ?? null, 'Physical child cancel qty should be 2');
        $this->assertSame(3.0, $resultBySku['agg-cancel-virtual-child'] ?? null, 'Virtual child cancel qty should be 3');
    }

    #[
        DbIsolation(false),
        AppIsolation(true),
        Config('carriers/flatrate/active', 1, 'store', 'default'),
        Config('payment/checkmo/active', 1, 'store', 'default'),
        DataFixture(ProductFixture::class, ['sku' => 'simple-cancel', 'price' => 12.0], as: 'simple'),
        DataFixture(SourceItemFixture::class, ['sku' => '$simple.sku$', 'source_code' => 'default', 'quantity' => 100, 'status' => 1]),
        DataFixture(GuestCartFixture::class, as: 'cart'),
        DataFixture(AddProductToCartFixture::class, ['cart_id' => '$cart.id$', 'product_id' => '$simple.id$', 'qty' => 3]),
        DataFixture(SetBillingAddressFixture::class, ['cart_id' => '$cart.id$']),
        DataFixture(SetShippingAddressFixture::class, ['cart_id' => '$cart.id$']),
        DataFixture(SetGuestEmailFixture::class, ['cart_id' => '$cart.id$']),
        DataFixture(SetDeliveryMethodFixture::class, ['cart_id' => '$cart.id$', 'carrier_code' => 'flatrate', 'method_code' => 'flatrate']),
        DataFixture(SetPaymentMethodFixture::class, ['cart_id' => '$cart.id$', 'method' => 'checkmo']),
        DataFixture(PlaceOrderFixture::class, ['cart_id' => '$cart.id$'], as: 'order'),
    ]
    public function testNonAggregateItemPassesThrough(): void
    {
        $order = $this->fixtures->get('order');
        $orderItem = $this->getOrderItemBySku($order, 'simple-cancel');
        $this->assertNotNull($orderItem, 'Simple order item should exist');

        $resultBySku = $this->indexItemsBySku(
            $this->getItemsToCancelFromOrderItem->execute($orderItem)
        );

        $this->assertCount(1, $resultBySku);
        $this->assertSame(3.0, $resultBySku['simple-cancel'] ?? null, 'Simple item cancellation should pass through unchanged');
    }

    private function createShipmentItem(int $orderItemId, float $qty): ShipmentItemCreationInterface
    {
        $shipmentItem = $this->shipmentItemCreationFactory->create();
        $shipmentItem->setOrderItemId($orderItemId);
        $shipmentItem->setQty($qty);
        return $shipmentItem;
    }

    private function getAggregateParentItem(OrderInterface $order): ?OrderItem
    {
        foreach ($order->getAllItems() as $item) {
            if ($item->getProductType() === Aggregate::TYPE_CODE && !$item->getParentItemId()) {
                return $item;
            }
        }
        return null;
    }

    private function getOrderItemBySku(OrderInterface $order, string $sku): ?OrderItem
    {
        foreach ($order->getAllItems() as $item) {
            if ((string) $item->getSku() === $sku && !$item->getParentItemId()) {
                return $item;
            }
        }
        return null;
    }

    /**
     * @param ItemToSellInterface[] $items
     * @return array<string, float>
     */
    private function indexItemsBySku(array $items): array
    {
        $indexed = [];
        foreach ($items as $item) {
            $sku = $item->getSku();
            if (!isset($indexed[$sku])) {
                $indexed[$sku] = 0.0;
            }
            $indexed[$sku] += $item->getQuantity();
        }
        return $indexed;
    }
}
