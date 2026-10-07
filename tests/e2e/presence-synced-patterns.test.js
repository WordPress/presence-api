/**
 * Presence API — Editors panel E2E Tests
 *
 * The block editor's Editors panel, the plugin's own consumer of `wp.presence.usePresenceUsers()`.
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

function wpEval( phpExpression ) {
	execSync(
		`npx wp-env run cli wp eval ${ JSON.stringify( phpExpression ) }`,
		{ stdio: 'pipe', timeout: 30_000 }
	);
}

test.describe( 'Editors panel', () => {
	// wp-env activates Classic Editor.
	test.beforeAll( () => {
		wpEval( `update_option( 'classic-editor-replace', 'block' );` );
	} );

	test.afterEach( async ( { requestUtils } ) => {
		await requestUtils.deleteAllPosts();
		await requestUtils.deleteAllUsers();
	} );

	test.afterAll( () => {
		wpEval( `delete_option( 'classic-editor-replace' );` );
	} );

	test( 'lists another editor of the post', async ( {
		admin,
		editor,
		page,
		requestUtils,
	} ) => {
		await requestUtils.createUser( COLLABORATOR );
		const post = await requestUtils.createPost( {
			title: 'Election night results',
			status: 'publish',
		} );

		await admin.editPost( post.id );
		// Panel state persists per user, so start from the editor's default of only Status open.
		await editor.setPreferences( 'core', {
			openPanels: [ 'post-status' ],
		} );
		await editor.openDocumentSettingsSidebar();
		const settings = page.getByRole( 'region', {
			name: 'Editor settings',
		} );
		await settings.getByRole( 'button', { name: 'Editors' } ).click();
		await expect(
			settings.getByText( 'No one else is editing.' )
		).toBeVisible();

		const browser = await chromium.launch( { headless: true } );

		try {
			const editUrl = `${ BASE_URL }/wp-admin/post.php?post=${ post.id }&action=edit`;
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

			await page.evaluate( () => wp.heartbeat.connectNow() );
			await expect(
				settings
					.getByRole( 'listitem' )
					.filter( { hasText: 'Maria Lopez' } )
			).toBeVisible();
		} finally {
			await browser.close();
		}
	} );
} );
