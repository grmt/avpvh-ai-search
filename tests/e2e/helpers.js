const { expect } = require('@playwright/test');

const ADMIN_PAGE = '/wp-admin/admin.php?page=geweb-ai-search';

/**
 * Optional pause so a headed run can be watched (E2E_DEMO_DELAY_MS, default 0).
 */
async function demoPause(page) {
	const delay = Number(process.env.E2E_DEMO_DELAY_MS || 0);
	if (delay > 0) {
		await page.waitForTimeout(delay);
	}
}

/**
 * Collects uncaught page errors, so a test can assert there were none.
 */
function collectPageErrors(page) {
	const errors = [];
	page.on('pageerror', (error) => errors.push(error.message));
	return errors;
}

/**
 * Opens the dedicated AI page. Its URL comes from the plugin's admin config,
 * so the tests also work on sites where the page has another slug.
 */
async function openWorkspace(page) {
	let url = process.env.E2E_AI_PAGE_URL || '';
	if (!url) {
		await page.goto(ADMIN_PAGE);
		const link = page.locator('p.description:has-text("Current AI Search page") a').first();
		url = (await link.count()) ? String(await link.getAttribute('href')) : '';
	}

	await page.goto(url || '/ai-search/');
	const workspace = page.locator('.geweb-ai-workspace').first();
	await expect(workspace, 'AI workspace should render on the AI page').toBeVisible();
	return workspace;
}

/**
 * Whether the workspace uses the phone layout (History / AI chat / Sources tabs).
 */
async function usesMobilePanes(workspace) {
	return (await workspace.getAttribute('data-mobile-pane')) !== null;
}

/**
 * A real one-finger swipe (Chrome DevTools touch events), so the workspace's
 * touchstart/touchend handlers run.
 */
async function swipe(page, locator, deltaX) {
	const box = await locator.boundingBox();
	const startX = box.x + box.width / 2 - deltaX / 2;
	const y = box.y + Math.min(box.height / 2, 200);
	const session = await page.context().newCDPSession(page);
	const steps = 8;
	await session.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x: startX, y }] });
	for (let step = 1; step <= steps; step++) {
		await session.send('Input.dispatchTouchEvent', {
			type: 'touchMove',
			touchPoints: [{ x: startX + (deltaX * step) / steps, y }],
		});
	}
	await session.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
	await session.detach();
}

/**
 * Posts to admin-ajax with the admin page's nonce, as the admin screens do.
 */
async function postAdminAjax(page, data) {
	const ajaxUrl = await page.evaluate(() => globalThis.gewebAisearchAdmin?.ajaxUrl || globalThis.ajaxurl || '');
	expect(ajaxUrl, 'admin-ajax URL on the admin page').not.toBe('');
	const response = await page.context().request.post(ajaxUrl, { form: data });
	const text = await response.text();
	let body = null;
	try {
		body = text ? JSON.parse(text) : null;
	} catch {
		throw new Error(`Expected JSON from admin-ajax, got: ${text.slice(0, 300)}`);
	}

	return { status: response.status(), body };
}

function allowsWrites(baseURL) {
	if (process.env.E2E_ALLOW_WRITES) {
		return process.env.E2E_ALLOW_WRITES === '1';
	}

	return /^https?:\/\/(localhost|127\.0\.0\.1)(:\d+)?\/?$/.test(String(baseURL));
}

module.exports = {
	ADMIN_PAGE,
	allowsWrites,
	collectPageErrors,
	demoPause,
	openWorkspace,
	postAdminAjax,
	swipe,
	usesMobilePanes,
};
