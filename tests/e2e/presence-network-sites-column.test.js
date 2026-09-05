/**
 * Presence API — Network Admin Sites list "Online" column E2E Tests
 *
 * Runs against the multisite instance, not the site the other specs use. See
 * scripts/start-multisite-env.sh.
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
	networkSiteId,
	setNetworkPresence,
} from './network-helpers';

let siteId;

test.describe( 'Network Sites Online column', () => {
	test.beforeAll( () => {
		siteId = networkSiteId( SITE_SLUG );
	} );

	test.beforeEach( () => {
		clearNetworkPresence();
	} );

	test.afterAll( () => {
		clearNetworkPresence();
	} );

	/**
	 * Returns the Online cell of the sub-site's row.
	 *
	 * Matched on the row's own checkbox rather than on the site name, because
	 * the main site's name is a prefix of the sub-site's on a subdirectory
	 * network.
	 *
	 * @param {import('@playwright/test').Page} page
	 * @return {import('@playwright/test').Locator} The matching cell.
	 */
	function onlineCell( page ) {
		return page
			.locator( '#the-list tr' )
			.filter( { has: page.locator( `#blog_${ siteId }` ) } )
			.locator( '.column-presence_online' );
	}

	test( 'draws an avatar stack and a count for a site with someone online', async ( {
		admin,
		page,
	} ) => {
		setNetworkPresence( { login: NETWORK_USERS.a.login, slug: SITE_SLUG } );

		await admin.visitAdminPage( 'network/sites.php' );

		await expect( page.locator( 'thead th#presence_online' ) ).toHaveText(
			'Online'
		);

		const avatars = onlineCell( page ).locator(
			'.presence-avatar-stack img'
		);

		await expect( avatars ).toHaveCount( 1 );
		await expect( avatars ).toHaveAttribute(
			'alt',
			NETWORK_USERS.a.displayName
		);
		await expect( onlineCell( page ) ).toHaveText( '1' );
	} );

	test( 'draws an em dash for a site with nobody online', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( 'network/sites.php' );

		await expect( onlineCell( page ) ).toHaveText( '—' );
	} );

	test( 'holds its page-load snapshot across a heartbeat tick, and redraws on reload', async ( {
		admin,
		page,
	} ) => {
		setNetworkPresence( { login: NETWORK_USERS.a.login, slug: SITE_SLUG } );

		await admin.visitAdminPage( 'network/sites.php' );
		await expect(
			onlineCell( page ).locator( '.presence-avatar-stack img' )
		).toHaveCount( 1 );

		setNetworkPresence( {
			login: NETWORK_USERS.b.login,
			slug: SITE_SLUG,
			client: 'e2e-b',
		} );

		// The column is rendered once per page load on purpose, the same as
		// every other column on this table — see the note on
		// wp_presence_render_network_sites_column(). A tick has to leave it
		// alone; only the reload below may move it.
		await forceHeartbeatTick( page );

		await expect(
			onlineCell( page ).locator( '.presence-avatar-stack img' )
		).toHaveCount( 1 );
		await expect( onlineCell( page ) ).toHaveText( '1' );

		await page.reload();

		await expect(
			onlineCell( page ).locator( '.presence-avatar-stack img' )
		).toHaveCount( 2 );
		await expect( onlineCell( page ) ).toHaveText( '2' );
	} );
} );
