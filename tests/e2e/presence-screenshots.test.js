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
import { test as base, expect } from '@wordpress/e2e-test-utils-playwright';
import { execSync } from 'node:child_process';
import path from 'node:path';
import fs from 'node:fs';

const SCREENSHOTS_DIR = path.resolve( __dirname, '../../artifacts/screenshots' );

function wpCli( command ) {
	execSync( `npx wp-env run cli wp ${ command }`, {
		stdio: 'pipe',
		timeout: 30_000,
	} );
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

const test = base.extend( {} );

test.describe.serial( 'Presence Screenshots', () => {
	test.beforeAll( () => {
		wpCli( 'presence demo --cleanup' );
		wpCli( 'db query "TRUNCATE TABLE wp_presence"' );
	} );

	test.afterAll( () => {
		wpCli( 'presence demo --cleanup' );
	} );

	test( '01 — Empty state', async ( { admin, page } ) => {
		wpCli( 'db query "TRUNCATE TABLE wp_presence"' );
		await admin.visitAdminPage( '/' );
		await page.evaluate( () => wp.heartbeat.connectNow() );
		await page.waitForTimeout( 3000 );

		await snap( page, '01-empty-dashboard' );
		await snapElement( page, '#presence-whos-online-list', '01-empty-whos-online' );
		await snapElement( page, '#presence-active-posts-list', '01-empty-active-posts' );
	} );

	test( '02 — Active users (5)', async ( { admin, page } ) => {
		wpCli( 'presence demo 5' );
		await admin.visitAdminPage( '/' );
		await page.evaluate( () => wp.heartbeat.connectNow() );
		await page.waitForTimeout( 3000 );

		await snap( page, '02-active-dashboard' );
		await snapElement( page, '#presence-whos-online-list', '02-active-whos-online' );
		await snapElement( page, '#presence-active-posts-list', '02-active-active-posts' );
		await snapElement( page, '#wp-admin-bar-presence-online', '02-active-admin-bar' );

		const barNode = page.locator( '#wp-admin-bar-presence-online' );
		if ( await barNode.isVisible().catch( () => false ) ) {
			await barNode.hover();
			await page.waitForTimeout( 500 );
			await snap( page, '02-active-admin-bar-dropdown' );
		}
	} );

	test( '03 — Active users (20)', async ( { admin, page } ) => {
		wpCli( 'presence demo --cleanup' );
		wpCli( 'db query "TRUNCATE TABLE wp_presence"' );
		wpCli( 'presence demo 20' );
		await admin.visitAdminPage( '/' );
		await page.evaluate( () => wp.heartbeat.connectNow() );
		await page.waitForTimeout( 3000 );

		await snap( page, '03-scale-dashboard' );
		await snapElement( page, '#presence-whos-online-list', '03-scale-whos-online' );
		await snapElement( page, '#presence-active-posts-list', '03-scale-active-posts' );
	} );

	test( '04 — Post list editors column', async ( { admin, page } ) => {
		await admin.visitAdminPage( 'edit.php' );
		await page.waitForTimeout( 2000 );
		await snap( page, '04-post-list' );
	} );

	test( '05 — Users list online filter', async ( { admin, page } ) => {
		await admin.visitAdminPage( 'users.php?presence_status=online' );
		await page.waitForTimeout( 2000 );
		await snap( page, '05-users-online' );
	} );

	test( '06 — Idle state', async ( { admin, page } ) => {
		await page.waitForTimeout( 35_000 );
		await admin.visitAdminPage( '/' );
		await page.evaluate( () => wp.heartbeat.connectNow() );
		await page.waitForTimeout( 3000 );

		await snap( page, '06-idle-dashboard' );
		await snapElement( page, '#presence-whos-online-list', '06-idle-whos-online' );
		await snapElement( page, '#presence-active-posts-list', '06-idle-active-posts' );
	} );

	test( '07 — Expired (back to empty)', async ( { admin, page } ) => {
		await page.waitForTimeout( 30_000 );
		await admin.visitAdminPage( '/' );
		await page.evaluate( () => wp.heartbeat.connectNow() );
		await page.waitForTimeout( 3000 );

		await snap( page, '07-expired-dashboard' );
		wpCli( 'presence demo --cleanup' );
	} );
} );
