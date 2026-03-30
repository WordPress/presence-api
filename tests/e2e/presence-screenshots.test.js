/**
 * Presence API — Screenshot Artifacts
 *
 * Captures screenshots of every presence surface under different conditions:
 * empty state, active users, idle state, and post editing.
 *
 * Outputs to artifacts/screenshots/ for documentation, PRs, and GIF generation.
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

const BASE_URL = process.env.WP_BASE_URL || 'http://localhost:8888';
const SCREENSHOTS_DIR = path.resolve( __dirname, '../../artifacts/screenshots' );

/**
 * Run a WP-CLI command inside the wp-env container.
 *
 * @param {string} command The WP-CLI command (without 'wp' prefix).
 */
function wpCli( command ) {
	execSync( `npx wp-env run cli wp ${ command }`, {
		stdio: 'pipe',
		timeout: 30_000,
	} );
}

/**
 * Take a screenshot and save to the artifacts directory.
 *
 * @param {import('@playwright/test').Page} page Playwright page.
 * @param {string}                          name Screenshot filename (without extension).
 */
async function screenshot( page, name ) {
	fs.mkdirSync( SCREENSHOTS_DIR, { recursive: true } );
	await page.screenshot( {
		path: path.join( SCREENSHOTS_DIR, `${ name }.png` ),
		fullPage: false,
	} );
}

/**
 * Take a screenshot of a specific element.
 *
 * @param {import('@playwright/test').Page}    page     Playwright page.
 * @param {string}                             selector CSS selector.
 * @param {string}                             name     Screenshot filename.
 */
async function elementScreenshot( page, selector, name ) {
	fs.mkdirSync( SCREENSHOTS_DIR, { recursive: true } );
	const element = page.locator( selector );
	await expect( element ).toBeVisible( { timeout: 10_000 } );
	await element.screenshot( {
		path: path.join( SCREENSHOTS_DIR, `${ name }.png` ),
	} );
}

const test = base.extend( {} );

test.describe( 'Presence Screenshots', () => {
	test.beforeAll( () => {
		// Clean slate.
		wpCli( 'presence demo --cleanup' );
		wpCli( 'db query "TRUNCATE TABLE wp_presence"' );
	} );

	test.afterAll( () => {
		wpCli( 'presence demo --cleanup' );
		wpCli( 'db query "TRUNCATE TABLE wp_presence"' );
	} );

	test( '01 — Empty state', async ( { admin, page } ) => {
		wpCli( 'db query "TRUNCATE TABLE wp_presence"' );
		await admin.visitAdminPage( '/' );

		// Wait for first heartbeat to fire so widgets render.
		await page.evaluate( () => wp.heartbeat.connectNow() );
		await page.waitForTimeout( 2000 );

		await screenshot( page, '01-empty-dashboard' );
		await elementScreenshot( page, '#presence-whos-online-list', '01-empty-whos-online' );
		await elementScreenshot( page, '#presence-active-posts-list', '01-empty-active-posts' );
	} );

	test( '02 — Active users (5)', async ( { admin, page } ) => {
		wpCli( 'presence demo 5' );
		await admin.visitAdminPage( '/' );
		await page.evaluate( () => wp.heartbeat.connectNow() );
		await page.waitForTimeout( 2000 );

		await screenshot( page, '02-active-dashboard' );
		await elementScreenshot( page, '#presence-whos-online-list', '02-active-whos-online' );
		await elementScreenshot( page, '#presence-active-posts-list', '02-active-active-posts' );

		// Admin bar.
		const adminBarNode = page.locator( '#wp-admin-bar-presence-online' );
		if ( await adminBarNode.isVisible().catch( () => false ) ) {
			await elementScreenshot( page, '#wp-admin-bar-presence-online', '02-active-admin-bar' );

			// Open the dropdown.
			await adminBarNode.hover();
			await page.waitForTimeout( 500 );
			await screenshot( page, '02-active-admin-bar-dropdown' );
		}
	} );

	test( '03 — Active users (20)', async ( { admin, page } ) => {
		wpCli( 'presence demo --cleanup' );
		wpCli( 'db query "TRUNCATE TABLE wp_presence"' );
		wpCli( 'presence demo 20' );
		await admin.visitAdminPage( '/' );
		await page.evaluate( () => wp.heartbeat.connectNow() );
		await page.waitForTimeout( 2000 );

		await screenshot( page, '03-scale-dashboard' );
		await elementScreenshot( page, '#presence-whos-online-list', '03-scale-whos-online' );
		await elementScreenshot( page, '#presence-active-posts-list', '03-scale-active-posts' );
	} );

	test( '04 — Post list editors column', async ( { admin, page } ) => {
		await admin.visitAdminPage( 'edit.php' );
		await page.waitForTimeout( 2000 );

		await screenshot( page, '04-post-list' );
	} );

	test( '05 — Users list online filter', async ( { admin, page } ) => {
		await admin.visitAdminPage( 'users.php?presence_status=online' );
		await page.waitForTimeout( 2000 );

		await screenshot( page, '05-users-online' );
	} );

	test( '06 — Idle state', async ( { admin, page } ) => {
		// Don't refresh demo users — let them age past 30s idle threshold.
		await page.waitForTimeout( 35_000 );

		await admin.visitAdminPage( '/' );
		await page.evaluate( () => wp.heartbeat.connectNow() );
		await page.waitForTimeout( 2000 );

		await screenshot( page, '06-idle-dashboard' );
		await elementScreenshot( page, '#presence-whos-online-list', '06-idle-whos-online' );
		await elementScreenshot( page, '#presence-active-posts-list', '06-idle-active-posts' );
	} );

	test( '07 — Expired (back to empty)', async ( { page, admin } ) => {
		// Wait for TTL expiry (60s total from last refresh, ~25s remaining after idle wait).
		await page.waitForTimeout( 30_000 );

		await admin.visitAdminPage( '/' );
		await page.evaluate( () => wp.heartbeat.connectNow() );
		await page.waitForTimeout( 2000 );

		await screenshot( page, '07-expired-dashboard' );

		// Cleanup.
		wpCli( 'presence demo --cleanup' );
	} );
} );
