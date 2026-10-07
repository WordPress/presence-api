/**
 * Presence API — Synced pattern notice E2E Tests
 *
 * Says on a synced pattern that someone else is editing it, through `wp.presence.usePresenceUsers()`.
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

test.describe( 'Synced pattern notice', () => {
	// wp-env activates Classic Editor.
	test.beforeAll( () => {
		wpEval( `update_option( 'classic-editor-replace', 'block' );` );
	} );

	test.afterEach( async ( { requestUtils } ) => {
		await requestUtils.deleteAllPosts();
		await requestUtils.deleteAllBlocks();
		await requestUtils.deleteAllUsers();
	} );

	test.afterAll( () => {
		wpEval( `delete_option( 'classic-editor-replace' );` );
	} );

	test( 'says who else is editing a synced pattern', async ( {
		admin,
		editor,
		requestUtils,
	} ) => {
		await requestUtils.createUser( COLLABORATOR );
		const pattern = await requestUtils.createBlock( {
			title: 'Newsletter signup',
			status: 'publish',
			content:
				'<!-- wp:paragraph --><p>Sign up for the weekly briefing.</p><!-- /wp:paragraph -->',
		} );
		const post = await requestUtils.createPost( {
			title: 'Weekend Arts Guide',
			status: 'draft',
			content: `<!-- wp:block {"ref":${ pattern.id }} /-->`,
		} );

		const browser = await chromium.launch( { headless: true } );

		try {
			const patternUrl = `${ BASE_URL }/wp-admin/post.php?post=${ pattern.id }&action=edit`;
			const context = await browser.newContext( { baseURL: BASE_URL } );
			await context.request.post( `${ BASE_URL }/wp-login.php`, {
				form: {
					log: COLLABORATOR.username,
					pwd: COLLABORATOR.password,
					redirect_to: patternUrl,
					testcookie: '1',
				},
			} );
			const collaboratorPage = await context.newPage();
			await collaboratorPage.goto( patternUrl );
			await collaboratorPage.waitForFunction(
				() => window.wp?.heartbeat?.connectNow
			);
			// Waits for the tick that writes the collaborator into the pattern's room.
			await collaboratorPage.evaluate(
				() =>
					new Promise( ( resolve ) => {
						jQuery( document ).one( 'heartbeat-tick', resolve );
						wp.heartbeat.connectNow();
					} )
			);

			await admin.editPost( post.id );
			await expect(
				editor.canvas.getByText(
					'Maria Lopez is editing this pattern.'
				)
			).toBeVisible();
		} finally {
			await browser.close();
		}
	} );
} );
