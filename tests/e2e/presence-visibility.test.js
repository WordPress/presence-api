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
import { test, expect } from '@wordpress/e2e-test-utils-playwright';

/**
 * Waits until the WordPress heartbeat library is ready on the page.
 *
 * @param {import('@playwright/test').Page} page
 */
function waitForHeartbeat( page ) {
	return page.waitForFunction(
		() => typeof wp !== 'undefined' && wp.heartbeat && wp.heartbeat.connectNow
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
 * @returns {Promise<object>}
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
