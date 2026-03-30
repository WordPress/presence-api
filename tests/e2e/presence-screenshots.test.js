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

import { test, expect } from '@playwright/test';
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
 * Take a full-page screenshot.
 *
 * @param {import('@playwright/test').Page} page Playwright page.
 * @param {string}                          name Screenshot filename (without extension).
 */
async function snap( page, name ) {
	fs.mkdirSync( SCREENSHOTS_DIR, { recursive: true } );
	await page.screenshot( {
		path: path.join( SCREENSHOTS_DIR, `${ name }.png` ),
		fullPage: false,
	} );
}

/**
 * Take a screenshot of a specific element.
 *
 * @param {import('@playwright/test').Page} page     Playwright page.
 * @param {string}                          selector CSS selector.
 * @param {string}                          name     Screenshot filename.
 */
async function snapElement( page, selector, name ) {
	fs.mkdirSync( SCREENSHOTS_DIR, { recursive: true } );
	const element = page.locator( selector );
	if ( await element.isVisible().catch( () => false ) ) {
		await element.screenshot( {
			path: path.join( SCREENSHOTS_DIR, `${ name }.png` ),
		} );
	}
}

/**
 * Log in as admin via the login form.
 *
 * @param {import('@playwright/test').Page} page Playwright page.
 */
/**
 * Navigate to an admin page, logging in if needed, and fire a heartbeat.
 *
 * @param {import('@playwright/test').Page} page      Playwright page.
 * @param {string}                          adminPath Admin path (e.g. '/' or 'edit.php').
 */
async function visitAdmin( page, adminPath = '/' ) {
	const url = `${ BASE_URL }/wp-admin/${ adminPath }`;
	await page.goto( url );
	await page.waitForLoadState( 'load' );

	// If redirected to login, authenticate via POST request and set cookies.
	if ( page.url().includes( 'wp-login.php' ) ) {
		const response = await page.context().request.post( `${ BASE_URL }/wp-login.php`, {
			form: {
				log: 'admin',
				pwd: 'password',
				'wp-submit': 'Log In',
				redirect_to: `${ BASE_URL }/wp-admin/${ adminPath }`,
				testcookie: '1',
			},
		} );
		// Reload the target page with auth cookies now set.
		await page.goto( url );
		await page.waitForLoadState( 'networkidle' );
	}

	// Fire heartbeat to ensure presence data is current.
	await page.evaluate( () => {
		if ( typeof wp !== 'undefined' && wp.heartbeat ) {
			wp.heartbeat.connectNow();
		}
	} );
	await page.waitForTimeout( 3000 );
}

test.describe.serial( 'Presence Screenshots', () => {
	test.beforeAll( () => {
		wpCli( 'presence demo --cleanup' );
		wpCli( 'db query "TRUNCATE TABLE wp_presence"' );
	} );

	test.afterAll( () => {
		wpCli( 'presence demo --cleanup' );
	} );

	test( '01 — Empty state', async ( { page } ) => {
		wpCli( 'db query "TRUNCATE TABLE wp_presence"' );
		await visitAdmin( page );

		await snap( page, '01-empty-dashboard' );
		await snapElement( page, '#presence-whos-online-list', '01-empty-whos-online' );
		await snapElement( page, '#presence-active-posts-list', '01-empty-active-posts' );
	} );

	test( '02 — Active users (5)', async ( { page } ) => {
		wpCli( 'presence demo 5' );
		await visitAdmin( page );

		await snap( page, '02-active-dashboard' );
		await snapElement( page, '#presence-whos-online-list', '02-active-whos-online' );
		await snapElement( page, '#presence-active-posts-list', '02-active-active-posts' );

		// Admin bar indicator.
		await snapElement( page, '#wp-admin-bar-presence-online', '02-active-admin-bar' );

		// Open the dropdown by hovering.
		const barNode = page.locator( '#wp-admin-bar-presence-online' );
		if ( await barNode.isVisible().catch( () => false ) ) {
			await barNode.hover();
			await page.waitForTimeout( 500 );
			await snap( page, '02-active-admin-bar-dropdown' );
		}
	} );

	test( '03 — Active users (20)', async ( { page } ) => {
		wpCli( 'presence demo --cleanup' );
		wpCli( 'db query "TRUNCATE TABLE wp_presence"' );
		wpCli( 'presence demo 20' );
		await visitAdmin( page );

		await snap( page, '03-scale-dashboard' );
		await snapElement( page, '#presence-whos-online-list', '03-scale-whos-online' );
		await snapElement( page, '#presence-active-posts-list', '03-scale-active-posts' );
	} );

	test( '04 — Post list editors column', async ( { page } ) => {
		await visitAdmin( page, 'edit.php' );

		await snap( page, '04-post-list' );
	} );

	test( '05 — Users list online filter', async ( { page } ) => {
		await visitAdmin( page, 'users.php?presence_status=online' );

		await snap( page, '05-users-online' );
	} );

	test( '06 — Idle state', async ( { page } ) => {
		// Wait for entries to age past 30s idle threshold.
		await page.waitForTimeout( 35_000 );

		await visitAdmin( page );

		await snap( page, '06-idle-dashboard' );
		await snapElement( page, '#presence-whos-online-list', '06-idle-whos-online' );
		await snapElement( page, '#presence-active-posts-list', '06-idle-active-posts' );
	} );

	test( '07 — Expired (back to empty)', async ( { page } ) => {
		// Wait for TTL expiry.
		await page.waitForTimeout( 30_000 );

		await visitAdmin( page );

		await snap( page, '07-expired-dashboard' );

		wpCli( 'presence demo --cleanup' );
	} );
} );
