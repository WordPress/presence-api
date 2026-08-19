/**
 * Presence API — Stale-Screen Cross-Tab Coalescing E2E Tests
 *
 * Asserts stale-screen.js elects one tab per screen to send
 * presence-screen-ping, siblings stay silent, closing the leader promotes a
 * sibling, and the leader's presence-screen-rev relays to siblings over
 * BroadcastChannel.
 *
 * Uses two pages in one browser context, not two Playwright contexts —
 * Web Locks and BroadcastChannel are scoped per storage partition.
 *
 * Run from plugin root:
 *   npx playwright test --config tests/e2e/playwright.config.js tests/e2e/presence-stale-screen-coalescing.test.js
 *
 * @package WordPress
 */
import { test as base, expect } from '@wordpress/e2e-test-utils-playwright';
import { execSync } from 'node:child_process';

const TEST_USERS = [
	{
		username: 'presence_test_stale_b',
		email: 'presence_test_stale_b@example.com',
		firstName: 'Stale',
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

function execOutput( command ) {
	return execSync( command, { encoding: 'utf8', timeout: 30_000 } )
		.trim()
		.split( '\n' )
		.pop()
		.trim();
}

function wpEval( phpExpression ) {
	execSync(
		`npx wp-env run cli wp eval ${ JSON.stringify( phpExpression ) }`,
		{ stdio: 'pipe', timeout: 30_000 }
	);
}

function waitForHeartbeat( page ) {
	return page.waitForFunction(
		() => typeof wp !== 'undefined' && wp.heartbeat && wp.heartbeat.connectNow
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
	for ( let attempt = 0; attempt < maxAttempts; attempt++ ) {
		const data = await captureHeartbeatSend( page );
		if ( data[ 'presence-screen-ping' ] ) {
			return data;
		}
		await page.waitForTimeout( 100 );
	}
	throw new Error( 'Tab never won presence-screen-ping leadership.' );
}

test.describe( 'Presence Stale-Screen Tab Coalescing', () => {
	test.afterEach( async ( { requestUtils } ) => {
		await requestUtils.deleteAllPosts();
	} );

	test( 'elects one tab to send presence-screen-ping; a sibling tab on the same post stays silent', async ( {
		page,
		requestUtils,
	} ) => {
		const post = await requestUtils.createPost( {
			title: 'Stale Screen Coalescing Post',
			status: 'draft',
		} );

		await page.goto( `/wp-admin/post.php?post=${ post.id }&action=edit` );
		await waitForHeartbeat( page );
		const leaderData = await waitForLeaderPing( page );
		expect( leaderData[ 'presence-screen-ping' ].key ).toBe(
			`post/${ post.id }`
		);

		const sibling = await page.context().newPage();
		await sibling.goto(
			`/wp-admin/post.php?post=${ post.id }&action=edit`
		);
		await waitForHeartbeat( sibling );

		const siblingData = await captureHeartbeatSend( sibling );
		expect( siblingData[ 'presence-screen-ping' ] ).toBeUndefined();

		await sibling.close();
	} );

	test( 'promotes the sibling tab once the leader tab closes', async ( {
		page,
		requestUtils,
	} ) => {
		const post = await requestUtils.createPost( {
			title: 'Stale Screen Promotion Post',
			status: 'draft',
		} );

		await page.goto( `/wp-admin/post.php?post=${ post.id }&action=edit` );
		await waitForHeartbeat( page );
		await waitForLeaderPing( page );

		const sibling = await page.context().newPage();
		await sibling.goto(
			`/wp-admin/post.php?post=${ post.id }&action=edit`
		);
		await waitForHeartbeat( sibling );

		const beforeClose = await captureHeartbeatSend( sibling );
		expect( beforeClose[ 'presence-screen-ping' ] ).toBeUndefined();

		await page.close();

		const promoted = await waitForLeaderPing( sibling );
		expect( promoted[ 'presence-screen-ping' ].key ).toBe(
			`post/${ post.id }`
		);

		await sibling.close();
	} );

	test( "relays the leader's stale-screen banner to a sibling tab", async ( {
		page,
		requestUtils,
		testUsers,
	} ) => {
		const post = await requestUtils.createPost( {
			title: 'Stale Screen Relay Post',
			status: 'draft',
		} );

		await page.goto( `/wp-admin/post.php?post=${ post.id }&action=edit` );
		await waitForHeartbeat( page );
		await waitForLeaderPing( page );

		const sibling = await page.context().newPage();
		await sibling.goto(
			`/wp-admin/post.php?post=${ post.id }&action=edit`
		);
		await waitForHeartbeat( sibling );

		// bump_screen_revision() no-ops for post keys — save the post instead.
		const otherUserId = execOutput(
			`npx wp-env run cli wp user get ${ testUsers[ 0 ].username } --field=ID`
		);
		// Each `wp eval` call is its own process — no need to restore the user.
		wpEval(
			`wp_set_current_user( ${ otherUserId } ); wp_update_post( array( 'ID' => ${ post.id }, 'post_content' => get_post_field( 'post_content', ${ post.id } ) ) );`
		);

		await Promise.all( [
			sibling.waitForSelector( '.wp-presence-stale-notice', {
				timeout: 8000,
			} ),
			page.evaluate( () => wp.heartbeat.connectNow() ),
		] );

		await sibling.close();
	} );
} );
