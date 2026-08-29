/**
 * Presence API — Network Admin Users list "Online" view and column E2E Tests
 *
 * Runs against the multisite instance, not the site the other specs use. See
 * scripts/start-multisite-env.sh.
 *
 * The admin driving these tests counts as online: rendering any admin screen
 * writes the viewer into the admin room server-side, so the main site always
 * has one person on it while a spec runs. See wp_presence_enqueue_heartbeat_ping().
 *
 * Run from plugin root:
 *   npx playwright test --config tests/e2e/playwright.config.js --project chromium-multisite
 *
 * @package WordPress
 */
import { test, expect } from '@wordpress/e2e-test-utils-playwright';
import {
	NETWORK_USERS,
	SITE_SLUG,
	clearNetworkPresence,
	forceHeartbeatTick,
	networkUserId,
	setNetworkPresence,
	siteLabel,
} from './network-helpers';

let userA;
let userB;

test.describe( 'Network Users Online view and column', () => {
	test.beforeAll( () => {
		userA = networkUserId( NETWORK_USERS.a.login );
		userB = networkUserId( NETWORK_USERS.b.login );
	} );

	test.beforeEach( () => {
		clearNetworkPresence();
	} );

	test.afterAll( () => {
		clearNetworkPresence();
	} );

	/**
	 * Returns the Online cell of one user's row.
	 *
	 * @param {import('@playwright/test').Page} page
	 * @param {number} userId
	 * @returns {import('@playwright/test').Locator}
	 */
	function onlineCell( page, userId ) {
		return page.locator( `#user-${ userId } .column-presence_online` );
	}

	/**
	 * Returns the "Online" entry in the list of views above the table.
	 *
	 * @param {import('@playwright/test').Page} page
	 * @returns {import('@playwright/test').Locator}
	 */
	function onlineView( page ) {
		return page.locator( '.subsubsub li.presence_online a' );
	}

	test( 'counts everyone online anywhere on the network', async ( {
		admin,
		page,
	} ) => {
		setNetworkPresence( { login: NETWORK_USERS.a.login, slug: SITE_SLUG } );

		await admin.visitAdminPage( 'network/users.php' );

		// User A on the sub-site, plus the admin this page just wrote online.
		await expect( onlineView( page ) ).toContainText( 'Online' );
		await expect( onlineView( page ).locator( '.count' ) ).toHaveText( '(2)' );
	} );

	test( 'names the sites a user is online on, and an em dash for a user online nowhere', async ( {
		admin,
		page,
	} ) => {
		setNetworkPresence( { login: NETWORK_USERS.a.login, slug: SITE_SLUG } );

		await admin.visitAdminPage( 'network/users.php' );

		await expect( onlineCell( page, userA ) ).toHaveText( siteLabel( SITE_SLUG ) );
		await expect( onlineCell( page, userB ) ).toHaveText( '—' );
	} );

	test( 'lists both sites for a user online on two of them', async ( {
		admin,
		page,
	} ) => {
		setNetworkPresence( { login: NETWORK_USERS.a.login } );
		setNetworkPresence( {
			login: NETWORK_USERS.a.login,
			slug: SITE_SLUG,
			client: 'e2e-team',
		} );

		await admin.visitAdminPage( 'network/users.php' );

		await expect( onlineCell( page, userA ) ).toContainText( siteLabel() );
		await expect( onlineCell( page, userA ) ).toContainText(
			siteLabel( SITE_SLUG )
		);
	} );

	test( 'filters the table to online users when the view is followed', async ( {
		admin,
		page,
	} ) => {
		setNetworkPresence( { login: NETWORK_USERS.a.login, slug: SITE_SLUG } );

		await admin.visitAdminPage( 'network/users.php' );
		await expect( page.locator( `#user-${ userB }` ) ).toBeVisible();

		await onlineView( page ).click();

		await expect( onlineView( page ) ).toHaveClass( /current/ );
		await expect( page.locator( `#user-${ userA }` ) ).toBeVisible();
		await expect( page.locator( `#user-${ userB }` ) ).toHaveCount( 0 );
	} );

	test( 'holds its page-load snapshot across a heartbeat tick, and redraws on reload', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( 'network/users.php' );
		await expect( onlineCell( page, userB ) ).toHaveText( '—' );

		setNetworkPresence( { login: NETWORK_USERS.b.login, slug: SITE_SLUG } );

		// The column is rendered once per page load on purpose, the same as
		// the Sites list column it mirrors.
		await forceHeartbeatTick( page );
		await expect( onlineCell( page, userB ) ).toHaveText( '—' );

		await page.reload();
		await expect( onlineCell( page, userB ) ).toHaveText(
			siteLabel( SITE_SLUG )
		);
	} );
} );
