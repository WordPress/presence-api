/**
 * Presence API — Active Posts Cross-Tab Coalescing E2E Tests
 *
 * Asserts active-posts.js elects one tab to send
 * presence-active-posts-ping, siblings stay silent, closing the leader
 * promotes a sibling, and the leader's presence-active-posts relays to
 * siblings over BroadcastChannel. Unlike presence-ping and
 * presence-screen-ping, this ping has no per-page context, so any two
 * Dashboard tabs are duplicates and the dedupe key is fixed.
 *
 * Uses two pages in one browser context, not two Playwright contexts —
 * Web Locks and BroadcastChannel are scoped per storage partition.
 *
 * Run from plugin root:
 *   npx playwright test --config tests/e2e/playwright.config.js tests/e2e/presence-active-posts-coalescing.test.js
 *
 * @package WordPress
 */
import { test, expect } from '@wordpress/e2e-test-utils-playwright';

function waitForHeartbeat( page ) {
	return page.waitForFunction(
		() =>
			typeof wp !== 'undefined' && wp.heartbeat && wp.heartbeat.connectNow
	);
}

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

async function waitForLeaderPing( page, maxAttempts = 20 ) {
	let winningData;

	await expect
		.poll(
			async () => {
				winningData = await captureHeartbeatSend( page );
				return Boolean( winningData[ 'presence-active-posts-ping' ] );
			},
			{
				intervals: [ 0 ],
				timeout: maxAttempts * 100,
				message: 'Tab never won presence-active-posts-ping leadership.',
			}
		)
		.toBe( true );

	return winningData;
}

function waitForActivePostsTick( page, timeoutMs = 8000 ) {
	return page.evaluate( ( timeout ) => {
		return new Promise( ( resolve, reject ) => {
			const timer = setTimeout( () => {
				jQuery( document ).off( 'heartbeat-tick', handler );
				reject( new Error( 'Timed out waiting for a relayed tick.' ) );
			}, timeout );

			function handler( event, data ) {
				if ( data && data[ 'presence-active-posts' ] ) {
					clearTimeout( timer );
					jQuery( document ).off( 'heartbeat-tick', handler );
					resolve( data );
				}
			}

			jQuery( document ).on( 'heartbeat-tick', handler );
		} );
	}, timeoutMs );
}

test.describe( 'Presence Active Posts Tab Coalescing', () => {
	test.afterEach( async ( { requestUtils } ) => {
		await requestUtils.deleteAllPosts();
	} );

	test( 'elects one tab to send presence-active-posts-ping; a sibling tab stays silent', async ( {
		page,
	} ) => {
		await page.goto( '/wp-admin/' );
		await waitForHeartbeat( page );
		await waitForLeaderPing( page );

		const sibling = await page.context().newPage();
		await sibling.goto( '/wp-admin/' );
		await waitForHeartbeat( sibling );

		const siblingData = await captureHeartbeatSend( sibling );
		expect( siblingData[ 'presence-active-posts-ping' ] ).toBeUndefined();

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
		expect( beforeClose[ 'presence-active-posts-ping' ] ).toBeUndefined();

		await page.close();

		const promoted = await waitForLeaderPing( sibling );
		expect( promoted[ 'presence-active-posts-ping' ] ).toBe( true );

		await sibling.close();
	} );

	test( "relays the leader's active-posts data to a sibling tab", async ( {
		page,
		requestUtils,
	} ) => {
		const post = await requestUtils.createPost( {
			title: 'Active Posts Coalescing Post',
			status: 'draft',
		} );

		// Dedicated tab: navigating `page` away would trigger presence-ping's
		// pagehide handler, deleting the very entry we're trying to occupy.
		const editorTab = await page.context().newPage();
		await editorTab.goto(
			`/wp-admin/post.php?post=${ post.id }&action=edit`
		);
		await waitForHeartbeat( editorTab );
		await editorTab.evaluate( () => wp.heartbeat.connectNow() );

		await page.goto( '/wp-admin/' );
		await waitForHeartbeat( page );
		await waitForLeaderPing( page );

		const sibling = await page.context().newPage();
		await sibling.goto( '/wp-admin/' );
		await waitForHeartbeat( sibling );

		const [ relayed ] = await Promise.all( [
			waitForActivePostsTick( sibling ),
			page.evaluate( () => wp.heartbeat.connectNow() ),
		] );

		expect( relayed[ 'presence-active-posts' ].length ).toBeGreaterThan(
			0
		);
		expect( relayed[ 'presence-active-posts' ][ 0 ].post_id ).toBe(
			post.id
		);

		await sibling.close();
		await editorTab.close();
	} );
} );
