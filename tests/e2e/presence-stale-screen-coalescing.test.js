/**
 * Presence API — Stale-Screen Cross-Tab Coalescing E2E Tests
 *
 * Asserts stale-screen.js elects one tab per screen to send
 * presence-screen-ping, siblings stay silent, closing the leader promotes a
 * sibling, and the leader's presence-screen-rev relays to siblings over
 * BroadcastChannel.
 *
 * Also covers what dismissing the banner does to later edits, which shares
 * this file's fixtures rather than duplicating them.
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
		username: 'presenceteststaleb',
		email: 'presenceteststaleb@example.com',
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
				return Boolean( winningData[ 'presence-screen-ping' ] );
			},
			{
				intervals: [ 0 ],
				timeout: maxAttempts * 100,
				message: 'Tab never won presence-screen-ping leadership.',
			}
		)
		.toBe( true );

	return winningData;
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
			expect(
				sibling.locator( '.wp-presence-stale-notice' )
			).toBeVisible( { timeout: 8000 } ),
			page.evaluate( () => wp.heartbeat.connectNow() ),
		] );

		await sibling.close();
	} );
} );

test.describe( 'Presence Stale-Screen Banner', () => {
	test.afterEach( async ( { requestUtils } ) => {
		await requestUtils.deleteAllPosts();
	} );

	/**
	 * Edits a post and attributes it to a given user.
	 *
	 * `_edit_last` carries the actor and core only writes it from the admin
	 * save path, so `wp_update_post()` alone leaves it empty and every edit
	 * reads as authorless. Setting it here is what makes `actor_is_me` mean
	 * anything.
	 *
	 * Post revisions are `strtotime( post_modified_gmt )`, so consecutive
	 * edits inside one second are indistinguishable. Callers space them.
	 *
	 * @param {number} postId The post to touch.
	 * @param {string} userId The editing user's ID.
	 */
	function editAsUser( postId, userId ) {
		wpEval(
			`wp_update_post( array( 'ID' => ${ postId }, 'post_content' => get_post_field( 'post_content', ${ postId } ) ) ); update_post_meta( ${ postId }, '_edit_last', ${ userId } );`
		);
	}

	async function openPostAsLeader( page, postId ) {
		await page.goto( `/wp-admin/post.php?post=${ postId }&action=edit` );
		await waitForHeartbeat( page );
		await waitForLeaderPing( page );
	}

	test( 'shows the banner again after a later edit once an earlier one was dismissed', async ( {
		page,
		requestUtils,
		testUsers,
	} ) => {
		const post = await requestUtils.createPost( {
			title: 'Stale Screen Redisplay Post',
			status: 'draft',
		} );
		const otherUserId = execOutput(
			`npx wp-env run cli wp user get ${ testUsers[ 0 ].username } --field=ID`
		);

		await openPostAsLeader( page, post.id );

		editAsUser( post.id, otherUserId );
		await Promise.all( [
			expect( page.locator( '.wp-presence-stale-notice' ) ).toBeVisible( {
				timeout: 8000,
			} ),
			page.evaluate( () => wp.heartbeat.connectNow() ),
		] );

		await page
			.locator( '.wp-presence-stale-notice .notice-dismiss' )
			.click();
		await expect( page.locator( '.wp-presence-stale-notice' ) ).toHaveCount(
			0
		);

		// Past the one-second resolution of the revision — no DOM state to
		// poll for yet, so the wait has to be real time.
		// eslint-disable-next-line playwright/no-wait-for-timeout
		await page.waitForTimeout( 1200 );
		editAsUser( post.id, otherUserId );

		await Promise.all( [
			expect( page.locator( '.wp-presence-stale-notice' ) ).toBeVisible( {
				timeout: 8000,
			} ),
			page.evaluate( () => wp.heartbeat.connectNow() ),
		] );
	} );

	test( "stays silent for the viewer's own save and still reports the next foreign one", async ( {
		page,
		requestUtils,
		testUsers,
	} ) => {
		const post = await requestUtils.createPost( {
			title: 'Stale Screen Self Save Post',
			status: 'draft',
		} );
		const otherUserId = execOutput(
			`npx wp-env run cli wp user get ${ testUsers[ 0 ].username } --field=ID`
		);
		const viewerId = execOutput(
			`npx wp-env run cli wp user get admin --field=ID`
		);

		await openPostAsLeader( page, post.id );

		editAsUser( post.id, viewerId );
		for ( let tick = 0; tick < 3; tick++ ) {
			await page.evaluate( () => wp.heartbeat.connectNow() );
			// Paces ticks a fixed interval apart; there is no DOM state to
			// poll for between them.
			// eslint-disable-next-line playwright/no-wait-for-timeout
			await page.waitForTimeout( 500 );
		}
		await expect( page.locator( '.wp-presence-stale-notice' ) ).toHaveCount(
			0
		);

		// A banner here proves the self-save advanced the baseline rather
		// than the screen having gone inert. Past the one-second resolution
		// of the revision — no DOM state to poll for yet.
		// eslint-disable-next-line playwright/no-wait-for-timeout
		await page.waitForTimeout( 1200 );
		editAsUser( post.id, otherUserId );
		await Promise.all( [
			expect( page.locator( '.wp-presence-stale-notice' ) ).toBeVisible( {
				timeout: 8000,
			} ),
			page.evaluate( () => wp.heartbeat.connectNow() ),
		] );
	} );

	test( 'leaves the banner dismissed when no further edit has happened', async ( {
		page,
		requestUtils,
		testUsers,
	} ) => {
		const post = await requestUtils.createPost( {
			title: 'Stale Screen Dismissal Post',
			status: 'draft',
		} );
		const otherUserId = execOutput(
			`npx wp-env run cli wp user get ${ testUsers[ 0 ].username } --field=ID`
		);

		await openPostAsLeader( page, post.id );

		editAsUser( post.id, otherUserId );
		await Promise.all( [
			expect( page.locator( '.wp-presence-stale-notice' ) ).toBeVisible( {
				timeout: 8000,
			} ),
			page.evaluate( () => wp.heartbeat.connectNow() ),
		] );

		await page
			.locator( '.wp-presence-stale-notice .notice-dismiss' )
			.click();

		// The revision that raised the banner is still ahead of the page's
		// original baseline, so a fix that only clears the shown flag brings
		// the same banner straight back.
		for ( let tick = 0; tick < 3; tick++ ) {
			await page.evaluate( () => wp.heartbeat.connectNow() );
			// Paces ticks a fixed interval apart; there is no DOM state to
			// poll for between them.
			// eslint-disable-next-line playwright/no-wait-for-timeout
			await page.waitForTimeout( 500 );
		}

		await expect( page.locator( '.wp-presence-stale-notice' ) ).toHaveCount(
			0
		);
	} );
} );
