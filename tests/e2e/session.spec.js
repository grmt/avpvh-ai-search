// Logging out, visiting another site and logging back in (the old cross-site
// cookie regression). Only runs when WP_SESSION_CROSS_SITE_URL is set, for
// example against the live sites; desktop only.
const { test, expect } = require('@playwright/test');

const crossSiteUrl = process.env.WP_SESSION_CROSS_SITE_URL || '';

test.describe('Session', () => {
	test.use({ storageState: { cookies: [], origins: [] } });

	test('log out, visit another site, log back in', async ({ page }) => {
		test.skip(!crossSiteUrl, 'set WP_SESSION_CROSS_SITE_URL to run');
		const username = process.env.WP_USERNAME || 'admin';
		const password = process.env.WP_PASSWORD || 'password';

		const logIn = async () => {
			await page.goto('/wp-login.php');
			await page.locator('#user_login').fill(username);
			await page.locator('#user_pass').fill(password);
			await page.locator('#wp-submit').click();
			await expect(page.locator('#wpadminbar')).toBeVisible();
		};

		await logIn();
		await page.goto('/wp-login.php?action=logout');
		const confirm = page.locator('a[href*="action=logout"]').first();
		if (await confirm.isVisible().catch(() => false)) {
			await confirm.click();
		}
		await expect
			.poll(async () => (await page.context().cookies()).some((cookie) => cookie.name.startsWith('wordpress_logged_in_')))
			.toBe(false);

		await page.goto(crossSiteUrl);
		await logIn();
		await page.goto('/wp-admin/');
		await expect(page).not.toHaveURL(/wp-login\.php/);
	});
});
