// The AI workspace on the dedicated AI page, at desktop, narrow, phone and
// iPad sizes (projects in playwright.config.js; tests tagged @layout).
const { test, expect } = require('@playwright/test');
const { collectPageErrors, demoPause, openWorkspace, swipe, usesMobilePanes } = require('./helpers');

test.describe('AI workspace @layout', () => {
	test('loads without script errors', async ({ page }) => {
		const errors = collectPageErrors(page);
		const workspace = await openWorkspace(page);

		await expect(workspace.locator('.geweb-ai-main-panel')).toBeVisible();
		await expect(page.locator('#geweb-ask-ai-submit')).toBeAttached();
		await demoPause(page);
		expect(errors, 'uncaught JavaScript errors').toEqual([]);
	});

	test('side panes collapse and expand', async ({ page }) => {
		const workspace = await openWorkspace(page);
		test.skip(await usesMobilePanes(workspace), 'phone layout uses tabs instead (see below)');

		// Up to 834px wide the workspace starts with both side panes collapsed,
		// so each button must flip the state and flip it back.
		for (const side of ['left', 'right']) {
			const button = workspace.locator(`.geweb-ai-panel-collapse[data-panel-toggle="${side}"]`).first();
			const collapsedClass = `is-${side}-collapsed`;
			const isCollapsed = async () => (await workspace.evaluate((el, cls) => el.classList.contains(cls), collapsedClass));

			await expect(button).toBeVisible();
			const startsCollapsed = await isCollapsed();

			await button.click();
			await expect.poll(isCollapsed, { message: `${side} pane toggles` }).toBe(!startsCollapsed);
			await expect(button).toHaveAttribute('aria-expanded', startsCollapsed ? 'true' : 'false');
			await demoPause(page);

			await button.click();
			await expect.poll(isCollapsed, { message: `${side} pane toggles back` }).toBe(startsCollapsed);
			await demoPause(page);
		}
	});

	test('search results panel opens and closes', async ({ page }) => {
		const workspace = await openWorkspace(page);
		const panel = page.locator('.geweb-ai-search-results-panel').first();
		const toggle = page.locator('.geweb-ai-panel-collapse[data-panel-toggle="search"]').first();
		test.skip(!(await toggle.isVisible().catch(() => false)), 'search panel toggle not shown in this layout');

		const startsCollapsed = /\bis-collapsed\b/.test(String(await panel.getAttribute('class')));
		await toggle.click();
		await expect(panel).toHaveClass(startsCollapsed ? /^(?!.*\bis-collapsed\b)/ : /\bis-collapsed\b/);
		await demoPause(page);

		await toggle.click();
		await expect(panel).toHaveClass(startsCollapsed ? /\bis-collapsed\b/ : /^(?!.*\bis-collapsed\b)/);
		await expect(workspace).toBeVisible();
	});

	test('phone tabs switch between History, AI chat and Sources', async ({ page }) => {
		const workspace = await openWorkspace(page);
		test.skip(!(await usesMobilePanes(workspace)), 'only the phone layout has tabs');

		for (const pane of ['left', 'right', 'main']) {
			const tab = page.locator(`.geweb-ai-mobile-pane-tab[data-mobile-pane-target="${pane}"]`).first();
			await tab.click();
			await expect(workspace).toHaveAttribute('data-mobile-pane', pane);
			await expect(tab).toHaveAttribute('aria-pressed', 'true');
			await demoPause(page);
		}
	});

	test('phone swipe moves between panes', async ({ page }) => {
		const workspace = await openWorkspace(page);
		test.skip(!(await usesMobilePanes(workspace)), 'only the phone layout swipes');

		await expect(workspace).toHaveAttribute('data-mobile-pane', 'main');
		await swipe(page, workspace, -150);
		await expect(workspace, 'swipe left opens Sources').toHaveAttribute('data-mobile-pane', 'right');
		await demoPause(page);

		await swipe(page, workspace, 150);
		await expect(workspace, 'swipe right returns to the chat').toHaveAttribute('data-mobile-pane', 'main');
		await swipe(page, workspace, 150);
		await expect(workspace, 'swipe right again opens History').toHaveAttribute('data-mobile-pane', 'left');
	});
});
