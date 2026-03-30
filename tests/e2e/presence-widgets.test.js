/**
 * Presence API — Automated Widget E2E Tests
 *
 * Tests multi-user presence scenarios across both dashboard widgets:
 * Who's Online and Active Posts.
 *
 * Run from plugin root:
 *   npx playwright test --config tests/e2e/playwright.config.js presence-widgets.test.js
 *
 * @package WordPress
 * @since 7.1.0
 */

/**
 * External dependencies
 */
import { test as base, expect } from '@wordpress/e2e-test-utils-playwright';
import { chromium } from '@playwright/test';

const BASE_URL = process.env.WP_BASE_URL || 'http://localhost:8888';

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
	/**
	 * Create test users before each test.
	 */
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
 * Helper: log in a user on a headless page and navigate to a URL.
 *
 * @param {Object} headlessBrowser Playwright browser instance.
 * @param {Object} user            User credentials object.
 * @param {string} destinationUrl  URL to navigate to after login.
 * @return {Object} Object with context and page.
 */
async function loginHeadlessUser( headlessBrowser, user, destinationUrl ) {
	const context = await headlessBrowser.newContext( {
		baseURL: BASE_URL,
		ignoreHTTPSErrors: true,
	} );
	const userPage = await context.newPage();

	await userPage.goto( '/wp-login.php' );
	await expect( async () => {
		await userPage.locator( '#user_login' ).fill( user.username );
		await userPage.locator( '#user_pass' ).fill( user.password );
		await expect( userPage.locator( '#user_pass' ) ).toHaveValue(
			user.password
		);
	} ).toPass( { timeout: 15_000 } );

	await userPage.getByRole( 'button', { name: 'Log In' } ).click();
	await userPage.waitForURL( '**/wp-admin/**' );

	if ( destinationUrl ) {
		await userPage.goto( destinationUrl );
	}

	/* Fire an immediate heartbeat so the server records presence. */
	await userPage.evaluate( () => wp.heartbeat.connectNow() );

	return { context, page: userPage };
}

test.describe( 'Presence Widgets', () => {
	test( 'User B appears in Who\'s Online widget for User A', async ( {
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

			/* Trigger admin heartbeat to pick up the new user. */
			await page.evaluate( () => wp.heartbeat.connectNow() );

			/* Wait for the widget to show User B. */
			const whosList = page.locator( '#presence-whos-online-list' );
			await expect( whosList ).toContainText( testUsers[ 0 ].lastName, {
				timeout: 30_000,
			} );

			await userB.context.close();
		} finally {
			await headlessBrowser.close();
		}
	} );

	test( 'User B editing a post appears in Active Posts widget', async ( {
		admin,
		page,
		requestUtils,
		testUsers,
	} ) => {
		/* Create a test post. */
		const post = await requestUtils.createPost( {
			title: 'E2E Presence Test Post',
			status: 'draft',
		} );

		await admin.visitAdminPage( '/' );
		await page.evaluate( () => wp.heartbeat.connectNow() );

		const headlessBrowser = await chromium.launch( { headless: true } );

		try {
			const editUrl = `${ BASE_URL }/wp-admin/post.php?post=${ post.id }&action=edit`;
			const userB = await loginHeadlessUser(
				headlessBrowser,
				testUsers[ 0 ],
				editUrl
			);

			/* Trigger admin heartbeat. */
			await page.evaluate( () => wp.heartbeat.connectNow() );

			/* Wait for the Active Posts widget to show the post. */
			const activePostsList = page.locator(
				'#presence-active-posts-list'
			);
			await expect( activePostsList ).toContainText(
				'E2E Presence Test Post',
				{ timeout: 30_000 }
			);

			await userB.context.close();
		} finally {
			await headlessBrowser.close();
		}
	} );

	test( 'User C logging out disappears from widgets', async ( {
		admin,
		page,
		testUsers,
	} ) => {
		await admin.visitAdminPage( '/' );
		await page.evaluate( () => wp.heartbeat.connectNow() );

		const headlessBrowser = await chromium.launch( { headless: true } );

		try {
			const userC = await loginHeadlessUser(
				headlessBrowser,
				testUsers[ 1 ],
				`${ BASE_URL }/wp-admin/`
			);

			/* Verify User C appears first. */
			await page.evaluate( () => wp.heartbeat.connectNow() );

			const whosList = page.locator( '#presence-whos-online-list' );
			await expect( whosList ).toContainText( testUsers[ 1 ].lastName, {
				timeout: 30_000,
			} );

			/* Log out User C via wp-login.php?action=logout. */
			await userC.page.goto(
				`${ BASE_URL }/wp-login.php?action=logout`
			);

			/* Click the confirmation link if present. */
			const logoutLink = userC.page.locator( 'a[href*="action=logout"]' );
			if ( await logoutLink.isVisible( { timeout: 5000 } ).catch( () => false ) ) {
				await logoutLink.click();
			}

			/* Wait for presence TTL to expire and trigger admin heartbeat. */
			await page.waitForTimeout( 3000 );
			await page.evaluate( () => wp.heartbeat.connectNow() );

			/*
			 * After logout + heartbeat, User C should no longer appear.
			 * The wp_presence_on_logout hook clears their entries immediately.
			 */
			await expect( async () => {
				await page.evaluate( () => wp.heartbeat.connectNow() );
				const text = await whosList.textContent();
				expect( text ).not.toContain( testUsers[ 1 ].lastName );
			} ).toPass( { timeout: 30_000 } );

			await userC.context.close();
		} finally {
			await headlessBrowser.close();
		}
	} );

	test( 'Multiple users editing different posts simultaneously', async ( {
		admin,
		page,
		requestUtils,
		testUsers,
	} ) => {
		/* Create two test posts. */
		const post1 = await requestUtils.createPost( {
			title: 'E2E Post Alpha',
			status: 'draft',
		} );
		const post2 = await requestUtils.createPost( {
			title: 'E2E Post Beta',
			status: 'draft',
		} );

		await admin.visitAdminPage( '/' );
		await page.evaluate( () => wp.heartbeat.connectNow() );

		const headlessBrowser = await chromium.launch( { headless: true } );

		try {
			const userB = await loginHeadlessUser(
				headlessBrowser,
				testUsers[ 0 ],
				`${ BASE_URL }/wp-admin/post.php?post=${ post1.id }&action=edit`
			);
			const userC = await loginHeadlessUser(
				headlessBrowser,
				testUsers[ 1 ],
				`${ BASE_URL }/wp-admin/post.php?post=${ post2.id }&action=edit`
			);

			/* Trigger admin heartbeat. */
			await page.evaluate( () => wp.heartbeat.connectNow() );

			/* Both posts should appear in the Active Posts widget. */
			const activePostsList = page.locator(
				'#presence-active-posts-list'
			);
			await expect( activePostsList ).toContainText( 'E2E Post Alpha', {
				timeout: 30_000,
			} );
			await expect( activePostsList ).toContainText( 'E2E Post Beta' );

			await userB.context.close();
			await userC.context.close();
		} finally {
			await headlessBrowser.close();
		}
	} );
} );
