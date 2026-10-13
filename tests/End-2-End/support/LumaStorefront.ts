import { expect, type Page } from '@playwright/test';

export interface GuestAddress {
    email: string;
    firstname: string;
    lastname: string;
    street: string;
    city: string;
    postcode: string;
    telephone: string;
    country: string;
    region: string;
}

/**
 * The Luma storefront and its Knockout checkout, driven as a guest shopper.
 */
export class LumaStorefront {
    constructor(private readonly page: Page) {}

    async openProduct(url: string, name: string): Promise<void> {
        await this.page.goto(url, { waitUntil: 'networkidle' });
        await expect(this.page.locator('h1.page-title')).toHaveText(name);
    }

    async addToCart(qty: number): Promise<void> {
        await this.page.locator('#product_addtocart_form #qty').fill(qty.toString());
        await this.page.locator('#product-addtocart-button').click();
        await expect(this.page.locator('.message-success')).toContainText('You added', { timeout: 30_000 });
    }

    async openCart(): Promise<void> {
        await this.page.locator('.message-success a', { hasText: 'shopping cart' }).click();
        await this.page.waitForLoadState('networkidle');
        await expect(this.page.locator('#shopping-cart-table')).toBeVisible();
    }

    async proceedToCheckout(): Promise<void> {
        await this.page.locator('.checkout-methods-items [data-role="proceed-to-checkout"]').click();
        await expect(this.page.locator('#checkout-step-shipping')).toBeVisible({ timeout: 60_000 });
    }

    async fillShippingAddress(address: GuestAddress): Promise<void> {
        const shipping = this.page.locator('#checkout-step-shipping');
        await shipping.locator('#customer-email').fill(address.email);
        await shipping.locator('select[name="country_id"]').selectOption(address.country);
        await shipping.locator('input[name="firstname"]').fill(address.firstname);
        await shipping.locator('input[name="lastname"]').fill(address.lastname);
        await shipping.locator('input[name="street[0]"]').fill(address.street);
        await shipping.locator('input[name="city"]').fill(address.city);
        await shipping.locator('input[name="postcode"]').fill(address.postcode);
        await shipping.locator('input[name="telephone"]').fill(address.telephone);
        await this.fillRegion(address.region);
    }

    async chooseShippingMethod(carrierTitle: string): Promise<void> {
        const methods = this.page.locator('#checkout-step-shipping_method');
        await methods.locator('tr', { hasText: carrierTitle }).locator('input[type=radio]').check({ timeout: 30_000 });
        await this.page.locator('#shipping-method-buttons-container button[data-role="opc-continue"]').click();
        await expect(this.page.locator('#checkout-payment-method-load')).toBeVisible({ timeout: 60_000 });
    }

    async placeOrderWithCheckMoneyOrder(): Promise<string> {
        const method = this.page.locator('.payment-method', { has: this.page.locator('#checkmo') });
        await method.locator('#checkmo').check();
        await method.locator('button.checkout', { hasText: 'Place Order' }).click();
        await expect(this.page.locator('.checkout-success')).toBeVisible({ timeout: 60_000 });

        return (await this.page.locator('.checkout-success .order-number strong, .checkout-success p span').first().innerText()).trim();
    }

    /**
     * Countries with a region list render a select, the others a free text field; only one of the
     * two is visible at a time.
     */
    private async fillRegion(region: string): Promise<void> {
        const regionSelect = this.page.locator('#checkout-step-shipping select[name="region_id"]');
        if (await regionSelect.isVisible()) {
            await regionSelect.selectOption({ label: region });
            return;
        }

        await this.page.locator('#checkout-step-shipping input[name="region"]').fill(region);
    }
}
