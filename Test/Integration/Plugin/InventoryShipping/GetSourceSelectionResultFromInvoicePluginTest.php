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
use Magento\InventoryApi\Test\Fixture\SourceItem as SourceItemFixture;
use Magento\InventoryShipping\Model\GetSourceSelectionResultFromInvoice;
use Magento\InventorySourceSelectionApi\Api\Data\SourceSelectionResultInterface;
use Magento\Quote\Test\Fixture\AddProductToCart as AddProductToCartFixture;
use Magento\Quote\Test\Fixture\GuestCart as GuestCartFixture;
use Magento\Sales\Api\Data\InvoiceInterface;
use Magento\Sales\Api\Data\InvoiceItemCreationInterface;
use Magento\Sales\Api\Data\InvoiceItemCreationInterfaceFactory;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\InvoiceOrderInterface;
use Magento\Sales\Api\InvoiceRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
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

class GetSourceSelectionResultFromInvoicePluginTest extends TestCase
{
    private ?object $objectManager = null;
    private ?GetSourceSelectionResultFromInvoice $getSourceSelectionResultFromInvoice = null;
    private ?InvoiceOrderInterface $invoiceOrder = null;
    private ?InvoiceRepositoryInterface $invoiceRepository = null;
    private ?InvoiceItemCreationInterfaceFactory $invoiceItemCreationFactory = null;
    private ?OrderRepositoryInterface $orderRepository = null;
    private ?DataFixtureStorage $fixtures = null;

    protected function setUp(): void
    {
        $this->objectManager = Bootstrap::getObjectManager();
        $this->getSourceSelectionResultFromInvoice = $this->objectManager->get(GetSourceSelectionResultFromInvoice::class);
        $this->invoiceOrder = $this->objectManager->get(InvoiceOrderInterface::class);
        $this->invoiceRepository = $this->objectManager->get(InvoiceRepositoryInterface::class);
        $this->invoiceItemCreationFactory = $this->objectManager->get(InvoiceItemCreationInterfaceFactory::class);
        $this->orderRepository = $this->objectManager->get(OrderRepositoryInterface::class);
        $this->fixtures = $this->objectManager->get(DataFixtureStorageManager::class)->getStorage();
    }

    #[
        DbIsolation(false),
        AppIsolation(true),
        Config('carriers/flatrate/active', 1, 'store', 'default'),
        Config('payment/checkmo/active', 1, 'store', 'default'),
        DataFixture(ProductFixture::class, ['sku' => 'agg-inv-vchild-1', 'type_id' => 'virtual', 'weight' => null, 'price' => 9.0], as: 'vchild1'),
        DataFixture(ProductFixture::class, ['sku' => 'agg-inv-vchild-2', 'type_id' => 'virtual', 'weight' => null, 'price' => 4.0], as: 'vchild2'),
        DataFixture(AggregateProductFixture::class, ['sku' => 'agg-inv-parent', 'price' => 60.0, '_children' => [
            ['product_id' => '$vchild1.id$', 'qty' => 5],
            ['product_id' => '$vchild2.id$', 'qty' => 3],
        ]], as: 'aggregate'),
        DataFixture(SourceItemFixture::class, ['sku' => '$vchild1.sku$', 'source_code' => 'default', 'quantity' => 100, 'status' => 1]),
        DataFixture(SourceItemFixture::class, ['sku' => '$vchild2.sku$', 'source_code' => 'default', 'quantity' => 100, 'status' => 1]),
        DataFixture(GuestCartFixture::class, as: 'cart'),
        DataFixture(AddProductToCartFixture::class, ['cart_id' => '$cart.id$', 'product_id' => '$aggregate.id$', 'qty' => 1]),
        DataFixture(SetBillingAddressFixture::class, ['cart_id' => '$cart.id$']),
        DataFixture(SetShippingAddressFixture::class, ['cart_id' => '$cart.id$']),
        DataFixture(SetGuestEmailFixture::class, ['cart_id' => '$cart.id$']),
        DataFixture(SetDeliveryMethodFixture::class, ['cart_id' => '$cart.id$', 'carrier_code' => 'flatrate', 'method_code' => 'flatrate']),
        DataFixture(SetPaymentMethodFixture::class, ['cart_id' => '$cart.id$', 'method' => 'checkmo']),
        DataFixture(PlaceOrderFixture::class, ['cart_id' => '$cart.id$'], as: 'order'),
    ]
    public function testAggregateInvoiceBuildsSelectionFromVirtualChildren(): void
    {
        $order = $this->fixtures->get('order');
        $parentItem = $this->getAggregateParentItem($order);
        $this->assertNotNull($parentItem, 'Aggregate parent item should exist in order');

        $invoiceId = $this->invoiceOrder->execute(
            (int) $order->getEntityId(),
            false,
            [$this->createInvoiceItem((int) $parentItem->getItemId(), 1.0)]
        );
        $invoice = $this->getInvoiceById((int) $invoiceId);
        $resultBySku = $this->indexSelectionItemsBySku($this->getSourceSelectionResultFromInvoice->execute($invoice));

        $this->assertArrayNotHasKey('agg-inv-parent', $resultBySku, 'Parent SKU must not be in selection result');
        $this->assertSame(5.0, $resultBySku['agg-inv-vchild-1'] ?? null, 'Virtual child 1 qty should be 5');
        $this->assertSame(3.0, $resultBySku['agg-inv-vchild-2'] ?? null, 'Virtual child 2 qty should be 3');
    }

    #[
        DbIsolation(false),
        AppIsolation(true),
        Config('carriers/flatrate/active', 1, 'store', 'default'),
        Config('payment/checkmo/active', 1, 'store', 'default'),
        DataFixture(ProductFixture::class, ['sku' => 'agg-inv-physical-child', 'price' => 8.0], as: 'physical'),
        DataFixture(ProductFixture::class, ['sku' => 'agg-inv-virtual-child', 'type_id' => 'virtual', 'weight' => null, 'price' => 7.0], as: 'virtual'),
        DataFixture(AggregateProductFixture::class, ['sku' => 'agg-inv-mixed-parent', 'price' => 45.0, '_children' => [
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
    public function testMixedAggregateInvoiceSelectsOnlyVirtualChildren(): void
    {
        $order = $this->fixtures->get('order');
        $parentItem = $this->getAggregateParentItem($order);
        $this->assertNotNull($parentItem, 'Aggregate parent item should exist in order');

        $invoiceId = $this->invoiceOrder->execute(
            (int) $order->getEntityId(),
            false,
            [$this->createInvoiceItem((int) $parentItem->getItemId(), 1.0)]
        );
        $invoice = $this->getInvoiceById((int) $invoiceId);
        $resultBySku = $this->indexSelectionItemsBySku($this->getSourceSelectionResultFromInvoice->execute($invoice));

        $this->assertArrayNotHasKey('agg-inv-mixed-parent', $resultBySku);
        $this->assertArrayNotHasKey('agg-inv-physical-child', $resultBySku, 'Physical child should not be selected on invoice');
        $this->assertSame(3.0, $resultBySku['agg-inv-virtual-child'] ?? null, 'Virtual child qty should be 3');
    }

    #[
        DbIsolation(false),
        AppIsolation(true),
        Config('carriers/flatrate/active', 1, 'store', 'default'),
        Config('payment/checkmo/active', 1, 'store', 'default'),
        DataFixture(ProductFixture::class, ['sku' => 'virtual-standalone', 'type_id' => 'virtual', 'weight' => null, 'price' => 10.0], as: 'virtual'),
        DataFixture(SourceItemFixture::class, ['sku' => '$virtual.sku$', 'source_code' => 'default', 'quantity' => 100, 'status' => 1]),
        DataFixture(GuestCartFixture::class, as: 'cart'),
        DataFixture(AddProductToCartFixture::class, ['cart_id' => '$cart.id$', 'product_id' => '$virtual.id$', 'qty' => 2]),
        DataFixture(SetBillingAddressFixture::class, ['cart_id' => '$cart.id$']),
        DataFixture(SetGuestEmailFixture::class, ['cart_id' => '$cart.id$']),
        DataFixture(SetPaymentMethodFixture::class, ['cart_id' => '$cart.id$', 'method' => 'checkmo']),
        DataFixture(PlaceOrderFixture::class, ['cart_id' => '$cart.id$'], as: 'order'),
    ]
    public function testNonAggregateVirtualInvoicePassesThroughCoreLogic(): void
    {
        $order = $this->fixtures->get('order');
        $orderItem = $this->getOrderItemBySku($order, 'virtual-standalone');
        $this->assertNotNull($orderItem, 'Virtual order item should exist');

        $invoiceId = $this->invoiceOrder->execute(
            (int) $order->getEntityId(),
            false,
            [$this->createInvoiceItem((int) $orderItem->getItemId(), 2.0)]
        );
        $invoice = $this->getInvoiceById((int) $invoiceId);
        $resultBySku = $this->indexSelectionItemsBySku($this->getSourceSelectionResultFromInvoice->execute($invoice));

        $this->assertCount(1, $resultBySku);
        $this->assertSame(2.0, $resultBySku['virtual-standalone'] ?? null, 'Standalone virtual product should use core selection');
    }

    private function createInvoiceItem(int $orderItemId, float $qty): InvoiceItemCreationInterface
    {
        $invoiceItem = $this->invoiceItemCreationFactory->create();
        $invoiceItem->setOrderItemId($orderItemId);
        $invoiceItem->setQty($qty);
        return $invoiceItem;
    }

    private function getInvoiceById(int $invoiceId): InvoiceInterface
    {
        return $this->invoiceRepository->get($invoiceId);
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
     * @return array<string, float>
     */
    private function indexSelectionItemsBySku(SourceSelectionResultInterface $result): array
    {
        $indexed = [];
        foreach ($result->getSourceSelectionItems() as $item) {
            $sku = $item->getSku();
            if (!isset($indexed[$sku])) {
                $indexed[$sku] = 0.0;
            }
            $indexed[$sku] += $item->getQtyToDeduct();
        }
        return $indexed;
    }
}
