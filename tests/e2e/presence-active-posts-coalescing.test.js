/**
 * Presence API — Active Posts Cross-Tab Coalescing E2E Tests
 *
 * Asserts the leader's active-posts fragment relays to a sibling Dashboard
 * tab over BroadcastChannel. Election and promotion are presence-ping's own,
 * covered by presence-tab-coalescing.test.js.
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
				return Boolean(
					winningData[ 'presence-fragments' ]?.[ 'active-posts' ]
				);
			},
			{
				intervals: [ 0 ],
				timeout: maxAttempts * 100,
				message: 'Tab never asked for the active-posts fragment.',
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
				if ( data?.[ 'presence-fragments' ]?.[ 'active-posts' ] ) {
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

		expect( relayed[ 'presence-fragments' ][ 'active-posts' ] ).toContain(
			'Active Posts Coalescing Post'
		);

		await sibling.close();
		await editorTab.close();
	} );
} );
