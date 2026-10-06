/**
 * Presence API — usePresenceUsers E2E Tests
 *
 * Loads `wp.presence.usePresenceUsers()` in the block editor from a must-use plugin that depends on `wp-presence`.
 *
 * @package Presence_API
 */
import { test, expect } from '@wordpress/e2e-test-utils-playwright';
import { chromium } from '@playwright/test';
import { execSync } from 'node:child_process';

const BASE_URL = ( process.env.WP_BASE_URL || 'http://localhost:8888' ).replace(
	/\/$/,
	''
);

const COLLABORATOR = {
	username: 'presencetestusers',
	email: 'presencetestusers@example.com',
	firstName: 'Maria',
	lastName: 'Lopez',
	password: 'password',
	roles: [ 'editor' ],
};

const MU_PLUGIN = 'presence-users-hook-plugin.php';

function wpEval( phpExpression ) {
	execSync(
		`npx wp-env run cli wp eval ${ JSON.stringify( phpExpression ) }`,
		{ stdio: 'pipe', timeout: 30_000 }
	);
}

test.describe( 'usePresenceUsers', () => {
	// wp-env maps the repository to ABSPATH/presence-api and activates Classic Editor.
	test.beforeAll( () => {
		wpEval(
			`wp_mkdir_p( WPMU_PLUGIN_DIR ); copy( ABSPATH . 'presence-api/tests/e2e/${ MU_PLUGIN }', WPMU_PLUGIN_DIR . '/${ MU_PLUGIN }' ); update_option( 'classic-editor-replace', 'block' );`
		);
	} );

	test.afterEach( async ( { requestUtils } ) => {
		await requestUtils.deleteAllPosts();
		await requestUtils.deleteAllUsers();
	} );

	test.afterAll( () => {
		wpEval(
			`wp_delete_file( WPMU_PLUGIN_DIR . '/${ MU_PLUGIN }' ); delete_option( 'classic-editor-replace' );`
		);
	} );

	test( 'lists another editor of the post', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const collaborator = await requestUtils.createUser( COLLABORATOR );
		const post = await requestUtils.createPost( {
			title: 'Election night results',
			status: 'publish',
		} );
		const editUrl = `${ BASE_URL }/wp-admin/post.php?post=${ post.id }&action=edit`;

		const browser = await chromium.launch( { headless: true } );

		try {
			const context = await browser.newContext( { baseURL: BASE_URL } );
			await context.request.post( `${ BASE_URL }/wp-login.php`, {
				form: {
					log: COLLABORATOR.username,
					pwd: COLLABORATOR.password,
					redirect_to: editUrl,
					testcookie: '1',
				},
			} );
			const collaboratorPage = await context.newPage();
			await collaboratorPage.goto( editUrl );
			await collaboratorPage.waitForFunction(
				() => window.wp?.heartbeat?.connectNow
			);
			// Waits for the tick that writes the collaborator into the post room.
			await collaboratorPage.evaluate(
				() =>
					new Promise( ( resolve ) => {
						jQuery( document ).one( 'heartbeat-tick', resolve );
						wp.heartbeat.connectNow();
					} )
			);

			await admin.visitAdminPage(
				'post.php',
				`post=${ post.id }&action=edit`
			);

			const output = page.locator( '#presence-users-hook-consumer' );
			await expect( output ).toHaveAttribute( 'data-loading', 'false' );
			await expect( output ).toHaveText( String( collaborator.id ) );
		} finally {
			await browser.close();
		}
	} );
} );
