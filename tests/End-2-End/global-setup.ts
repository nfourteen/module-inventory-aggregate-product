import { chromium, type FullConfig } from '@playwright/test';
import fs from 'fs';
import path from 'path';

export const ADMIN_SESSION_PATH = path.resolve(__dirname, './test-results/admin-session.json');

/**
 * Signs in to the admin once. Magento's admin login is rate limited and its form key rotates, so
 * logging in per test is slow and fails for reasons unrelated to the aggregate product.
 *
 * Only the admin pages use this session. The storefront half of the flow runs in a fresh context,
 * as a guest, the way a shopper would.
 */
async function globalSetup(config: FullConfig): Promise<void> {
    process.env.NODE_TLS_REJECT_UNAUTHORIZED = '0';

    const baseURL = config.projects[0].use.baseURL as string;
    const adminPath = process.env.ADMIN_PATH || 'admin';
    const username = process.env.ADMIN_USER || 'e2e_admin';
    const password = process.env.ADMIN_PASSWORD || 'E2eAdminPassword1';

    fs.mkdirSync(path.dirname(ADMIN_SESSION_PATH), { recursive: true });

    const browser = await chromium.launch();
    const context = await browser.newContext({ ignoreHTTPSErrors: true });
    const page = await context.newPage();

    await page.goto(new URL(adminPath, baseURL).toString(), { waitUntil: 'networkidle' });
    await page.fill('#username', username);
    await page.fill('#login', password);
    await page.click('.action-login');
    await page.waitForLoadState('networkidle');

    if (await page.locator('#username').count()) {
        throw new Error(`Admin login failed for "${username}". The suite cannot run without it.`);
    }

    await context.storageState({ path: ADMIN_SESSION_PATH });
    await browser.close();
}

export default globalSetup;
