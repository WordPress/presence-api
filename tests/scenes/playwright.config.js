/**
 * Playwright configuration for scenes, which play in real time and so run apart from the e2e specs.
 *
 * @see https://github.com/WordPress/gutenberg/blob/trunk/test/performance/playwright.config.ts
 */
import { defineConfig, devices } from '@playwright/test';
import baseConfig from '../e2e/playwright.config.js';

export default defineConfig( {
	...baseConfig,
	testDir: '.',
	projects: [
		{
			name: 'chromium',
			use: { ...devices[ 'Desktop Chrome' ] },
		},
	],
	webServer: baseConfig.webServer.filter(
		( server ) => server.command === 'npm run env:start'
	),
} );
