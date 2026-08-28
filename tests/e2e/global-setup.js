/**
 * Global setup — authenticates admin and saves storage state.
 *
 * Follows WordPress core's pattern: uses Playwright's request API
 * (no browser) to authenticate, then writes cookies to disk so
 * all tests start already logged in.
 *
 * Runs once per storage state rather than once overall: the network specs run
 * against their own wp-env instance on another port, which is a separate
 * WordPress install with its own session cookies.
 *
 * @see https://github.com/WordPress/wordpress-develop/blob/trunk/tests/e2e/config/global-setup.ts
 */
import { request } from '@playwright/test';
import { RequestUtils } from '@wordpress/e2e-test-utils-playwright';

/**
 * @param {import('@playwright/test').FullConfig} config
 * @returns {Promise<void>}
 */
async function globalSetup( config ) {
	const authenticated = new Set();

	for ( const project of config.projects ) {
		const { storageState, baseURL } = project.use;
		const storageStatePath =
			typeof storageState === 'string' ? storageState : undefined;

		if ( ! storageStatePath || authenticated.has( storageStatePath ) ) {
			continue;
		}

		authenticated.add( storageStatePath );

		const requestContext = await request.newContext( {
			baseURL,
		} );

		const requestUtils = new RequestUtils( requestContext, {
			storageStatePath,
		} );

		// Authenticate and save the storageState to disk.
		await requestUtils.setupRest();

		await requestContext.dispose();
	}
}

export default globalSetup;
