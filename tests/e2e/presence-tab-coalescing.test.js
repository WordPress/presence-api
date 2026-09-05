/**
 * Presence API — Cross-Tab Polling Coalescing E2E Tests
 *
 * Asserts presence-ping.js elects one tab per screen/post context to send
 * presence-ping, siblings stay silent, closing the leader promotes a
 * sibling, and the leader's Who's Online response relays over
 * BroadcastChannel.
 *
 * Uses two pages in one browser context, not two Playwright contexts —
 * Web Locks and BroadcastChannel are scoped per storage partition, so
 * separate contexts would never see each other.
 *
 * Run from plugin root:
 *   npx playwright test --config tests/e2e/playwright.config.js tests/e2e/presence-tab-coalescing.test.js
 *
 * @package WordPress
 */
import { test, expect } from '@wordpress/e2e-test-utils-playwright';

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
 * Forces a heartbeat tick and resolves with the data object that
 * `heartbeat-send` listeners saw.
 *
 * @param {import('@playwright/test').Page} page
 * @return {Promise<object>} The recorded Heartbeat activity.
 */
function captureHeartbeatSend( page ) {
	return page.evaluate(
		() =>
			new Promise( ( resolve ) => {
				jQuery( document ).one( 'heartbeat-send', ( event, data ) => {
					resolve( data );
				} );
				wp.heartbeat.connectNow();
			} )
	);
}

/**
 * Retries a forced heartbeat tick until this tab wins Web Locks leadership
 * and sends presence-ping, or gives up.
 *
 * @param {import('@playwright/test').Page} page
 * @param {number}                          maxAttempts
 * @return {Promise<object>} The `heartbeat-send` data from the winning attempt.
 */
async function waitForLeaderPing( page, maxAttempts = 20 ) {
	let winningData;

	await expect
		.poll(
			async () => {
				winningData = await captureHeartbeatSend( page );
				return Boolean( winningData[ 'presence-ping' ] );
			},
			{
				intervals: [ 0 ],
				timeout: maxAttempts * 100,
				message: 'Tab never won presence-ping leadership.',
			}
		)
		.toBe( true );

	return winningData;
}

/**
 * Waits for a heartbeat-tick carrying Who's Online data, ignoring any
 * unrelated ticks that arrive first (e.g. this tab's own post-lock tick).
 *
 * @param {import('@playwright/test').Page} page
 * @param {number}                          timeoutMs
 * @return {Promise<object>} The recorded Heartbeat activity.
 */
function waitForOnlineDataTick( page, timeoutMs = 8000 ) {
	return page.evaluate( ( timeout ) => {
		return new Promise( ( resolve, reject ) => {
			const timer = setTimeout( () => {
				jQuery( document ).off( 'heartbeat-tick', handler );
				reject( new Error( 'Timed out waiting for a relayed tick.' ) );
			}, timeout );

			function handler( event, data ) {
				if (
					data &&
					( data[ 'presence-online' ] ||
						data[ 'presence-online-unchanged' ] )
				) {
					clearTimeout( timer );
					jQuery( document ).off( 'heartbeat-tick', handler );
					resolve( data );
				}
			}

			jQuery( document ).on( 'heartbeat-tick', handler );
		} );
	}, timeoutMs );
}

test.describe( 'Presence Tab Coalescing', () => {
	test.afterEach( async ( { requestUtils } ) => {
		await requestUtils.deleteAllPosts();
	} );

	test( 'elects one tab to send presence-ping; a sibling tab on the same screen stays silent', async ( {
		page,
	} ) => {
		await page.goto( '/wp-admin/' );
		await waitForHeartbeat( page );

		// Let this tab win leadership before the sibling opens, so the
		// outcome is deterministic instead of racing two tabs.
		const leaderData = await waitForLeaderPing( page );
		expect( leaderData[ 'presence-ping' ].screen ).toBeTruthy();

		const sibling = await page.context().newPage();
		await sibling.goto( '/wp-admin/' );
		await waitForHeartbeat( sibling );

		const siblingData = await captureHeartbeatSend( sibling );
		expect( siblingData[ 'presence-ping' ] ).toBeUndefined();

		await sibling.close();
	} );

	test( 'promotes the sibling tab once the leader tab closes', async ( {
		page,
	} ) => {
		await page.goto( '/wp-admin/' );
		await waitForHeartbeat( page );
		await waitForLeaderPing( page );

		const sibling = await page.context().newPage();
		await sibling.goto( '/wp-admin/' );
		await waitForHeartbeat( sibling );

		const beforeClose = await captureHeartbeatSend( sibling );
		expect( beforeClose[ 'presence-ping' ] ).toBeUndefined();

		await page.close();

		const promoted = await waitForLeaderPing( sibling );
		expect( promoted[ 'presence-ping' ].screen ).toBeTruthy();

		await sibling.close();
	} );

	test( "relays the leader's Who's Online data to a sibling tab", async ( {
		page,
	} ) => {
		await page.goto( '/wp-admin/' );
		await waitForHeartbeat( page );
		await waitForLeaderPing( page );

		const sibling = await page.context().newPage();
		await sibling.goto( '/wp-admin/' );
		await waitForHeartbeat( sibling );

		const [ relayed ] = await Promise.all( [
			waitForOnlineDataTick( sibling ),
			page.evaluate( () => wp.heartbeat.connectNow() ),
		] );

		expect(
			relayed[ 'presence-online' ] ||
				relayed[ 'presence-online-unchanged' ]
		).toBeTruthy();

		await sibling.close();
	} );

	test( 'tabs editing different posts are never coalesced with each other', async ( {
		page,
		requestUtils,
	} ) => {
		const postA = await requestUtils.createPost( {
			title: 'Tab Coalescing Post A',
			status: 'draft',
		} );
		const postB = await requestUtils.createPost( {
			title: 'Tab Coalescing Post B',
			status: 'draft',
		} );

		await page.goto( `/wp-admin/post.php?post=${ postA.id }&action=edit` );
		await waitForHeartbeat( page );
		const dataA = await waitForLeaderPing( page );
		expect( dataA[ 'presence-editor-ping' ].post_id ).toBe( postA.id );

		const pageB = await page.context().newPage();
		await pageB.goto( `/wp-admin/post.php?post=${ postB.id }&action=edit` );
		await waitForHeartbeat( pageB );

		// A different post is a different context, so this tab must win
		// its own leadership rather than being silenced as a false sibling.
		const dataB = await waitForLeaderPing( pageB );
		expect( dataB[ 'presence-editor-ping' ].post_id ).toBe( postB.id );

		await pageB.close();
	} );
} );
