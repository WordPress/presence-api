/**
 * Presence API — Network Admin Markup Validity E2E Tests
 *
 * Network Admin counterpart of presence-markup-validity.test.js (#824).
 *
 * Run from plugin root:
 *   npx playwright test --config tests/e2e/playwright.config.js --project=chromium-multisite tests/e2e/presence-network-markup-validity.test.js
 *
 * @package WordPress
 */
import { test, expect } from '@wordpress/e2e-test-utils-playwright';
import { markupErrors } from './markup-helpers';
import {
	NETWORK_USERS,
	SITE_SLUG,
	clearNetworkPresence,
	networkSiteId,
	setNetworkPresence,
} from './network-helpers';

let teamSiteId;

test.describe( 'Network Admin Markup Validity', () => {
	test.beforeAll( () => {
		teamSiteId = networkSiteId( SITE_SLUG );
	} );

	test.beforeEach( () => {
		clearNetworkPresence();
		setNetworkPresence( {
			login: NETWORK_USERS.a.login,
			slug: SITE_SLUG,
			client: 'e2e-a',
		} );
		setNetworkPresence( {
			login: NETWORK_USERS.b.login,
			slug: SITE_SLUG,
			client: 'e2e-b',
		} );
	} );

	test.afterAll( () => {
		clearNetworkPresence();
	} );

	test( "the Who's Online widget is valid", async ( { admin, page } ) => {
		await admin.visitAdminPage( 'network/' );
		await expect(
			page.locator(
				`#presence-network-widget-list [data-blog-id="${ teamSiteId }"]`
			)
		).toBeVisible();

		expect(
			await markupErrors( page, '#presence_network_whos_online' )
		).toEqual( [] );
	} );

	test( 'the Online column on Sites is valid', async ( { admin, page } ) => {
		await admin.visitAdminPage( 'network/sites.php' );
		await expect(
			page
				.locator( '#the-list tr' )
				.filter( { has: page.locator( `#blog_${ teamSiteId }` ) } )
				.locator( '.column-presence_online .presence-avatar-stack img' )
		).toHaveCount( 2 );

		expect(
			await markupErrors( page, '#the-list .column-presence_online' )
		).toEqual( [] );
	} );

	test( 'the Online view and column on Users are valid', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( 'network/users.php' );
		await expect(
			page.locator( '#the-list .column-presence_online' )
		).toContainText( [ `/${ SITE_SLUG }/` ] );

		expect(
			await markupErrors( page, '.subsubsub .presence_online' )
		).toEqual( [] );
		expect(
			await markupErrors( page, '#the-list .column-presence_online' )
		).toEqual( [] );
	} );

	test( 'the network feature settings page is valid', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage(
			'network/settings.php',
			'page=presence-api'
		);

		expect( await markupErrors( page, '#wpbody-content .wrap' ) ).toEqual(
			[]
		);
	} );
} );
