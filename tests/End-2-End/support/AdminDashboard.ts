import { expect, type Page } from '@playwright/test';

/**
 * Opens the admin dashboard, the starting point for every admin page in this suite.
 *
 * Magento Open Source greets a fresh admin with Magento_AdminAnalytics's "Allow Adobe to collect
 * usage data" modal, which covers the whole page and swallows menu clicks. Mage-OS does not ship
 * that module, so the modal is only answered when it is actually there.
 */
export async function openAdminDashboard(page: Page): Promise<void> {
    await page.goto(process.env.ADMIN_PATH || 'admin', { waitUntil: 'networkidle' });

    const notification = page.locator('.modal-popup.admin-usage-notification._show');
    if ((await notification.count()) === 0) {
        return;
    }

    await notification.getByRole('button', { name: "Don't Allow" }).click();
    await expect(notification).toBeHidden();
}
