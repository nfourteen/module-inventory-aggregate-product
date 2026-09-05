<?php
declare(strict_types=1);
/**
 * Copyright © Nfourteen. All Rights Reserved.
 * See COPYING.txt for license details.
 **/

namespace Nfourteen\InventoryAggregateProduct\Test\Integration\Plugin\InventoryShipping;

use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\Checkout\Test\Fixture\SetBillingAddress as SetBillingAddressFixture;
use Magento\Checkout\Test\Fixture\SetDeliveryMethod as SetDeliveryMethodFixture;
use Magento\Checkout\Test\Fixture\SetGuestEmail as SetGuestEmailFixture;
use Magento\Checkout\Test\Fixture\SetPaymentMethod as SetPaymentMethodFixture;
use Magento\Checkout\Test\Fixture\SetShippingAddress as SetShippingAddressFixture;
use Magento\Checkout\Test\Fixture\PlaceOrder as PlaceOrderFixture;
use Magento\InventoryApi\Api\Data\SourceItemInterface;
use Magento\InventoryApi\Api\GetSourceItemsBySkuInterface;
use Magento\InventoryApi\Test\Fixture\SourceItem as SourceItemFixture;
use Magento\InventoryCatalogApi\Api\DefaultSourceProviderInterface;
use Magento\Quote\Test\Fixture\AddProductToCart as AddProductToCartFixture;
use Magento\Quote\Test\Fixture\GuestCart as GuestCartFixture;
use Magento\Sales\Api\Data\InvoiceItemCreationInterface;
use Magento\Sales\Api\Data\InvoiceItemCreationInterfaceFactory;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\ShipmentItemCreationInterface;
use Magento\Sales\Api\Data\ShipmentItemCreationInterfaceFactory;
use Magento\Sales\Api\InvoiceOrderInterface;
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

/**
 * End-to-end proof that shipping and invoicing an aggregate order deducts child source items.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class AggregateSourceDeductionEndToEndTest extends TestCase
{
    private ?object $objectManager = null;
    private ?ShipOrderInterface $shipOrder = null;
    private ?ShipmentItemCreationInterfaceFactory $shipmentItemCreationFactory = null;
    private ?InvoiceOrderInterface $invoiceOrder = null;
    private ?InvoiceItemCreationInterfaceFactory $invoiceItemCreationFactory = null;
    private ?GetSourceItemsBySkuInterface $getSourceItemBySku = null;
    private ?OrderRepositoryInterface $orderRepository = null;
    private ?DataFixtureStorage $fixtures = null;

    protected function setUp(): void
    {
        $this->objectManager = Bootstrap::getObjectManager();
        $this->shipOrder = $this->objectManager->get(ShipOrderInterface::class);
        $this->shipmentItemCreationFactory = $this->objectManager->get(ShipmentItemCreationInterfaceFactory::class);
        $this->invoiceOrder = $this->objectManager->get(InvoiceOrderInterface::class);
        $this->invoiceItemCreationFactory = $this->objectManager->get(InvoiceItemCreationInterfaceFactory::class);
        $this->getSourceItemBySku = $this->objectManager->get(GetSourceItemsBySkuInterface::class);
        $this->orderRepository = $this->objectManager->get(OrderRepositoryInterface::class);
        $this->fixtures = $this->objectManager->get(DataFixtureStorageManager::class)->getStorage();
    }

    #[
        DbIsolation(false),
        AppIsolation(true),
        Config('carriers/flatrate/active', 1, 'store', 'default'),
        Config('payment/checkmo/active', 1, 'store', 'default'),
        DataFixture(ProductFixture::class, ['sku' => 'e2e-agg-ship-child1', 'price' => 5.0], as: 'child1'),
        DataFixture(ProductFixture::class, ['sku' => 'e2e-agg-ship-child2', 'price' => 3.0], as: 'child2'),
        DataFixture(AggregateProductFixture::class, ['sku' => 'e2e-agg-ship-parent', 'price' => 10.0, '_children' => [
            ['product_id' => '$child1.id$', 'qty' => 2],
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
    public function testEndToEndShipmentDeduction(): void
    {
        $order = $this->fixtures->get('order');
        $parentItem = $this->getAggregateParentItem($order);
        $this->assertNotNull($parentItem, 'Aggregate parent item should exist in order');

        $shipmentItems = $this->createShippableItemsFromOrder($order);
        $this->assertNotEmpty($shipmentItems, 'Order should expose at least one shippable item');
        $this->shipOrder->execute((int) $order->getEntityId(), $shipmentItems);

        $sourceItem1 = $this->getDefaultSourceItem('e2e-agg-ship-child1');
        $this->assertNotNull($sourceItem1, 'Source item for child 1 should exist');
        $this->assertEquals(98.0, $sourceItem1->getQuantity(), 'Child 1 stock should be reduced by 2 (from 100)');

        $sourceItem2 = $this->getDefaultSourceItem('e2e-agg-ship-child2');
        $this->assertNotNull($sourceItem2, 'Source item for child 2 should exist');
        $this->assertEquals(97.0, $sourceItem2->getQuantity(), 'Child 2 stock should be reduced by 3 (from 100)');
    }

    #[
        DbIsolation(false),
        AppIsolation(true),
        Config('carriers/flatrate/active', 1, 'store', 'default'),
        Config('payment/checkmo/active', 1, 'store', 'default'),
        DataFixture(ProductFixture::class, ['sku' => 'e2e-agg-inv-vchild', 'type_id' => 'virtual', 'weight' => null, 'price' => 5.0], as: 'vchild'),
        DataFixture(AggregateProductFixture::class, ['sku' => 'e2e-agg-inv-parent', 'price' => 10.0, '_children' => [
            ['product_id' => '$vchild.id$', 'qty' => 4],
        ]], as: 'aggregate'),
        DataFixture(SourceItemFixture::class, ['sku' => '$vchild.sku$', 'source_code' => 'default', 'quantity' => 100, 'status' => 1]),
        DataFixture(GuestCartFixture::class, as: 'cart'),
        DataFixture(AddProductToCartFixture::class, ['cart_id' => '$cart.id$', 'product_id' => '$aggregate.id$', 'qty' => 1]),
        DataFixture(SetBillingAddressFixture::class, ['cart_id' => '$cart.id$']),
        DataFixture(SetShippingAddressFixture::class, ['cart_id' => '$cart.id$']),
        DataFixture(SetGuestEmailFixture::class, ['cart_id' => '$cart.id$']),
        DataFixture(SetDeliveryMethodFixture::class, ['cart_id' => '$cart.id$', 'carrier_code' => 'flatrate', 'method_code' => 'flatrate']),
        DataFixture(SetPaymentMethodFixture::class, ['cart_id' => '$cart.id$', 'method' => 'checkmo']),
        DataFixture(PlaceOrderFixture::class, ['cart_id' => '$cart.id$'], as: 'order'),
    ]
    public function testEndToEndInvoiceDeductionForVirtualChildren(): void
    {
        $order = $this->fixtures->get('order');
        $parentItem = $this->getAggregateParentItem($order);
        $this->assertNotNull($parentItem, 'Aggregate parent item should exist in order');

        $invoiceItem = $this->invoiceItemCreationFactory->create();
        $invoiceItem->setOrderItemId((int) $parentItem->getItemId());
        $invoiceItem->setQty(1);
        $this->invoiceOrder->execute((int) $order->getEntityId(), false, [$invoiceItem]);

        $sourceItem = $this->getDefaultSourceItem('e2e-agg-inv-vchild');
        $this->assertNotNull($sourceItem, 'Source item for virtual child should exist');
        $this->assertEquals(96.0, $sourceItem->getQuantity(), 'Virtual child stock should be reduced by 4 (from 100)');
    }

    #[
        DbIsolation(false),
        AppIsolation(true),
        Config('carriers/flatrate/active', 1, 'store', 'default'),
        Config('payment/checkmo/active', 1, 'store', 'default'),
        DataFixture(ProductFixture::class, ['sku' => 'e2e-agg-mixed-physical', 'price' => 5.0], as: 'physical'),
        DataFixture(ProductFixture::class, ['sku' => 'e2e-agg-mixed-virtual', 'type_id' => 'virtual', 'weight' => null, 'price' => 3.0], as: 'virtual'),
        DataFixture(AggregateProductFixture::class, ['sku' => 'e2e-agg-mixed-parent', 'price' => 10.0, '_children' => [
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
    public function testEndToEndMixedPhysicalVirtualDeduction(): void
    {
        $order = $this->fixtures->get('order');
        $parentItem = $this->getAggregateParentItem($order);
        $this->assertNotNull($parentItem, 'Aggregate parent item should exist');

        $shipmentItems = $this->createShippableItemsFromOrder($order);
        $this->assertNotEmpty($shipmentItems, 'Order should expose at least one shippable item');
        $this->shipOrder->execute((int) $order->getEntityId(), $shipmentItems);

        $physicalSourceItem = $this->getDefaultSourceItem('e2e-agg-mixed-physical');
        $this->assertEquals(98.0, $physicalSourceItem->getQuantity(), 'Physical child stock should be reduced on shipment');

        $virtualSourceItem = $this->getDefaultSourceItem('e2e-agg-mixed-virtual');
        $this->assertEquals(
            100.0,
            $virtualSourceItem->getQuantity(),
            'Virtual child stock should NOT change on shipment (shipment skips virtual type deduction)'
        );

        // Reload order after shipment to reflect updated state
        $order = $this->orderRepository->get((int) $order->getEntityId());
        $parentItem = $this->getAggregateParentItem($order);

        $invoiceItem = $this->invoiceItemCreationFactory->create();
        $invoiceItem->setOrderItemId((int) $parentItem->getItemId());
        $invoiceItem->setQty(1);
        $this->invoiceOrder->execute((int) $order->getEntityId(), false, [$invoiceItem]);

        $virtualSourceItem = $this->getDefaultSourceItem('e2e-agg-mixed-virtual');
        $this->assertEquals(97.0, $virtualSourceItem->getQuantity(), 'Virtual child stock should be reduced on invoice');

        $physicalSourceItem = $this->getDefaultSourceItem('e2e-agg-mixed-physical');
        $this->assertEquals(98.0, $physicalSourceItem->getQuantity(), 'Physical child stock should remain the same on invoice after shipment deduction');
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

    /**
     * @return ShipmentItemCreationInterface[]
     */
    private function createShippableItemsFromOrder(OrderInterface $order): array
    {
        $shipmentItems = [];
        foreach ($order->getAllItems() as $item) {
            if ((float) $item->getQtyToShip() <= 0.0 || $item->getIsVirtual() || $item->getLockedDoShip()) {
                continue;
            }

            $shipmentItem = $this->shipmentItemCreationFactory->create();
            $shipmentItem->setOrderItemId((int) $item->getItemId());
            $shipmentItem->setQty((float) $item->getQtyToShip());
            $shipmentItems[] = $shipmentItem;
        }

        return $shipmentItems;
    }

    private function getDefaultSourceItem(string $sku): ?SourceItemInterface
    {
        $defaultSourceCode = $this->objectManager->get(DefaultSourceProviderInterface::class)->getCode();

        foreach ($this->getSourceItemBySku->execute($sku) as $sourceItem) {
            if ($sourceItem->getSourceCode() === $defaultSourceCode) {
                return $sourceItem;
            }
        }

        return null;
    }
}
