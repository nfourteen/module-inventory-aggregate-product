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
use Magento\InventoryApi\Api\Data\SourceItemInterface;
use Magento\InventoryApi\Api\GetSourceItemsBySkuInterface;
use Magento\InventoryApi\Test\Fixture\SourceItem as SourceItemFixture;
use Magento\InventoryCatalogApi\Api\DefaultSourceProviderInterface;
use Magento\InventorySalesApi\Api\Data\SalesChannelInterface;
use Magento\InventorySalesApi\Api\GetProductSalableQtyInterface;
use Magento\InventorySalesApi\Api\StockResolverInterface;
use Magento\Quote\Test\Fixture\AddProductToCart as AddProductToCartFixture;
use Magento\Quote\Test\Fixture\GuestCart as GuestCartFixture;
use Magento\Sales\Api\CreditmemoManagementInterface;
use Magento\Sales\Api\CreditmemoRepositoryInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\ShipmentItemCreationInterface;
use Magento\Sales\Api\Data\ShipmentItemCreationInterfaceFactory;
use Magento\Sales\Api\InvoiceOrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\ShipOrderInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\CreditmemoFactory;
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
 * Without ProcessRefundItemsForAggregatePlugin a credit-memo return-to-stock restores nothing
 * (core skips the parent, and the children are hidden order items), so inventory drifts on
 * every return. The credit-memo save goes through the real sales_order_creditmemo_save_after
 * observer, exercising the production seam end to end.
 *
 * @magentoAppArea adminhtml
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class AggregateRefundReturnToStockTest extends TestCase
{
    private ?object $objectManager = null;
    private ?InvoiceOrderInterface $invoiceOrder = null;
    private ?ShipOrderInterface $shipOrder = null;
    private ?ShipmentItemCreationInterfaceFactory $shipmentItemCreationFactory = null;
    private ?CreditmemoFactory $creditmemoFactory = null;
    private ?CreditmemoRepositoryInterface $creditmemoRepository = null;
    private ?GetSourceItemsBySkuInterface $getSourceItemsBySku = null;
    private ?GetProductSalableQtyInterface $getProductSalableQty = null;
    private ?StockResolverInterface $stockResolver = null;
    private ?OrderRepositoryInterface $orderRepository = null;
    private ?DataFixtureStorage $fixtures = null;

    protected function setUp(): void
    {
        $this->objectManager = Bootstrap::getObjectManager();
        $this->invoiceOrder = $this->objectManager->get(InvoiceOrderInterface::class);
        $this->shipOrder = $this->objectManager->get(ShipOrderInterface::class);
        $this->shipmentItemCreationFactory = $this->objectManager->get(ShipmentItemCreationInterfaceFactory::class);
        $this->creditmemoFactory = $this->objectManager->get(CreditmemoFactory::class);
        $this->creditmemoRepository = $this->objectManager->get(CreditmemoRepositoryInterface::class);
        $this->getSourceItemsBySku = $this->objectManager->get(GetSourceItemsBySkuInterface::class);
        $this->getProductSalableQty = $this->objectManager->get(GetProductSalableQtyInterface::class);
        $this->stockResolver = $this->objectManager->get(StockResolverInterface::class);
        $this->orderRepository = $this->objectManager->get(OrderRepositoryInterface::class);
        $this->fixtures = $this->objectManager->get(DataFixtureStorageManager::class)->getStorage();
    }

    #[
        DbIsolation(false),
        AppIsolation(true),
        Config('carriers/flatrate/active', 1, 'store', 'default'),
        Config('payment/checkmo/active', 1, 'store', 'default'),
        DataFixture(ProductFixture::class, ['sku' => 'agg-rts-child1', 'price' => 5.0], as: 'child1'),
        DataFixture(ProductFixture::class, ['sku' => 'agg-rts-child2', 'price' => 3.0], as: 'child2'),
        DataFixture(AggregateProductFixture::class, ['sku' => 'agg-rts-parent', 'price' => 10.0, '_children' => [
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
    public function testReturnToStockRestoresChildInventory(): void
    {
        $order = $this->invoiceAndShip($this->fixtures->get('order'));
        $parentItem = $this->getAggregateParentItem($order);
        $this->assertNotNull($parentItem, 'Aggregate parent item should exist in order');

        // Sanity: shipment deducted the child source qty (child1: 2, child2: 3 per parent).
        $this->assertEquals(98.0, $this->getDefaultSourceQty('agg-rts-child1'), 'child1 deducted on ship');
        $this->assertEquals(97.0, $this->getDefaultSourceQty('agg-rts-child2'), 'child2 deducted on ship');

        $creditmemo = $this->creditmemoFactory->createByOrder($order, [
            'qtys' => [(int) $parentItem->getItemId() => 1],
        ]);
        // The admin checks "return to stock" on the visible parent row only; the children are
        // hidden and the aggregate refund plugin restores them by expanding the parent.
        $this->setParentBackToStock($creditmemo, (int) $parentItem->getItemId());

        // Go through the real refund seam: CreditmemoManagementInterface::refund pre-increments
        // qtyRefunded on the parent order item, then dispatches sales_order_creditmemo_save_after,
        // whose observer calls ReturnProcessor::execute (where the aggregate plugin runs).
        $this->objectManager->get(CreditmemoManagementInterface::class)->refund($creditmemo, true);

        $this->assertEquals(100.0, $this->getDefaultSourceQty('agg-rts-child1'), 'child1 source restored on return-to-stock');
        $this->assertEquals(100.0, $this->getDefaultSourceQty('agg-rts-child2'), 'child2 source restored on return-to-stock');
        $this->assertEquals(100.0, $this->getDefaultSalableQty('agg-rts-child1'), 'child1 salable qty restored');
        $this->assertEquals(100.0, $this->getDefaultSalableQty('agg-rts-child2'), 'child2 salable qty restored');
    }

    /**
     * auto_return cascades back_to_stock=true onto EVERY credit-memo item, putting the hidden
     * children (whose createByOrder qty is an unscaled 1) in core's return-to-stock list too —
     * the harder case: trusting that qty restores the wrong amount, and letting both core and
     * the plugin restore would double count. Proves each child is restored exactly once at the
     * parent-scaled qty.
     */
    #[
        DbIsolation(false),
        AppIsolation(true),
        Config('carriers/flatrate/active', 1, 'store', 'default'),
        Config('payment/checkmo/active', 1, 'store', 'default'),
        Config('cataloginventory/item_options/auto_return', 1, 'store', 'default'),
        DataFixture(ProductFixture::class, ['sku' => 'agg-auto-child1', 'price' => 5.0], as: 'child1'),
        DataFixture(ProductFixture::class, ['sku' => 'agg-auto-child2', 'price' => 3.0], as: 'child2'),
        DataFixture(AggregateProductFixture::class, ['sku' => 'agg-auto-parent', 'price' => 10.0, '_children' => [
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
    public function testAutoReturnRestoresChildInventoryExactlyOnce(): void
    {
        $order = $this->invoiceAndShip($this->fixtures->get('order'));
        $parentItem = $this->getAggregateParentItem($order);
        $this->assertNotNull($parentItem, 'Aggregate parent item should exist in order');

        // Sanity: shipment deducted the child source qty (child1: 2, child2: 3 per parent).
        $this->assertEquals(98.0, $this->getDefaultSourceQty('agg-auto-child1'), 'child1 deducted on ship');
        $this->assertEquals(97.0, $this->getDefaultSourceQty('agg-auto-child2'), 'child2 deducted on ship');

        $creditmemo = $this->creditmemoFactory->createByOrder($order, [
            'qtys' => [(int) $parentItem->getItemId() => 1],
        ]);
        // Mirror admin CreditmemoLoader under auto_return: back_to_stock=true on EVERY item, parent
        // and hidden children alike. The plugin must dedupe the children out of core's list and still
        // restore them exactly once at the parent-scaled qty.
        $this->setAllItemsBackToStock($creditmemo);

        $this->objectManager->get(CreditmemoManagementInterface::class)->refund($creditmemo, true);

        $this->assertEquals(100.0, $this->getDefaultSourceQty('agg-auto-child1'), 'child1 restored exactly once (scaled +2, not unscaled +1 or doubled)');
        $this->assertEquals(100.0, $this->getDefaultSourceQty('agg-auto-child2'), 'child2 restored exactly once (scaled +3, not unscaled +1 or doubled)');
        $this->assertEquals(100.0, $this->getDefaultSalableQty('agg-auto-child1'), 'child1 salable qty restored');
        $this->assertEquals(100.0, $this->getDefaultSalableQty('agg-auto-child2'), 'child2 salable qty restored');
    }

    private function invoiceAndShip(OrderInterface $order): Order
    {
        $orderId = (int) $order->getEntityId();
        $this->invoiceOrder->execute($orderId);

        $order = $this->orderRepository->get($orderId);
        $shipmentItems = $this->createShippableItemsFromOrder($order);
        if (!empty($shipmentItems)) {
            $this->shipOrder->execute($orderId, $shipmentItems);
        }

        /** @var Order $reloaded */
        $reloaded = $this->orderRepository->get($orderId);
        return $reloaded;
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

    private function setParentBackToStock(Creditmemo $creditmemo, int $parentOrderItemId): void
    {
        foreach ($creditmemo->getAllItems() as $creditmemoItem) {
            $orderItem = $creditmemoItem->getOrderItem();
            $creditmemoItem->setBackToStock(
                $orderItem !== null && (int) $orderItem->getId() === $parentOrderItemId
            );
        }
    }

    private function setAllItemsBackToStock(Creditmemo $creditmemo): void
    {
        foreach ($creditmemo->getAllItems() as $creditmemoItem) {
            $creditmemoItem->setBackToStock(true);
        }
    }

    private function getDefaultSourceQty(string $sku): float
    {
        $defaultSourceCode = $this->objectManager->get(DefaultSourceProviderInterface::class)->getCode();
        foreach ($this->getSourceItemsBySku->execute($sku) as $sourceItem) {
            if ($sourceItem->getSourceCode() === $defaultSourceCode) {
                /** @var SourceItemInterface $sourceItem */
                return (float) $sourceItem->getQuantity();
            }
        }
        $this->fail(sprintf('No default source item for SKU %s', $sku));
    }

    private function getDefaultSalableQty(string $sku): float
    {
        $stock = $this->stockResolver->execute(SalesChannelInterface::TYPE_WEBSITE, 'base');
        return $this->getProductSalableQty->execute($sku, (int) $stock->getStockId());
    }
}
