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
	test( 'heartbeat-send omits presence-ping while document is hidden', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( '/' );
		// Warm up the heartbeat machinery so subsequent connectNow() calls
		// route through our handler.
		await page.evaluate( () => wp.heartbeat.connectNow() );
		await page.waitForTimeout( 1000 );

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
		await page.evaluate( () => wp.heartbeat.connectNow() );
		await page.waitForTimeout( 1000 );

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
		await page.evaluate( () => wp.heartbeat.connectNow() );
		await page.waitForTimeout( 1000 );

		// Go hidden first so the next 'visible' event has something to do.
		await setVisibility( page, 'hidden' );

		// Wrap connectNow to count invocations from this point forward.
		await page.evaluate( () => {
			window.__connectNowCalls = 0;
			const original = wp.heartbeat.connectNow.bind( wp.heartbeat );
			wp.heartbeat.connectNow = function () {
				window.__connectNowCalls++;
				return original();
			};
		} );

		await setVisibility( page, 'visible' );

		await page.waitForFunction(
			() => window.__connectNowCalls > 0,
			null,
			{ timeout: 5000 }
		);
		const calls = await page.evaluate( () => window.__connectNowCalls );
		expect( calls ).toBeGreaterThan( 0 );
	} );
} );
