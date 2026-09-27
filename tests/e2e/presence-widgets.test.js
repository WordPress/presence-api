/**
 * Presence API — Widget E2E Tests
 *
 * Tests presence scenarios in the Active Posts dashboard widget.
 *
 * Run from plugin root:
 *   npx playwright test --config tests/e2e/playwright.config.js
 *
 * @package WordPress
 * @since 7.1.0
 */
import { test, expect } from '@wordpress/e2e-test-utils-playwright';
import { execSync } from 'node:child_process';

function wpCli( command ) {
	execSync( `npx wp-env run cli wp ${ command }`, {
		stdio: 'pipe',
		timeout: 30_000,
	} );
}

test.describe( 'Presence Widgets', () => {
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
			`eval 'global $wpdb; $wpdb->update( $wpdb->presence, array( "date_gmt" => gmdate( "Y-m-d H:i:s", time() - wp_presence_idle_threshold() - 1 ) ), array( "client_id" => "session-a" ), array( "%s" ), array( "%s" ) );'`
		);
		await page.evaluate( () => wp.heartbeat.connectNow() );

		await expect(
			page.locator( '#presence-active-posts-list' )
		).toContainText( 'Idle', { timeout: 30_000 } );
		await expect( postLink ).toBeFocused();
	} );
} );
