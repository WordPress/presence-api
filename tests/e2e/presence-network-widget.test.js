/**
 * Presence API — Network Admin "Who's Online" dashboard widget E2E Tests
 *
 * The one screen of the three that updates over Heartbeat: the server renders
 * the site list, and every tick replaces it in place.
 *
 * Runs against the multisite instance, not the site the other specs use. See
 * scripts/start-multisite-env.sh.
 *
 * The admin driving these tests counts as online: rendering any admin screen
 * writes the viewer into the admin room server-side, so the main site is always
 * one of the sites listed. See wp_presence_enqueue_heartbeat_ping().
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
	ensureNetworkSite,
	forceHeartbeatTick,
	networkSiteId,
	setNetworkPresence,
	siteLabel,
} from './network-helpers';

/**
 * Sub-sites created for the overflow case, on top of the main site and the one
 * the fixture network already has.
 *
 * Five is what it takes to push past the widget's own VISIBLE_SITES of 5 and
 * leave sites over for the "more sites" link to count.
 */
const OVERFLOW_SLUGS = [ 'over1', 'over2', 'over3', 'over4', 'over5' ];

let mainSiteId;
let teamSiteId;

test.describe( "Network Who's Online widget", () => {
	test.beforeAll( () => {
		mainSiteId = 1;
		teamSiteId = networkSiteId( SITE_SLUG );
	} );

	test.beforeEach( () => {
		clearNetworkPresence();
	} );

	test.afterAll( () => {
		clearNetworkPresence();
	} );

	/**
	 * @param {import('@playwright/test').Page} page
	 * @return {import('@playwright/test').Locator} The matching element.
	 */
	function widgetList( page ) {
		return page.locator(
			'#presence-network-widget-list ul.presence-user-list'
		);
	}

	/**
	 * @param {import('@playwright/test').Page} page
	 * @param {number}                          blogId
	 * @return {import('@playwright/test').Locator} The matching element.
	 */
	function siteItem( page, blogId ) {
		return page.locator(
			`#presence-network-widget-list [data-blog-id="${ blogId }"]`
		);
	}

	test( 'lists a site with online users, under an accessible name', async ( {
		admin,
		page,
	} ) => {
		setNetworkPresence( { login: NETWORK_USERS.a.login, slug: SITE_SLUG } );

		await admin.visitAdminPage( 'network/' );

		await expect( widgetList( page ) ).toHaveAttribute(
			'aria-label',
			'Sites with online users'
		);

		const team = siteItem( page, teamSiteId );

		await expect(
			team.locator( '.presence-avatar-stack img' )
		).toHaveAttribute( 'alt', NETWORK_USERS.a.displayName );
		await expect( team.locator( '.presence-site-info a' ) ).toHaveText(
			siteLabel( SITE_SLUG )
		);
		await expect( team.locator( '.presence-site-count' ) ).toHaveText(
			'1'
		);
	} );

	test( 'adds a site over a heartbeat tick, without a reload', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( 'network/' );

		await expect( siteItem( page, mainSiteId ) ).toBeVisible();
		await expect( siteItem( page, teamSiteId ) ).toHaveCount( 0 );

		setNetworkPresence( { login: NETWORK_USERS.a.login, slug: SITE_SLUG } );
		await forceHeartbeatTick( page );

		await expect( siteItem( page, teamSiteId ) ).toBeVisible();
		await expect(
			siteItem( page, teamSiteId ).locator( '.presence-site-count' )
		).toHaveText( '1' );
	} );

	test( 'keeps focus on a site link across a heartbeat re-render', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( 'network/' );

		const mainLink = siteItem( page, mainSiteId ).locator(
			'.presence-site-info a'
		);

		await mainLink.focus();
		await expect( mainLink ).toBeFocused();

		setNetworkPresence( { login: NETWORK_USERS.a.login, slug: SITE_SLUG } );
		await forceHeartbeatTick( page );

		await expect( siteItem( page, teamSiteId ) ).toBeVisible();
		await expect(
			siteItem( page, mainSiteId ).locator( '.presence-site-info a' )
		).toBeFocused();
	} );

	test( 'keeps the accessible name of the list across a heartbeat re-render', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( 'network/' );

		setNetworkPresence( { login: NETWORK_USERS.a.login, slug: SITE_SLUG } );
		await forceHeartbeatTick( page );

		await expect( siteItem( page, teamSiteId ) ).toBeVisible();
		await expect( widgetList( page ) ).toHaveAttribute(
			'aria-label',
			'Sites with online users'
		);
	} );

	test( 'keeps the accessible name of the overflow link across a heartbeat re-render', async ( {
		admin,
		page,
	} ) => {
		OVERFLOW_SLUGS.forEach( ( slug ) => ensureNetworkSite( slug ) );

		// Six sites online — the main site plus five — leaves one over the
		// widget's five, so the server renders the singular link.
		setNetworkPresence( { login: NETWORK_USERS.a.login, slug: SITE_SLUG } );
		OVERFLOW_SLUGS.slice( 0, 4 ).forEach( ( slug ) =>
			setNetworkPresence( {
				login: NETWORK_USERS.a.login,
				slug,
				client: `e2e-${ slug }`,
			} )
		);

		await admin.visitAdminPage( 'network/' );

		const moreLink = page.locator(
			'#presence-network-widget-list .presence-more-link'
		);

		await expect( moreLink ).toHaveText( '+1 more site — view all' );

		// A seventh site online takes the overflow to two, so the tick has to
		// redraw the link, and it has to still say what it counts.
		setNetworkPresence( {
			login: NETWORK_USERS.a.login,
			slug: OVERFLOW_SLUGS[ 4 ],
			client: `e2e-${ OVERFLOW_SLUGS[ 4 ] }`,
		} );
		await forceHeartbeatTick( page );

		// The seventh site is what the link counts, not something it shows, so
		// the link's own text is the only place the tick is visible.
		await expect( moreLink ).toHaveText( '+2 more sites — view all' );
	} );
} );
