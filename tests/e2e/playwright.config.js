// Browser tests for the AI workspace and admin screens.
//
// Default target: the local wp-env site (npm run e2e:env:start).
// Another site: set WP_BASE_URL, WP_USERNAME and WP_PASSWORD (see tests/e2e/README.md).
const path = require('path');
const { defineConfig, devices } = require('@playwright/test');

const baseURL = process.env.WP_BASE_URL || 'http://localhost:8890';
const authFile = path.join(__dirname, '.auth', 'admin.json');
// E2E_BROWSER_CHANNEL=chrome uses the installed Google Chrome instead of
// Playwright's own Chromium.
const channel = process.env.E2E_BROWSER_CHANNEL || undefined;

const { defaultBrowserType: _ipadBrowser, ...ipad } = devices['iPad Pro 11'];

const signedIn = (use) => ({ browserName: 'chromium', channel, storageState: authFile, ...use });

const mobile = (width, height) => signedIn({
	viewport: { width, height },
	isMobile: true,
	hasTouch: true,
	deviceScaleFactor: 2.6,
});

module.exports = defineConfig({
	testDir: __dirname,
	outputDir: path.join(__dirname, '..', '..', 'test-results', 'e2e'),
	timeout: 60_000,
	expect: { timeout: 15_000 },
	fullyParallel: false,
	workers: 1,
	retries: process.env.CI ? 1 : 0,
	reporter: process.env.CI
		? [['list'], ['html', { open: 'never', outputFolder: path.join(__dirname, '..', '..', 'playwright-report') }]]
		: [['list']],
	use: {
		baseURL,
		headless: process.env.PW_HEADLESS !== 'false',
		screenshot: 'only-on-failure',
		trace: 'retain-on-failure',
		// Video needs Playwright's ffmpeg (npx playwright install ffmpeg); on in CI.
		video: process.env.CI || process.env.E2E_VIDEO ? 'retain-on-failure' : 'off',
		launchOptions: { slowMo: Number(process.env.PW_SLOW_MO || 0) },
	},
	projects: [
		{ name: 'setup', testMatch: /auth\.setup\.js/, use: { browserName: 'chromium', channel } },
		{
			name: 'desktop',
			dependencies: ['setup'],
			use: signedIn({ viewport: { width: 1440, height: 900 } }),
		},
		{
			name: 'narrow-desktop',
			dependencies: ['setup'],
			grep: /@layout/,
			use: signedIn({ viewport: { width: 412, height: 925 } }),
		},
		{ name: 'mobile', dependencies: ['setup'], grep: /@layout/, use: mobile(412, 925) },
		{ name: 'mobile-landscape', dependencies: ['setup'], grep: /@layout/, use: mobile(925, 412) },
		{ name: 'tablet', dependencies: ['setup'], grep: /@layout/, use: signedIn(ipad) },
	],
});
