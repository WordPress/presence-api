/**
 * Presence API — Visibility E2E Tests
 *
 * Asserts the Page Visibility integration on the inline heartbeat
 * script: a hidden tab suppresses both presence-ping and
 * presence-editor-ping, and visibility restore triggers an immediate
 * heartbeat tick.
 *
 * Playwright headless browsers don't fire real visibilitychange when a
 * tab is backgrounded, so these tests stub `document.visibilityState`
 * via Object.defineProperty and dispatch the event manually.
 *
 * Run from plugin root:
 *   npx playwright test --config tests/e2e/playwright.config.js tests/e2e/presence-visibility.test.js
 *
 * @package WordPress
 * @since 7.1.0
 */
import { test as base, expect } from '@wordpress/e2e-test-utils-playwright';
import { chromium } from '@playwright/test';

const BASE_URL = ( process.env.WP_BASE_URL || 'http://localhost:8888' ).replace(
	/\/$/,
	''
);

const TEST_USERS = [
	{
		username: 'presencetestb',
		email: 'presencetestb@example.com',
		firstName: 'User',
		lastName: 'B',
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

/**
 * Waits until the WordPress heartbeat library is ready on the page.
 *
 * @param {import('@playwright/test').Page} page
 */
function waitForHeartbeat( page ) {
	return page.waitForFunction(
		() =>
			typeof wp !== 'undefined' && wp.heartbeat && wp.heartbeat.connectNow
	);
}

/**
 * Fakes document.visibilityState and fires a visibilitychange event.
 *
 * @param {import('@playwright/test').Page} page
 * @param {'hidden' | 'visible'}            state
 */
async function setVisibility( page, state ) {
	await page.evaluate( ( value ) => {
		Object.defineProperty( document, 'visibilityState', {
			configurable: true,
			get: () => value,
		} );
		document.dispatchEvent( new Event( 'visibilitychange' ) );
	}, state );
}

/**
 * Forces a heartbeat tick and resolves with the data object that
 * `heartbeat-send` listeners (including the plugin's) saw.
 *
 * @param {import('@playwright/test').Page} page
 * @return {Promise<object>} The recorded Heartbeat activity.
 */
function captureHeartbeatSend( page ) {
	return page.evaluate(
		() =>
			new Promise( ( resolve ) => {
				// The plugin's handler is registered first, so by the time this
				// one-shot listener fires the data object has already been
				// mutated (or skipped) as appropriate.
				jQuery( document ).one( 'heartbeat-send', ( event, data ) => {
					resolve( data );
				} );
				wp.heartbeat.connectNow();
			} )
	);
}

test.describe( 'Presence Visibility', () => {
	test.afterEach( async ( { requestUtils } ) => {
		await requestUtils.deleteAllPosts();
	} );

	test( 'heartbeat-send omits presence-ping while document is hidden', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( '/' );
		await waitForHeartbeat( page );

		await setVisibility( page, 'hidden' );
		const hidden = await captureHeartbeatSend( page );
		expect( hidden[ 'presence-ping' ] ).toBeUndefined();

		await setVisibility( page, 'visible' );
		const visible = await captureHeartbeatSend( page );
		expect( visible[ 'presence-ping' ] ).toBeDefined();
		expect( visible[ 'presence-ping' ].screen ).toBeTruthy();
	} );

	test( 'heartbeat-send omits presence-editor-ping while editor tab is hidden', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const post = await requestUtils.createPost( {
			title: 'E2E Visibility Editor Test',
			status: 'draft',
		} );

		await admin.visitAdminPage(
			'post.php',
			`post=${ post.id }&action=edit`
		);
		await waitForHeartbeat( page );

		await setVisibility( page, 'hidden' );
		const hidden = await captureHeartbeatSend( page );
		expect( hidden[ 'presence-editor-ping' ] ).toBeUndefined();

		await setVisibility( page, 'visible' );
		const visible = await captureHeartbeatSend( page );
		expect( visible[ 'presence-editor-ping' ] ).toBeDefined();
		expect( visible[ 'presence-editor-ping' ].post_id ).toBe( post.id );
	} );

	test( 'heartbeat-send omits wp-refresh-post-lock while editor tab is hidden', async ( {
		admin,
		page,
		requestUtils,
		testUsers,
	} ) => {
		const post = await requestUtils.createPost( {
			title: 'E2E Visibility Editor Post Lock Test',
			status: 'draft',
		} );

		// User A opens the post editor to create the initial lock.
		await admin.visitAdminPage(
			'post.php',
			`post=${ post.id }&action=edit`
		);
		await waitForHeartbeat( page );

		// User B opens the same post editor in a new browser context.
		const headlessBrowser = await chromium.launch( { headless: true } );

		try {
			const userB = await loginHeadlessUser(
				headlessBrowser,
				testUsers[ 0 ],
				`${ BASE_URL }/wp-admin/post.php?post=${ post.id }&action=edit`
			);

			// User B clicks "Take over" to become the active lock holder.
			const takeOverButton = userB.page.locator(
				'a:has-text("Take over")'
			);
			await takeOverButton.click();

			// Wait for heartbeat library on User B's page.
			await waitForHeartbeat( userB.page );

			// Verify that wp-refresh-post-lock is present when visible initially.
			const initial = await captureHeartbeatSend( userB.page );
			expect( initial[ 'wp-refresh-post-lock' ] ).toBeDefined();
			expect( initial[ 'wp-refresh-post-lock' ].post_id ).toBe(
				String( post.id )
			);

			// Verify that wp-refresh-post-lock is deleted when page is hidden.
			await setVisibility( userB.page, 'hidden' );
			const hidden = await captureHeartbeatSend( userB.page );
			expect( hidden[ 'wp-refresh-post-lock' ] ).toBeUndefined();

			// Restore visibility to User B's page and assert it is sent again.
			await setVisibility( userB.page, 'visible' );
			const visible = await captureHeartbeatSend( userB.page );
			expect( visible[ 'wp-refresh-post-lock' ] ).toBeDefined();

			await userB.context.close();
		} finally {
			await headlessBrowser.close();
		}
	} );

	test( 'visibility restore triggers an immediate heartbeat tick', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( '/' );
		await waitForHeartbeat( page );

		// Go hidden first so the next 'visible' event has something to do.
		await setVisibility( page, 'hidden' );

		// Wrap connectNow to count invocations and snapshot the counter
		// immediately before flipping visible, so we measure exactly the
		// delta caused by our visibilitychange handler.
		const before = await page.evaluate( () => {
			window.__connectNowCalls = window.__connectNowCalls || 0;
			if ( ! window.__connectNowWrapped ) {
				const original = wp.heartbeat.connectNow.bind( wp.heartbeat );
				wp.heartbeat.connectNow = function () {
					window.__connectNowCalls++;
					return original();
				};
				window.__connectNowWrapped = true;
			}
			return window.__connectNowCalls;
		} );

		await setVisibility( page, 'visible' );

		await page.waitForFunction(
			( baseline ) => window.__connectNowCalls > baseline,
			before,
			{ timeout: 5000 }
		);
		const after = await page.evaluate( () => window.__connectNowCalls );
		expect( after - before ).toBeGreaterThanOrEqual( 1 );
	} );
} );
