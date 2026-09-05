/**
 * Presence API — Heartbeat Idle Backoff E2E Tests
 *
 * Asserts that presence-ping.js widens the Heartbeat interval after enough
 * consecutive unchanged ticks, and snaps it back on local activity.
 *
 * Run from plugin root:
 *   npx playwright test --config tests/e2e/playwright.config.js tests/e2e/presence-heartbeat-backoff.test.js
 *
 * @package WordPress
 * @since 7.1.0
 */
import { test as base, expect } from '@wordpress/e2e-test-utils-playwright';

const TEST_USERS = [
	{
		username: 'presencetestbackoff',
		email: 'presencetestbackoff@example.com',
		firstName: 'User',
		lastName: 'B',
		password: 'password',
		roles: [ 'editor' ],
	},
];

const test = base.extend( {
	// A second user is required for the post-lock dialog (and its 10-second
	// Heartbeat interval) to render on the edit screen at all.
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

function waitForHeartbeat( page ) {
	return page.waitForFunction(
		() => typeof wp !== 'undefined' && wp.heartbeat && wp.heartbeat.connectNow
	);
}

/**
 * Forces a heartbeat tick and resolves with the data object the tick carried.
 *
 * @param {import('@playwright/test').Page} page
 * @returns {Promise<object>}
 */
function captureHeartbeatTick( page ) {
	return page.evaluate(
		() =>
			new Promise( ( resolve ) => {
				jQuery( document ).one( 'heartbeat-tick', ( event, data ) => {
					resolve( data );
				} );
				wp.heartbeat.connectNow();
			} )
	);
}

test.describe( 'Presence Heartbeat Idle Backoff', () => {
	test.afterEach( async ( { requestUtils } ) => {
		await requestUtils.deleteAllPosts();
	} );

	test( 'widens the interval after consecutive unchanged ticks, then snaps back on keydown', async ( {
		admin,
		page,
		requestUtils,
		testUsers,
	} ) => {
		const post = await requestUtils.createPost( {
			title: 'E2E Heartbeat Backoff Test',
			status: 'publish',
		} );

		await admin.visitAdminPage( 'post.php', `post=${ post.id }&action=edit` );
		await waitForHeartbeat( page );

		// The post-lock dialog is present (2+ users exist), so post.js has
		// already set the interval to 10 seconds by the time heartbeat is ready.
		const normalInterval = await page.evaluate( () => wp.heartbeat.interval() );
		expect( normalInterval ).toBe( 10 );

		// One tick establishes the room's hash; repeat until the client sees
		// enough consecutive unchanged ticks to back off.
		let widened = false;
		for ( let i = 0; i < 10 && ! widened; i++ ) {
			await captureHeartbeatTick( page );
			const current = await page.evaluate( () => wp.heartbeat.interval() );
			if ( current > normalInterval ) {
				widened = true;
			}
		}

		expect( widened ).toBe( true );
		const widenedInterval = await page.evaluate( () => wp.heartbeat.interval() );
		expect( widenedInterval ).toBeGreaterThan( normalInterval );
		// The idle interval is itself under the TTL.
		const idleInterval = await page.evaluate( () => window.wpPresenceConfig.idleInterval );
		expect( widenedInterval ).toBeLessThanOrEqual( idleInterval );

		await page.evaluate( () => {
			document.dispatchEvent( new KeyboardEvent( 'keydown', { bubbles: true } ) );
		} );

		const afterKeydown = await page.evaluate( () => wp.heartbeat.interval() );
		expect( afterKeydown ).toBe( normalInterval );
	} );
} );
