// Admin screens (desktop only).
const { test, expect } = require('@playwright/test');
const { ADMIN_PAGE, allowsWrites, collectPageErrors, demoPause, postAdminAjax } = require('./helpers');

test.describe('Admin settings', () => {
	test('General tab shows the model table with a working filter', async ({ page }) => {
		const errors = collectPageErrors(page);
		await page.goto(`${ADMIN_PAGE}&geweb_tab=general`);

		const table = page.locator('.geweb-model-diagnostics-table');
		await expect(table).toBeVisible();
		const rows = table.locator('tbody tr');
		await expect(rows.first()).toBeVisible();
		const total = await rows.count();
		const count = page.locator('.geweb-model-filter-count');
		// Retired models are hidden by default, so the counter may already show.
		const initialCount = (await count.textContent()) || '';

		await page.locator('.geweb-model-filter-search').fill('gemini-4');
		await expect(rows.filter({ visible: true }).first()).toContainText('gemini-4');
		const shown = await rows.filter({ visible: true }).count();
		expect(shown).toBeGreaterThan(0);
		expect(shown).toBeLessThan(total);
		await expect(count).toContainText(`Showing ${shown} of`);
		await demoPause(page);

		await page.locator('.geweb-model-filter-search').fill('');
		await expect(count).toHaveText(initialCount);

		await page.locator('.geweb-model-filter-hide-retired').uncheck();
		await expect(count, 'all models shown when retired ones are included').toHaveText('');
		expect(errors, 'uncaught JavaScript errors').toEqual([]);
	});

	test('a stale save is refused with 409 and can be retried', async ({ page, baseURL }) => {
		test.skip(!allowsWrites(baseURL), 'changes settings; only on local sites or with E2E_ALLOW_WRITES=1');

		await page.goto(`${ADMIN_PAGE}&geweb_tab=prompts`);
		const state = await page.evaluate(() => ({
			nonce: String(globalThis.gewebAisearchAdmin?.adminActionNonce || ''),
			revision: String(document.getElementById('geweb_ai_search_group_revision')?.value || ''),
		}));
		expect(state.nonce, 'admin action nonce').not.toBe('');
		expect(state.revision, 'group revision field').not.toBe('');

		const first = await postAdminAjax(page, {
			action: 'geweb_clear_prompt_history',
			nonce: state.nonce,
			group_revision: state.revision,
		});
		expect(first.status).toBe(200);
		expect(first.body?.success).toBe(true);

		const stale = await postAdminAjax(page, {
			action: 'geweb_clear_prompt_history',
			nonce: state.nonce,
			group_revision: state.revision,
		});
		expect(stale.status, 'second save with the old revision').toBe(409);
		const current = String(stale.body?.data?.current_revision || '');
		expect(current).not.toBe('');

		const retry = await postAdminAjax(page, {
			action: 'geweb_clear_prompt_history',
			nonce: state.nonce,
			group_revision: current,
		});
		expect(retry.status).toBe(200);
		expect(retry.body?.success).toBe(true);
	});
});
