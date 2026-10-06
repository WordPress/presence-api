/**
 * Presence API — Feature Settings E2E Tests
 *
 * Saves a feature switch from the plugin's own page through options.php, the one path the PHPUnit suite cannot reach.
 *
 * Run from plugin root:
 *   npx playwright test --config tests/e2e/playwright.config.js tests/e2e/presence-feature-settings.test.js
 *
 * @package WordPress
 */
import { test, expect } from '@wordpress/e2e-test-utils-playwright';

test.describe( 'Presence feature settings', () => {
	test( 'a feature switched off on Settings > Presence API stays off after saving', async ( {
		admin,
		page,
	} ) => {
		const postLocks = page.locator( '#wp_presence_features_post-locks' );
		const save = page.getByRole( 'button', { name: 'Save Changes' } );

		await admin.visitAdminPage(
			'options-general.php',
			'page=presence-api'
		);
		await expect(
			page.getByRole( 'heading', { name: 'Presence API', level: 1 } )
		).toBeVisible();
		await expect( postLocks ).toBeChecked();

		try {
			await postLocks.uncheck();
			await save.click();

			await expect( postLocks ).not.toBeChecked();
		} finally {
			// Other specs rely on post locks, so the switch goes back on however this one ends.
			await admin.visitAdminPage(
				'options-general.php',
				'page=presence-api'
			);
			await postLocks.check();
			await save.click();
			await expect( postLocks ).toBeChecked();
		}
	} );

	test( 'Settings > General keeps only the recording switch', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( 'options-general.php' );

		await expect( page.locator( '#wp_presence_recording' ) ).toBeVisible();
		await expect(
			page.locator( '[name^="wp_presence_features"]' )
		).toHaveCount( 0 );
	} );
} );
