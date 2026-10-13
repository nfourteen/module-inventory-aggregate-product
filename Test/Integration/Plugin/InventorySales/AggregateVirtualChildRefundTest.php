<?php
declare(strict_types=1);
/**
 * Copyright © David Nimorwicz. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Nfourteen\InventoryAggregateProduct\Test\Integration\Plugin\InventorySales;

use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\Checkout\Test\Fixture\PlaceOrder as PlaceOrderFixture;
use Magento\Checkout\Test\Fixture\SetBillingAddress as SetBillingAddressFixture;
use Magento\Checkout\Test\Fixture\SetDeliveryMethod as SetDeliveryMethodFixture;
use Magento\Checkout\Test\Fixture\SetGuestEmail as SetGuestEmailFixture;
use Magento\Checkout\Test\Fixture\SetPaymentMethod as SetPaymentMethodFixture;
use Magento\Checkout\Test\Fixture\SetShippingAddress as SetShippingAddressFixture;
use Magento\Framework\App\ResourceConnection;
use Magento\InventoryApi\Api\GetSourceItemsBySkuInterface;
use Magento\InventoryApi\Test\Fixture\SourceItem as SourceItemFixture;
use Magento\InventoryCatalogApi\Api\DefaultSourceProviderInterface;
use Magento\Quote\Test\Fixture\AddProductToCart as AddProductToCartFixture;
use Magento\Quote\Test\Fixture\GuestCart as GuestCartFixture;
use Magento\Sales\Api\CreditmemoManagementInterface;
use Magento\Sales\Api\Data\OrderInterface;
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
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use Nfourteen\AggregateProduct\Model\Product\Type\Aggregate;
use Nfourteen\AggregateProduct\Test\Fixture\AggregateProduct as AggregateProductFixture;
use PHPUnit\Framework\TestCase;

/**
 * A virtual child leaves its source when the pack is invoiced, so returning the pack to stock
 * has to put it back on that source and leave no reservation behind.
 *
 * Each pack is created after its children's source items: saving a child's source item after
 * the pack exists re-indexes the pack as out of stock, and the order could not be placed.
 *
 * @magentoAppArea adminhtml
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class AggregateVirtualChildRefundTest extends TestCase
{
    #[
        DbIsolation(false),
        AppIsolation(true),
        Config('carriers/flatrate/active', 1, 'store', 'default'),
        Config('payment/checkmo/active', 1, 'store', 'default'),
        DataFixture(ProductFixture::class, ['sku' => 'agg-vref-box-%uniqid%', 'price' => 5.0], as: 'box'),
        DataFixture(ProductFixture::class, ['sku' => 'agg-vref-licence-%uniqid%', 'type_id' => 'virtual', 'weight' => null, 'price' => 3.0], as: 'licence'),
        DataFixture(SourceItemFixture::class, ['sku' => '$box.sku$', 'source_code' => 'default', 'quantity' => 100, 'status' => 1]),
        DataFixture(SourceItemFixture::class, ['sku' => '$licence.sku$', 'source_code' => 'default', 'quantity' => 100, 'status' => 1]),
        DataFixture(AggregateProductFixture::class, ['sku' => 'agg-vref-pack-%uniqid%', 'price' => 10.0, '_children' => [
            ['product_id' => '$box.id$', 'qty' => 2],
            ['product_id' => '$licence.id$', 'qty' => 3],
        ]], as: 'aggregate'),
        DataFixture(GuestCartFixture::class, as: 'cart'),
        DataFixture(AddProductToCartFixture::class, ['cart_id' => '$cart.id$', 'product_id' => '$aggregate.id$', 'qty' => 1]),
        DataFixture(SetBillingAddressFixture::class, ['cart_id' => '$cart.id$']),
        DataFixture(SetShippingAddressFixture::class, ['cart_id' => '$cart.id$']),
        DataFixture(SetGuestEmailFixture::class, ['cart_id' => '$cart.id$']),
        DataFixture(SetDeliveryMethodFixture::class, ['cart_id' => '$cart.id$', 'carrier_code' => 'flatrate', 'method_code' => 'flatrate']),
        DataFixture(SetPaymentMethodFixture::class, ['cart_id' => '$cart.id$', 'method' => 'checkmo']),
        DataFixture(PlaceOrderFixture::class, ['cart_id' => '$cart.id$'], as: 'order'),
    ]
    public function testReturnToStockPutsTheVirtualChildBackOnItsSource(): void
    {
        $order = $this->invoiceAndShip($this->getFixtureOrder());

        $this->refundReturningToStock($order, 1);

        $this->assertSame(100.0, $this->getDefaultSourceQty($this->getFixtureSku('licence')));
        $this->assertSame(0.0, $this->getOpenReservationQty($this->getFixtureSku('licence')));
        $this->assertSame(100.0, $this->getDefaultSourceQty($this->getFixtureSku('box')));
        $this->assertSame(0.0, $this->getOpenReservationQty($this->getFixtureSku('box')));
    }

    #[
        DbIsolation(false),
        AppIsolation(true),
        Config('carriers/flatrate/active', 1, 'store', 'default'),
        Config('payment/checkmo/active', 1, 'store', 'default'),
        DataFixture(ProductFixture::class, ['sku' => 'agg-vpart-licence-%uniqid%', 'type_id' => 'virtual', 'weight' => null, 'price' => 3.0], as: 'licence'),
        DataFixture(SourceItemFixture::class, ['sku' => '$licence.sku$', 'source_code' => 'default', 'quantity' => 100, 'status' => 1]),
        DataFixture(AggregateProductFixture::class, ['sku' => 'agg-vpart-pack-%uniqid%', 'price' => 10.0, '_children' => [
            ['product_id' => '$licence.id$', 'qty' => 3],
        ]], as: 'aggregate'),
        DataFixture(GuestCartFixture::class, as: 'cart'),
        DataFixture(AddProductToCartFixture::class, ['cart_id' => '$cart.id$', 'product_id' => '$aggregate.id$', 'qty' => 2]),
        DataFixture(SetBillingAddressFixture::class, ['cart_id' => '$cart.id$']),
        DataFixture(SetShippingAddressFixture::class, ['cart_id' => '$cart.id$']),
        DataFixture(SetGuestEmailFixture::class, ['cart_id' => '$cart.id$']),
        DataFixture(SetDeliveryMethodFixture::class, ['cart_id' => '$cart.id$', 'carrier_code' => 'flatrate', 'method_code' => 'flatrate']),
        DataFixture(SetPaymentMethodFixture::class, ['cart_id' => '$cart.id$', 'method' => 'checkmo']),
        DataFixture(PlaceOrderFixture::class, ['cart_id' => '$cart.id$'], as: 'order'),
    ]
    public function testReturningOnePackOfTwoPutsBackOnlyThatPacksVirtualChildren(): void
    {
        $order = $this->invoiceAndShip($this->getFixtureOrder());

        $this->refundReturningToStock($order, 1);

        $this->assertSame(97.0, $this->getDefaultSourceQty($this->getFixtureSku('licence')));
        $this->assertSame(0.0, $this->getOpenReservationQty($this->getFixtureSku('licence')));
    }

    #[
        DbIsolation(false),
        AppIsolation(true),
        Config('carriers/flatrate/active', 1, 'store', 'default'),
        Config('payment/checkmo/active', 1, 'store', 'default'),
        DataFixture(ProductFixture::class, ['sku' => 'agg-vnot-licence-%uniqid%', 'type_id' => 'virtual', 'weight' => null, 'price' => 3.0], as: 'licence'),
        DataFixture(SourceItemFixture::class, ['sku' => '$licence.sku$', 'source_code' => 'default', 'quantity' => 100, 'status' => 1]),
        DataFixture(AggregateProductFixture::class, ['sku' => 'agg-vnot-pack-%uniqid%', 'price' => 10.0, '_children' => [
            ['product_id' => '$licence.id$', 'qty' => 3],
        ]], as: 'aggregate'),
        DataFixture(GuestCartFixture::class, as: 'cart'),
        DataFixture(AddProductToCartFixture::class, ['cart_id' => '$cart.id$', 'product_id' => '$aggregate.id$', 'qty' => 1]),
        DataFixture(SetBillingAddressFixture::class, ['cart_id' => '$cart.id$']),
        DataFixture(SetShippingAddressFixture::class, ['cart_id' => '$cart.id$']),
        DataFixture(SetGuestEmailFixture::class, ['cart_id' => '$cart.id$']),
        DataFixture(SetDeliveryMethodFixture::class, ['cart_id' => '$cart.id$', 'carrier_code' => 'flatrate', 'method_code' => 'flatrate']),
        DataFixture(SetPaymentMethodFixture::class, ['cart_id' => '$cart.id$', 'method' => 'checkmo']),
        DataFixture(PlaceOrderFixture::class, ['cart_id' => '$cart.id$'], as: 'order'),
    ]
    public function testRefundWithoutReturnToStockLeavesTheVirtualChildOffItsSource(): void
    {
        $order = $this->invoiceAndShip($this->getFixtureOrder());

        $this->refund($order, 1, false);

        $this->assertSame(97.0, $this->getDefaultSourceQty($this->getFixtureSku('licence')));
        $this->assertSame(0.0, $this->getOpenReservationQty($this->getFixtureSku('licence')));
    }

    private function getFixtureOrder(): OrderInterface
    {
        return Bootstrap::getObjectManager()->get(DataFixtureStorageManager::class)->getStorage()->get('order');
    }

    /**
     * Every run gets fresh SKUs: reservations are keyed by SKU and outlive the fixtures.
     */
    private function getFixtureSku(string $fixtureName): string
    {
        return Bootstrap::getObjectManager()->get(DataFixtureStorageManager::class)->getStorage()->get($fixtureName)->getSku();
    }

    private function refundReturningToStock(Order $order, float $packQty): void
    {
        $this->refund($order, $packQty, true);
    }

    /**
     * Builds the credit memo the way the admin does for a pack: only the visible parent row carries
     * a quantity and the "Return to Stock" tick, which the loader copies onto the hidden children.
     */
    private function refund(Order $order, float $packQty, bool $isReturnedToStock): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $parentItemId = (int) $this->getAggregateParentItem($order)->getItemId();
        $creditmemo = $objectManager->get(CreditmemoFactory::class)->createByOrder($order, [
            'qtys' => [$parentItemId => $packQty],
        ]);
        $this->setBackToStock($creditmemo, $parentItemId, $isReturnedToStock);

        $objectManager->get(CreditmemoManagementInterface::class)->refund($creditmemo, true);
    }

    private function setBackToStock(Creditmemo $creditmemo, int $parentItemId, bool $isReturnedToStock): void
    {
        array_map(
            static fn ($creditmemoItem) => $creditmemoItem->setBackToStock(
                $isReturnedToStock
                && in_array($parentItemId, [
                    (int) $creditmemoItem->getOrderItemId(),
                    (int) $creditmemoItem->getOrderItem()->getParentItemId(),
                ], true)
            ),
            $creditmemo->getAllItems()
        );
    }

    private function invoiceAndShip(OrderInterface $order): Order
    {
        $objectManager = Bootstrap::getObjectManager();
        $orderId = (int) $order->getEntityId();
        $objectManager->get(InvoiceOrderInterface::class)->execute($orderId);

        $shipmentItems = $this->createShippableItems($objectManager->get(OrderRepositoryInterface::class)->get($orderId));
        if ($shipmentItems !== []) {
            $objectManager->get(ShipOrderInterface::class)->execute($orderId, $shipmentItems);
        }

        return $objectManager->get(OrderRepositoryInterface::class)->get($orderId);
    }

    private function createShippableItems(OrderInterface $order): array
    {
        $factory = Bootstrap::getObjectManager()->get(ShipmentItemCreationInterfaceFactory::class);

        return array_values(array_map(
            static fn (OrderItem $item) => $factory->create()
                ->setOrderItemId((int) $item->getItemId())
                ->setQty((float) $item->getQtyToShip()),
            array_filter(
                $order->getAllItems(),
                static fn (OrderItem $item) => (float) $item->getQtyToShip() > 0.0
                    && !$item->getIsVirtual()
                    && !$item->getLockedDoShip()
            )
        ));
    }

    private function getAggregateParentItem(OrderInterface $order): OrderItem
    {
        return array_values(array_filter(
            $order->getAllItems(),
            static fn (OrderItem $item) => $item->getProductType() === Aggregate::TYPE_CODE && !$item->getParentItemId()
        ))[0];
    }

    private function getDefaultSourceQty(string $sku): float
    {
        $objectManager = Bootstrap::getObjectManager();
        $defaultSourceCode = $objectManager->get(DefaultSourceProviderInterface::class)->getCode();

        return (float) array_values(array_filter(
            $objectManager->get(GetSourceItemsBySkuInterface::class)->execute($sku),
            static fn ($sourceItem) => $sourceItem->getSourceCode() === $defaultSourceCode
        ))[0]->getQuantity();
    }

    private function getOpenReservationQty(string $sku): float
    {
        $connection = Bootstrap::getObjectManager()->get(ResourceConnection::class)->getConnection();

        return (float) $connection->fetchOne(
            $connection->select()
                ->from($connection->getTableName('inventory_reservation'), ['SUM(quantity)'])
                ->where('sku = ?', $sku)
        );
    }
}
