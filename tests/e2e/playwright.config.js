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

process.env.MULTISITE_STORAGE_STATE_PATH ??= path.join(
	process.env.WP_ARTIFACTS_PATH,
	'storage-states/admin-multisite.json'
);

const baseUrl = new URL( process.env.WP_BASE_URL || 'http://localhost:8888' );

process.env.WP_BASE_URL = baseUrl.href;

const multisiteBaseUrl = new URL(
	process.env.WP_MULTISITE_BASE_URL || 'http://localhost:8890'
);

process.env.WP_MULTISITE_BASE_URL = multisiteBaseUrl.href;

/**
 * The Network Admin specs, which are the only ones that need a network.
 *
 * Split by filename rather than by directory so every spec stays in one place
 * next to the single-site ones it mirrors.
 *
 * @type {RegExp}
 */
const NETWORK_SPECS = /presence-network-[^/]+\.test\.js$/;

const PROJECTS = [ 'chromium', 'chromium-multisite' ];

/**
 * Reads `--project` from argv, which Playwright does not pass to the config or global setup.
 *
 * @return {string[]} Project names.
 */
function selectedProjects() {
	const names = [];
	const args = process.argv.slice( 2 );

	for ( let i = 0; i < args.length; i++ ) {
		if ( args[ i ].startsWith( '--project=' ) ) {
			names.push( args[ i ].slice( '--project='.length ) );
		} else if ( args[ i ] === '--project' ) {
			while ( args[ i + 1 ] && ! args[ i + 1 ].startsWith( '-' ) ) {
				names.push( args[ ++i ] );
			}
		}
	}

	const matched = PROJECTS.filter( ( name ) =>
		names.some( ( n ) => n.toLowerCase() === name )
	);

	return names.length && matched.length === names.length ? matched : PROJECTS;
}

const selected = selectedProjects();

export default defineConfig( {
	globalSetup: path.resolve( __dirname, 'global-setup.js' ),
	metadata: { projects: selected },
	reporter: process.env.CI ? [ [ 'github' ] ] : [ [ 'list' ] ],
	retries: process.env.CI ? 2 : 0,
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
	webServer: [
		selected.includes( 'chromium' ) && {
			command: 'npm run env:start',
			port: parseInt( baseUrl.port, 10 ),
			timeout: 120_000,
			reuseExistingServer: true,
		},
		selected.includes( 'chromium-multisite' ) && {
			// Seeds the fixture network as well as starting it, so a first run
			// on a cold machine takes longer than the single-site instance.
			command: 'npm run env:start:multisite',
			port: parseInt( multisiteBaseUrl.port, 10 ),
			timeout: 300_000,
			reuseExistingServer: true,
		},
	].filter( Boolean ),
	projects: [
		{
			name: 'chromium',
			testIgnore: NETWORK_SPECS,
			use: { ...devices[ 'Desktop Chrome' ] },
		},
		{
			// A second wp-env instance rather than a second site on the first
			// one: see scripts/start-multisite-env.sh for why the network
			// cannot share the environment the other specs run against.
			name: 'chromium-multisite',
			testMatch: NETWORK_SPECS,
			use: {
				...devices[ 'Desktop Chrome' ],
				baseURL: multisiteBaseUrl.href,
				storageState: process.env.MULTISITE_STORAGE_STATE_PATH,
			},
		},
	],
} );
