/**
 * Presence API — Widget E2E Tests
 *
 * Tests multi-user presence scenarios across dashboard widgets.
 *
 * Run from plugin root:
 *   npx playwright test --config tests/e2e/playwright.config.js
 *
 * @package WordPress
 * @since 7.1.0
 */
import { test as base, expect } from '@wordpress/e2e-test-utils-playwright';
import { chromium } from '@playwright/test';
import { execSync } from 'node:child_process';

const BASE_URL = process.env.WP_BASE_URL || 'http://localhost:8888';

function wpCli( command ) {
	execSync( `npx wp-env run cli wp ${ command }`, {
		stdio: 'pipe',
		timeout: 30_000,
	} );
}

const TEST_USERS = [
	{
		username: 'presence_test_b',
		email: 'presence_test_b@example.com',
		firstName: 'User',
		lastName: 'B',
		password: 'password',
		roles: [ 'editor' ],
	},
	{
		username: 'presence_test_c',
		email: 'presence_test_c@example.com',
		firstName: 'User',
		lastName: 'C',
		password: 'password',
		roles: [ 'editor' ],
	},
];

const test = base.extend( {
	testUsers: [
		async ( { requestUtils }, use ) => {
			for ( const user of TEST_USERS ) {
				await requestUtils.createUser( user ).catch( ( error ) => {
					if ( error?.code !== 'existing_user_login' ) {
						throw error;
					}
				} );
			}
			await use( TEST_USERS );
			await requestUtils.deleteAllUsers();
		},
		{ scope: 'test' },
	],
} );

/**
 * Log in a user on a headless browser and navigate to a URL.
 *
 * Uses request-based auth (POST to wp-login.php) to avoid
 * form interaction issues with WordPress 7.0's login page.
 */
async function loginHeadlessUser( headlessBrowser, user, destinationUrl ) {
	const context = await headlessBrowser.newContext( {
		baseURL: BASE_URL,
		ignoreHTTPSErrors: true,
	} );

	// Authenticate via POST request to set cookies on the context.
	await context.request.post( `${ BASE_URL }/wp-login.php`, {
		form: {
			log: user.username,
			pwd: user.password,
			'wp-submit': 'Log In',
			redirect_to: destinationUrl || `${ BASE_URL }/wp-admin/`,
			testcookie: '1',
		},
	} );

	const userPage = await context.newPage();
	await userPage.goto( destinationUrl || `${ BASE_URL }/wp-admin/` );
	await userPage.waitForLoadState( 'networkidle' );

	await userPage.evaluate( () => {
		if ( typeof wp !== 'undefined' && wp.heartbeat ) {
			wp.heartbeat.connectNow();
		}
	} );

	return { context, page: userPage };
}

test.describe( 'Presence Widgets', () => {
	test( 'User B appears in Who\'s Online widget', async ( {
		admin,
		page,
		testUsers,
	} ) => {
		await admin.visitAdminPage( '/' );
		await page.evaluate( () => wp.heartbeat.connectNow() );

		const headlessBrowser = await chromium.launch( { headless: true } );

		try {
			const userB = await loginHeadlessUser(
				headlessBrowser,
				testUsers[ 0 ],
				`${ BASE_URL }/wp-admin/`
			);

			await page.evaluate( () => wp.heartbeat.connectNow() );

			const whosList = page.locator( '#presence-whos-online-list' );
			await expect( whosList ).toContainText( testUsers[ 0 ].lastName, {
				timeout: 30_000,
			} );

			await userB.context.close();
		} finally {
			await headlessBrowser.close();
		}
	} );

	test( 'Post editing presence appears in Active Posts widget', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const post = await requestUtils.createPost( {
			title: 'E2E Presence Test Post',
			status: 'draft',
		} );

		// Seed a presence entry for the post via wp eval (CLI --user flag collides with WP-CLI global).
		wpCli( `eval 'wp_set_presence( "postType/post:${ post.id }", "editor-1", array( "action" => "editing", "screen" => "post" ), 1 );'` );

		await admin.visitAdminPage( '/' );
		await page.evaluate( () => wp.heartbeat.connectNow() );
		await page.waitForTimeout( 3000 );

		const activePostsList = page.locator( '#presence-active-posts-list' );
		await expect( activePostsList ).toContainText(
			'E2E Presence Test Post',
			{ timeout: 30_000 }
		);
	} );
} );
