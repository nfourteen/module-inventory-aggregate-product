import { expect, test } from '@playwright/test';
import { ADMIN_SESSION_PATH } from '../global-setup';
import { AdminOrderView } from '../support/AdminOrderView';
import { CatalogFixture, type ChildProduct } from '../support/CatalogFixture';
import { LumaStorefront } from '../support/LumaStorefront';

const CHILD_PRICE = 5;
const CHILD_STOCK = 100;
const AGGREGATE_PRICE = 25;
const ORDERED_QTY = 2;

/**
 * A pack with a virtual child is bought, invoiced and refunded with "Return to Stock" ticked.
 *
 * A virtual product never ships, so Magento takes it off the source when the order is invoiced.
 * Returning the pack to stock has to put it back on the source too, and leave no reservation
 * behind once the order is done with.
 *
 * The pack holds only the virtual child. The pack itself is not virtual, so checkout still asks
 * for shipping.
 */
test.describe('Refunding an aggregate with a virtual child', () => {
    const catalog = new CatalogFixture();
    const aggregateName = `E2E Aggregate ${catalog.prefix}`;
    const aggregateSku = catalog.sku('pack');
    const virtualChild: ChildProduct = {
        sku: catalog.sku('licence'),
        name: `E2E Licence ${catalog.prefix}`,
        type: 'virtual',
        linkQty: 2,
    };

    test.beforeAll(() => {
        catalog.createChildProduct(virtualChild, CHILD_PRICE, CHILD_STOCK);
        catalog.createAggregateProduct(aggregateSku, aggregateName, AGGREGATE_PRICE, [virtualChild]);
        catalog.processScheduledIndexes();
    });

    test.afterAll(() => {
        catalog.removeAll();
    });

    test('returning the pack to stock puts the virtual child back on its source', async ({ browser }) => {
        const admin = await browser.newContext({ storageState: ADMIN_SESSION_PATH });
        const shopper = await browser.newContext();
        const storefront = new LumaStorefront(await shopper.newPage());

        const incrementId = await test.step('a guest buys two packs', async () => {
            await storefront.openProduct(catalog.productUrl(aggregateSku), aggregateName);
            await storefront.addToCart(ORDERED_QTY);
            await storefront.openCart();
            await storefront.proceedToCheckout();
            await storefront.fillShippingAddress({
                email: `${catalog.prefix}@example.com`,
                firstname: 'Erin',
                lastname: 'Example',
                street: '1 Test Street',
                city: 'Austin',
                postcode: '78701',
                telephone: '5125550100',
                country: 'US',
                region: 'Texas',
            });
            await storefront.chooseShippingMethod('Flat Rate');

            return storefront.placeOrderWithCheckMoneyOrder();
        });

        const orderView = new AdminOrderView(await admin.newPage());

        await test.step('invoicing takes the virtual child off its source', async () => {
            await orderView.open(incrementId);
            await orderView.invoiceEverything();

            expect(catalog.sourceQty(virtualChild.sku)).toBe(CHILD_STOCK - ORDERED_QTY * virtualChild.linkQty);
        });

        await test.step('the merchant refunds the packs and returns them to stock', async () => {
            await orderView.open(incrementId);
            await orderView.refundReturningToStock(aggregateSku);
        });

        await test.step('the virtual child is back on its source, with nothing left reserved', async () => {
            expect.soft(catalog.sourceQty(virtualChild.sku), 'quantity on the source').toBe(CHILD_STOCK);
            expect.soft(catalog.openReservationQty(virtualChild.sku), 'open reservations').toBe(0);
            expect.soft(catalog.salableQty(virtualChild.sku), 'salable quantity').toBe(CHILD_STOCK);
        });

        await admin.close();
        await shopper.close();
    });
});
