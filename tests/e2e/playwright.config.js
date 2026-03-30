/**
 * Playwright configuration following WordPress core patterns.
 *
 * @see https://github.com/WordPress/wordpress-develop/blob/trunk/tests/e2e/playwright.config.ts
 */
import path from 'node:path';
import { defineConfig, devices } from '@playwright/test';

const pluginRoot = path.resolve( __dirname, '../..' );

process.env.WP_ARTIFACTS_PATH ??= path.join( pluginRoot, 'artifacts' );
process.env.STORAGE_STATE_PATH ??= path.join(
	process.env.WP_ARTIFACTS_PATH,
	'storage-states/admin.json'
);

const baseUrl = new URL(
	process.env.WP_BASE_URL || 'http://localhost:8888'
);

process.env.WP_BASE_URL = baseUrl.href;

export default defineConfig( {
	globalSetup: path.resolve( __dirname, 'global-setup.js' ),
	reporter: process.env.CI ? [ [ 'github' ] ] : [ [ 'list' ] ],
	workers: 1,
	timeout: 100_000,
	reportSlowTests: null,
	testDir: '.',
	outputDir: path.join( process.env.WP_ARTIFACTS_PATH, 'test-results' ),
	use: {
		baseURL: baseUrl.href,
		headless: true,
		viewport: { width: 1440, height: 900 },
		ignoreHTTPSErrors: true,
		locale: 'en-US',
		contextOptions: {
			reducedMotion: 'reduce',
			strictSelectors: true,
		},
		storageState: process.env.STORAGE_STATE_PATH,
		actionTimeout: 10_000,
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
	},
	webServer: {
		command: 'npm run env:start',
		port: parseInt( baseUrl.port, 10 ),
		timeout: 120_000,
		reuseExistingServer: true,
	},
	projects: [
		{
			name: 'chromium',
			use: { ...devices[ 'Desktop Chrome' ] },
		},
	],
} );
