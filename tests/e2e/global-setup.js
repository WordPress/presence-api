/**
 * External dependencies
 */
import fs from 'fs/promises';
import path from 'node:path';

/**
 * WordPress dependencies
 */
import { RequestUtils } from '@wordpress/e2e-test-utils-playwright';

/**
 * Global setup — authenticates admin and saves storage state.
 *
 * @param {import('@playwright/test').FullConfig} config
 * @returns {Promise<void>}
 */
async function globalSetup( config ) {
	const { storageState, baseURL } = config.projects[ 0 ].use;
	const storageStatePath =
		typeof storageState === 'string' ? storageState : undefined;

	// Don't pass storageStatePath to setup() — the library writes a custom
	// format ({ cookies, nonce, rootURL }) that Playwright can't consume.
	// We'll write the Playwright-compatible format ourselves below.
	const requestUtils = await RequestUtils.setup( {
		baseURL,
		user: {
			username: 'admin',
			password: 'password',
		},
	} );

	// Write Playwright-compatible storage state ({ cookies, origins }).
	if ( storageStatePath ) {
		const { cookies } = await requestUtils.request.storageState();
		await fs.mkdir( path.dirname( storageStatePath ), {
			recursive: true,
		} );
		await fs.writeFile(
			storageStatePath,
			JSON.stringify( { cookies, origins: [] } )
		);
	}

	await requestUtils.request.dispose();
}

export default globalSetup;
