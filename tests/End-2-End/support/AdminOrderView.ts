import { expect, type Locator, type Page } from '@playwright/test';
import { openAdminDashboard } from './AdminDashboard';

/**
 * The admin order view and the invoice and credit memo pages reached from it, opened through
 * Sales > Orders and the grid's keyword search, the way a merchant finds an order.
 */
export class AdminOrderView {
    constructor(private readonly page: Page) {}

    async open(incrementId: string): Promise<void> {
        await openAdminDashboard(this.page);
        await this.page.locator('#menu-magento-sales-sales > a').click();
        await this.page.locator('[data-ui-id="menu-magento-sales-sales-order"] a').click();
        await this.page.waitForLoadState('networkidle');

        const search = this.page.getByRole('textbox', { name: 'Search by keyword' }).filter({ visible: true });
        await search.fill(incrementId);
        await search.press('Enter');

        const row = this.page.locator('.data-grid tbody tr', { hasText: incrementId });
        await expect(row).toHaveCount(1, { timeout: 30_000 });
        await row.getByRole('link', { name: 'View' }).click();
        await this.page.waitForLoadState('networkidle');
        await expect(this.page.locator('h1.page-title')).toContainText(incrementId);
    }

    async invoiceEverything(): Promise<void> {
        await this.page.locator('#order_invoice').click();
        await this.page.waitForLoadState('networkidle');
        await this.page.locator('.order-totals-actions button.submit-button').click();
        await expect(this.successMessage()).toContainText('The invoice has been created.', { timeout: 60_000 });
    }

    /**
     * Opens "Credit Memo", ticks "Return to Stock" on the row of the given product, and refunds
     * offline, which is the only refund a check/money order payment offers.
     */
    async refundReturningToStock(sku: string): Promise<void> {
        await this.page.locator('#order_creditmemo').click();
        await this.page.waitForLoadState('networkidle');
        await this.creditMemoRow(sku).locator('input[name$="[back_to_stock]"]').check();
        await this.page.getByRole('button', { name: 'Refund Offline' }).click();
        await expect(this.successMessage()).toContainText('You created the credit memo.', { timeout: 60_000 });
    }

    private creditMemoRow(sku: string): Locator {
        return this.page.locator('#creditmemo_item_container tbody', {
            has: this.page.locator('.product-sku-block', { hasText: sku }),
        });
    }

    private successMessage(): Locator {
        return this.page.locator('#messages .message-success, .page.messages .message-success').first();
    }
}
