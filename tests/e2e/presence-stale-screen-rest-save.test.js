/**
 * Presence API — Stale-Screen REST Save E2E Tests
 *
 * Asserts a setting saved through /wp/v2/settings, as the Site Title block
 * saves it, shows the stale-screen banner to whoever has the Settings screen
 * for that setting open.
 *
 * The save comes from a second administrator over HTTP, with its own cookie
 * and nonce, so it runs as a real REST request rather than through the admin.
 *
 * Run from plugin root:
 *   npx playwright test --config tests/e2e/playwright.config.js tests/e2e/presence-stale-screen-rest-save.test.js
 *
 * @package WordPress
 */
import {
	test as base,
	expect,
	RequestUtils,
} from '@wordpress/e2e-test-utils-playwright';

const OTHER_ADMIN = {
	username: 'presencetestrestadmin',
	email: 'presencetestrestadmin@example.com',
	firstName: 'Rest',
	lastName: 'Admin',
	password: 'password',
	roles: [ 'administrator' ],
};

const test = base.extend( {
	otherAdmin: [
		async ( { requestUtils }, use ) => {
			await requestUtils.createUser( OTHER_ADMIN ).catch( ( error ) => {
				if ( error?.code !== 'existing_user_login' ) {
					throw error;
				}
			} );
			await use(
				await RequestUtils.setup( {
					user: {
						username: OTHER_ADMIN.username,
						password: OTHER_ADMIN.password,
					},
				} )
			);
			await requestUtils.deleteAllUsers();
		},
		{ scope: 'test' },
	],
} );

test.describe( 'Presence Stale-Screen REST Save', () => {
	let originalTitle;

	test.beforeEach( async ( { requestUtils } ) => {
		originalTitle = ( await requestUtils.getSiteSettings() ).title;
	} );

	test.afterEach( async ( { requestUtils } ) => {
		await requestUtils.updateSiteSettings( { title: originalTitle } );
	} );

	test( 'warns an open Settings screen when another user saves its setting over REST', async ( {
		page,
		otherAdmin,
	} ) => {
		await page.goto( '/wp-admin/options-general.php' );
		await page.waitForFunction(
			() =>
				typeof wp !== 'undefined' &&
				wp.heartbeat &&
				wp.heartbeat.connectNow
		);

		await otherAdmin.updateSiteSettings( {
			title: 'Changed via REST',
		} );

		await Promise.all( [
			expect( page.locator( '.wp-presence-stale-notice' ) ).toContainText(
				'Rest Admin updated this screen',
				{
					timeout: 8000,
				}
			),
			page.evaluate( () => wp.heartbeat.connectNow() ),
		] );
	} );
} );
