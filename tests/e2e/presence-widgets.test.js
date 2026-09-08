/**
 * Presence API — Widget E2E Tests
 *
 * Tests multi-user presence scenarios across dashboard widgets.
 *
 * Run from plugin root:
 *   npx playwright test --config tests/e2e/playwright.config.js
 *
 * @package WordPress
 * @since 7.1.0
 */
import { test as base, expect } from '@wordpress/e2e-test-utils-playwright';
import { chromium } from '@playwright/test';
import { execSync } from 'node:child_process';

// Trailing slash stripped so `${BASE_URL}/wp-admin/` below can't double up.
const BASE_URL = ( process.env.WP_BASE_URL || 'http://localhost:8888' ).replace(
	/\/$/,
	''
);

function wpCli( command ) {
	execSync( `npx wp-env run cli wp ${ command }`, {
		stdio: 'pipe',
		timeout: 30_000,
	} );
}

function wpCliOutput( command ) {
	const raw = execSync( `npx wp-env run cli wp ${ command }`, {
		encoding: 'utf8',
		timeout: 30_000,
	} );
	return raw.trim().split( '\n' ).pop().trim();
}

const TEST_USERS = [
	{
		username: 'presencetestb',
		email: 'presencetestb@example.com',
		firstName: 'User',
		lastName: 'B',
		password: 'password',
		roles: [ 'editor' ],
	},
	{
		username: 'presencetestc',
		email: 'presencetestc@example.com',
		firstName: 'User',
		lastName: 'C',
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
 * Log in a user on a headless browser and navigate to a URL.
 *
 * Uses request-based auth (POST to wp-login.php) to avoid
 * form interaction issues with WordPress 7.0's login page.
 * @param {import('@playwright/test').Browser}   headlessBrowser The browser to open a context on.
 * @param {{username: string, password: string}} user            Credentials to log in with.
 * @param {string}                               destinationUrl  Where to land after logging in.
 */
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
	await waitForHeartbeat( userPage );
	await userPage.evaluate( () => wp.heartbeat.connectNow() );

	return { context, page: userPage };
}

test.describe( 'Presence Widgets', () => {
	test( "User B appears in Who's Online widget", async ( {
		admin,
		page,
		testUsers,
	} ) => {
		await admin.visitAdminPage( '/' );
		await page.evaluate( () => wp.heartbeat.connectNow() );

		const headlessBrowser = await chromium.launch( { headless: true } );

		try {
			const userB = await loginHeadlessUser(
				headlessBrowser,
				testUsers[ 0 ],
				`${ BASE_URL }/wp-admin/`
			);

			await page.evaluate( () => wp.heartbeat.connectNow() );

			const whosList = page.locator( '#presence-whos-online-list' );
			await expect( whosList ).toContainText( testUsers[ 0 ].lastName, {
				timeout: 30_000,
			} );

			await userB.context.close();
		} finally {
			await headlessBrowser.close();
		}
	} );

	test( "Focus stays on the user's row in Who's Online widget across a heartbeat re-render", async ( {
		admin,
		page,
		testUsers,
	} ) => {
		const userBId = wpCliOutput(
			`user get ${ testUsers[ 0 ].username } --field=ID`
		);

		await admin.visitAdminPage( '/' );
		await page.evaluate( () => wp.heartbeat.connectNow() );

		wpCli(
			`eval 'wp_set_presence( wp_presence_admin_room(), "session-b", array( "screen" => "dashboard" ), ${ userBId } );'`
		);
		await page.evaluate( () => wp.heartbeat.connectNow() );

		const userRow = page.locator(
			`#presence-whos-online-list [data-user-id="${ userBId }"]`
		);
		await expect( userRow ).toBeVisible( { timeout: 30_000 } );

		const userLink = userRow.locator( 'a' ).first();
		await userLink.focus();
		await expect( userLink ).toBeFocused();

		wpCli(
			`eval 'wp_set_presence( wp_presence_admin_room(), "session-b", array( "screen" => "edit" ), ${ userBId } );'`
		);
		await page.evaluate( () => wp.heartbeat.connectNow() );

		await expect(
			page
				.locator(
					`#presence-whos-online-list [data-user-id="${ userBId }"] a`
				)
				.first()
		).toBeFocused( { timeout: 30_000 } );
	} );

	test( 'Post editing presence appears in Active Posts widget', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const post = await requestUtils.createPost( {
			title: 'E2E Presence Test Post',
			status: 'draft',
		} );

		// Seed a presence entry for the post via wp eval (CLI --user flag collides with WP-CLI global).
		wpCli(
			`eval 'wp_set_presence( "postType/post:${ post.id }", "editor-1", array( "action" => "editing", "screen" => "post" ), 1 );'`
		);

		await admin.visitAdminPage( '/' );
		await page.evaluate( () => wp.heartbeat.connectNow() );

		const activePostsList = page.locator( '#presence-active-posts-list' );
		await expect( activePostsList ).toContainText(
			'E2E Presence Test Post',
			{ timeout: 30_000 }
		);
	} );

	test( 'Focus stays on the post link in Active Posts widget across a heartbeat re-render', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const post = await requestUtils.createPost( {
			title: 'E2E Focus Test Post',
			status: 'draft',
		} );

		wpCli(
			`eval 'wp_set_presence( "postType/post:${ post.id }", "session-a", array( "action" => "editing", "screen" => "post" ), 1 );'`
		);

		await admin.visitAdminPage( '/' );
		await page.evaluate( () => wp.heartbeat.connectNow() );

		// Match by edit href, not title text — leftover draft posts from
		// earlier runs can share the same title.
		const postLink = page.locator(
			`#presence-active-posts-list a[href$="post=${ post.id }&action=edit"]`
		);
		await expect( postLink ).toBeVisible( { timeout: 30_000 } );

		await postLink.focus();
		await expect( postLink ).toBeFocused();

		// Backdate the entry past the idle threshold so the next tick's
		// signature differs and the list markup gets rebuilt.
		wpCli(
			`eval 'global $wpdb; $wpdb->update( $wpdb->presence, array( "date_gmt" => gmdate( "Y-m-d H:i:s", time() - 45 ) ), array( "client_id" => "session-a" ), array( "%s" ), array( "%s" ) );'`
		);
		await page.evaluate( () => wp.heartbeat.connectNow() );

		await expect(
			page.locator( '#presence-active-posts-list' )
		).toContainText( 'Idle', { timeout: 30_000 } );
		await expect( postLink ).toBeFocused();
	} );

	/**
	 * The heartbeat payload is capped well below a large room, so the overflow
	 * count and its link to the Users screen can only come from the total the
	 * response carries. Reading them off the capped array instead replaces a
	 * correct server render with a smaller count on the first tick.
	 */
	test( "Who's Online counts the overflow from the payload total", async ( {
		admin,
		page,
	} ) => {
		const others = 26;
		const visibleRows = 3;
		// The seeded users plus you, who the widget counts as well.
		const online = others + 1;

		try {
			await page.addInitScript( () => {
				window.__presenceTicks = 0;
				document.addEventListener( 'DOMContentLoaded', () => {
					jQuery( document ).on(
						'heartbeat-tick',
						( event, data ) => {
							if ( data[ 'presence-online' ] ) {
								window.__presenceTicks++;
							}
						}
					);
				} );
			} );

			await admin.visitAdminPage( '/' );

			// Seed after the page load tick, not before. Seeding first lets that
			// tick carry the final state, and the forced tick then comes back
			// `presence-online-unchanged`, which never reaches the listener.
			wpCli(
				`eval-file wp-content/plugins/presence-api/tests/e2e/whos-online-overflow-seeder.php ${ others }`
			);

			const summary = page.locator(
				'#presence-whos-online-list a.presence-overflow-toggle'
			);

			const tick = async () => {
				const before = await page.evaluate(
					() => window.__presenceTicks
				);
				await page.evaluate( () => wp.heartbeat.connectNow() );
				await page.waitForFunction(
					( seen ) => window.__presenceTicks > seen,
					before,
					{ timeout: 30_000 }
				);
			};

			await tick();

			await expect( summary ).toContainText(
				`+${ online - visibleRows } more`
			);
			await expect(
				page.locator(
					'#presence-whos-online-list #presence-overflow-list'
				)
			).toHaveCount( 0 );

			// Drop a user ranked below the payload cap: the entries the client
			// receives are byte-identical and only the total moves, so a
			// signature built from the entries alone leaves the count stale.
			wpCli(
				'eval-file wp-content/plugins/presence-api/tests/e2e/whos-online-overflow-seeder.php drop-oldest'
			);

			await tick();

			await expect( summary ).toContainText(
				`+${ online - visibleRows - 1 } more`
			);
		} finally {
			wpCli(
				'eval-file wp-content/plugins/presence-api/tests/e2e/whos-online-overflow-seeder.php clean'
			);
		}
	} );
} );
