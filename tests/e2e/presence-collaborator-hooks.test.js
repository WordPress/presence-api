/**
 * Presence API — Collaborator JS Hooks E2E Tests
 *
 * Asserts presence-ping.js fires `presence-api.watchingRoom` and
 * `presence-api.collaboratorsChanged` through wp.hooks, so external code
 * (e.g. Gutenberg's sync poll loop) can react to a room's editor count
 * instead of polling for it separately.
 *
 * Run from plugin root:
 *   npx playwright test --config tests/e2e/playwright.config.js tests/e2e/presence-collaborator-hooks.test.js
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
		username: 'presencetesthooks',
		email: 'presencetesthooks@example.com',
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

	return { context, page: userPage };
}

function waitForHeartbeat( page ) {
	return page.waitForFunction(
		() =>
			typeof wp !== 'undefined' && wp.heartbeat && wp.heartbeat.connectNow
	);
}

/**
 * Forces a tick and waits for it to be fully processed before resolving,
 * so an assertion right after this call sees the tick's effects rather
 * than racing the in-flight request.
 *
 * @param {import('@playwright/test').Page} page
 */
function tickAndWait( page ) {
	return page.evaluate(
		() =>
			new Promise( ( resolve ) => {
				jQuery( document ).one( 'heartbeat-tick', () => resolve() );
				wp.heartbeat.connectNow();
			} )
	);
}

/**
 * Traps `window.wp.hooks` before any page script runs, so both actions are
 * captured even though `watchingRoom` fires ahead of Heartbeat's first tick,
 * before a listener added from page content could otherwise register in time.
 *
 * @param {import('@playwright/test').Page} page
 */
function captureCollaboratorActions( page ) {
	return page.addInitScript( () => {
		window.__watchingRoomCalls = [];
		window.__collaboratorsChangedCalls = [];
		window.wp = window.wp || {};

		let hooks;
		Object.defineProperty( window.wp, 'hooks', {
			configurable: true,
			get() {
				return hooks;
			},
			set( value ) {
				hooks = value;
				value.addAction(
					'presence-api.watchingRoom',
					'e2e-test',
					( room ) => {
						window.__watchingRoomCalls.push( room );
					}
				);
				value.addAction(
					'presence-api.collaboratorsChanged',
					'e2e-test',
					( room, count ) => {
						window.__collaboratorsChangedCalls.push( {
							room,
							count,
						} );
					}
				);
			},
		} );
	} );
}

test.describe( 'Presence Collaborator JS Hooks', () => {
	test.afterEach( async ( { requestUtils } ) => {
		await requestUtils.deleteAllPosts();
	} );

	test( 'fires watchingRoom once, before the first tick, with the post room', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const post = await requestUtils.createPost( {
			title: 'E2E Collaborator Hooks — watchingRoom',
			status: 'publish',
		} );

		await captureCollaboratorActions( page );

		await admin.visitAdminPage(
			'post.php',
			`post=${ post.id }&action=edit`
		);
		await waitForHeartbeat( page );

		const calls = await page.evaluate( () => window.__watchingRoomCalls );

		expect( calls ).toEqual( [ `postType/post:${ post.id }` ] );
	} );

	test( 'does not fire watchingRoom outside a post-edit screen', async ( {
		admin,
		page,
	} ) => {
		await captureCollaboratorActions( page );

		await admin.visitAdminPage( '/' );
		await waitForHeartbeat( page );

		const calls = await page.evaluate( () => window.__watchingRoomCalls );

		expect( calls ).toEqual( [] );
	} );

	test( 'fires collaboratorsChanged on the 1-to-2+ edge when a second editor joins, not again on the next unchanged tick', async ( {
		admin,
		page,
		requestUtils,
		testUsers,
	} ) => {
		const post = await requestUtils.createPost( {
			title: 'E2E Collaborator Hooks — join edge',
			status: 'publish',
		} );

		await captureCollaboratorActions( page );

		await admin.visitAdminPage(
			'post.php',
			`post=${ post.id }&action=edit`
		);
		await waitForHeartbeat( page );

		// Solo baseline: establishes the room, not an edge.
		await tickAndWait( page );
		expect(
			await page.evaluate( () => window.__collaboratorsChangedCalls )
		).toEqual( [] );

		const headlessBrowser = await chromium.launch( { headless: true } );

		try {
			const userB = await loginHeadlessUser(
				headlessBrowser,
				testUsers[ 0 ],
				`${ BASE_URL }/wp-admin/post.php?post=${ post.id }&action=edit`
			);
			await waitForHeartbeat( userB.page );
			await tickAndWait( userB.page );

			// User A's next tick observes User B and crosses the edge.
			await tickAndWait( page );
			expect(
				await page.evaluate( () => window.__collaboratorsChangedCalls )
			).toEqual( [ { room: `postType/post:${ post.id }`, count: 2 } ] );

			// Both still present: must not refire.
			await tickAndWait( page );
			expect(
				await page.evaluate( () => window.__collaboratorsChangedCalls )
			).toHaveLength( 1 );

			await userB.context.close();
		} finally {
			await headlessBrowser.close();
		}
	} );

	test( 'fires collaboratorsChanged on the 2+-to-1 edge from a relayed tick, not again while unchanged', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		// A relayed tick is how a follower tab in the same browser learns the
		// count (RELAYED_TICK_KEYS in presence-ping.js), so triggering one
		// directly exercises a real code path, not a fake for this test.
		const post = await requestUtils.createPost( {
			title: 'E2E Collaborator Hooks — leave edge',
			status: 'publish',
		} );

		await captureCollaboratorActions( page );

		await admin.visitAdminPage(
			'post.php',
			`post=${ post.id }&action=edit`
		);
		await waitForHeartbeat( page );
		await tickAndWait( page );

		await page.evaluate( () => {
			jQuery( document ).trigger( 'heartbeat-tick', [
				{ 'presence-heartbeat-collaborators': 2 },
			] );
		} );
		await page.evaluate( () => {
			jQuery( document ).trigger( 'heartbeat-tick', [
				{ 'presence-heartbeat-collaborators': 1 },
			] );
		} );
		// Unchanged: must not refire.
		await page.evaluate( () => {
			jQuery( document ).trigger( 'heartbeat-tick', [
				{ 'presence-heartbeat-collaborators': 1 },
			] );
		} );

		const calls = await page.evaluate(
			() => window.__collaboratorsChangedCalls
		);

		expect( calls ).toEqual( [
			{ room: `postType/post:${ post.id }`, count: 2 },
			{ room: `postType/post:${ post.id }`, count: 1 },
		] );
	} );
} );
