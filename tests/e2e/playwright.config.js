/**
 * External dependencies
 */
import path from 'node:path';
import { defineConfig, devices } from '@playwright/test';

const pluginRoot = path.resolve( __dirname, '../..' );
const artifactsPath =
	process.env.WP_ARTIFACTS_PATH ?? path.join( pluginRoot, 'artifacts' );

process.env.WP_ARTIFACTS_PATH ??= artifactsPath;

const baseUrl = new URL(
	process.env.WP_BASE_URL || 'http://localhost:8888'
);

process.env.WP_BASE_URL = baseUrl.href;

const config = defineConfig( {
	reporter: [ [ 'list' ] ],
	workers: 1,
	timeout: 600_000,
	reportSlowTests: null,
	testDir: '.',
	outputDir: path.join( artifactsPath, 'test-results' ),
	use: {
		baseURL: baseUrl.href,
		headless: false,
		viewport: null,
		ignoreHTTPSErrors: true,
		locale: 'en-US',
		contextOptions: {
			strictSelectors: true,
		},
		actionTimeout: 30_000,
		trace: 'retain-on-failure',
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
			use: {
				...devices[ 'Desktop Chrome' ],
				viewport: null,
				deviceScaleFactor: undefined,
				launchOptions: {
					args: [ '--window-size=1600,1100' ],
				},
			},
		},
	],
} );

export default config;
