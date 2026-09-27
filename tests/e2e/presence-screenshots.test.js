/**
 * Presence API — Screenshot Artifacts
 *
 * Captures screenshots of every presence surface under different conditions.
 * Outputs to artifacts/screenshots/.
 *
 * Run from plugin root:
 *   npx playwright test --config tests/e2e/playwright.config.js presence-screenshots.test.js
 *
 * @package WordPress
 * @since 7.1.0
 */
import { test as base } from '@wordpress/e2e-test-utils-playwright';
import { expect } from '@playwright/test';
import { execSync } from 'node:child_process';
import path from 'node:path';
import fs from 'node:fs';

const SCREENSHOTS_DIR = path.resolve(
	__dirname,
	'../../artifacts/screenshots'
);

function wpCli( command ) {
	execSync( `npx wp-env run cli wp ${ command }`, {
		stdio: 'pipe',
		timeout: 30_000,
	} );
}

const SEEDER_PATH =
	'/var/www/html/wp-content/plugins/presence-api/tests/e2e/demo-seeder.php';

/**
 * Calls a demo-seeder function inside the container, as the Playground blueprint does.
 *
 * @param {string} php Statement to run after the seeder is loaded.
 */
function demoSeeder( php ) {
	wpCli( `eval 'require "${ SEEDER_PATH }"; ${ php }'` );
}

/**
 * Ages the seeded editor rows one second past a threshold the plugin reports.
 *
 * @param {string} seconds PHP expression for the threshold, in seconds.
 */
function backdateEditors( seconds ) {
	wpCli(
		`eval 'global $wpdb; $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->presence} SET date_gmt = %s WHERE room LIKE %s AND client_id LIKE %s", gmdate( "Y-m-d H:i:s", time() - ${ seconds } - 1 ), "postType/%", "editor-%" ) );'`
	);
}

async function snap( page, name ) {
	fs.mkdirSync( SCREENSHOTS_DIR, { recursive: true } );
	await page.screenshot( {
		path: path.join( SCREENSHOTS_DIR, `${ name }.png` ),
		fullPage: false,
	} );
}

async function snapElement( page, selector, name ) {
	fs.mkdirSync( SCREENSHOTS_DIR, { recursive: true } );
	const element = page.locator( selector );
	if ( await element.isVisible().catch( () => false ) ) {
		await element.screenshot( {
			path: path.join( SCREENSHOTS_DIR, `${ name }.png` ),
		} );
	}
}

async function connectHeartbeat( page ) {
	const heartbeatResponse = page.waitForResponse( ( response ) => {
		const request = response.request();
		return (
			response.url().includes( '/wp-admin/admin-ajax.php' ) &&
			request.method() === 'POST' &&
			new URLSearchParams( request.postData() ?? '' ).get( 'action' ) ===
				'heartbeat'
		);
	} );
	await page.evaluate( () => wp.heartbeat.connectNow() );
	await heartbeatResponse;
}

const test = base.extend( {} );

test.describe.serial( 'Presence Screenshots', () => {
	test.beforeAll( () => {
		demoSeeder( 'wp_presence_demo_cleanup();' );
		wpCli( 'db query "TRUNCATE TABLE wp_presence"' );
	} );

	test.afterAll( () => {
		demoSeeder( 'wp_presence_demo_cleanup();' );
	} );

	test( '01 — Empty state', async ( { admin, page } ) => {
		wpCli( 'db query "TRUNCATE TABLE wp_presence"' );
		await admin.visitAdminPage( '/' );
		await connectHeartbeat( page );

		await snap( page, '01-empty-dashboard' );
		await snapElement(
			page,
			'#presence-active-posts-list',
			'01-empty-active-posts'
		);
	} );

	test( '02 — Active users (5)', async ( { admin, page } ) => {
		demoSeeder( 'wp_presence_demo_seed( 5 );' );
		await admin.visitAdminPage( '/' );
		await connectHeartbeat( page );

		await snap( page, '02-active-dashboard' );
		await snapElement(
			page,
			'#presence-active-posts-list',
			'02-active-active-posts'
		);
		await snapElement(
			page,
			'#wp-admin-bar-presence-online',
			'02-active-admin-bar'
		);

		const barNode = page.locator( '#wp-admin-bar-presence-online' );
		if ( await barNode.isVisible().catch( () => false ) ) {
			await barNode.hover();
			await page
				.locator( '#wp-admin-bar-presence-online .ab-sub-wrapper' )
				.waitFor( { state: 'visible' } );
			await snap( page, '02-active-admin-bar-dropdown' );
		}
	} );

	test( '03 — Active users (20)', async ( { admin, page } ) => {
		demoSeeder( 'wp_presence_demo_cleanup();' );
		wpCli( 'db query "TRUNCATE TABLE wp_presence"' );
		demoSeeder( 'wp_presence_demo_seed( 20 );' );
		await admin.visitAdminPage( '/' );
		await connectHeartbeat( page );

		await snap( page, '03-scale-dashboard' );
		await snapElement(
			page,
			'#presence-active-posts-list',
			'03-scale-active-posts'
		);
	} );

	test( '04 — Post list editors column', async ( { admin, page } ) => {
		await admin.visitAdminPage( 'edit.php' );
		await page.locator( '#the-list' ).waitFor();
		await snap( page, '04-post-list' );
	} );

	test( '05 — Users list online filter', async ( { admin, page } ) => {
		await admin.visitAdminPage( 'users.php?presence_status=online' );
		await page.locator( '#the-list' ).waitFor();
		await snap( page, '05-users-online' );
	} );

	test( '06 — Idle state', async ( { admin, page } ) => {
		backdateEditors( 'wp_presence_idle_threshold()' );
		await admin.visitAdminPage( '/' );
		await connectHeartbeat( page );
		await expect(
			page.locator( '#presence-active-posts-list' )
		).toContainText( 'Idle' );

		await snap( page, '06-idle-dashboard' );
		await snapElement(
			page,
			'#presence-active-posts-list',
			'06-idle-active-posts'
		);
	} );

	test( '07 — Expired (back to empty)', async ( { admin, page } ) => {
		backdateEditors( 'wp_presence_get_timeout()' );
		await admin.visitAdminPage( '/' );
		await connectHeartbeat( page );

		await snap( page, '07-expired-dashboard' );
		demoSeeder( 'wp_presence_demo_cleanup();' );
	} );
} );
