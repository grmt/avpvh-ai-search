const path = require('path');
const { test: setup, expect } = require('@playwright/test');

const authFile = path.join(__dirname, '.auth', 'admin.json');

function credentials(baseURL) {
	const isLocal = /^https?:\/\/(localhost|127\.0\.0\.1)(:\d+)?\/?$/.test(String(baseURL));
	const username = process.env.WP_USERNAME || (isLocal ? 'admin' : '');
	const password = process.env.WP_PASSWORD || (isLocal ? 'password' : '');
	if (!username || !password) {
		throw new Error('Set WP_USERNAME and WP_PASSWORD to run the browser tests against ' + baseURL);
	}

	return { username, password };
}

setup('log in to WordPress', async ({ page, baseURL }) => {
	const { username, password } = credentials(baseURL);

	await page.goto('/wp-login.php');
	await page.locator('#user_login').fill(username);
	await page.locator('#user_pass').fill(password);
	const rememberMe = page.locator('#rememberme');
	if (await rememberMe.isVisible().catch(() => false)) {
		await rememberMe.check();
	}
	await page.locator('#wp-submit').click();

	await expect(page.locator('#wpadminbar')).toBeVisible();
	await expect
		.poll(async () => (await page.context().cookies()).some((cookie) => cookie.name.startsWith('wordpress_logged_in_')))
		.toBe(true);

	await page.context().storageState({ path: authFile });
});
