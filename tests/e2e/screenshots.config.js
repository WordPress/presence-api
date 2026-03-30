/**
 * Playwright config for screenshot generation only.
 * Runs headless with a fixed viewport for consistent artifacts.
 */
import path from 'node:path';
import { defineConfig } from '@playwright/test';

const pluginRoot = path.resolve( __dirname, '../..' );
const artifactsPath = path.join( pluginRoot, 'artifacts' );

export default defineConfig( {
	reporter: [ [ 'list' ] ],
	workers: 1,
	timeout: 300_000,
	testDir: '.',
	testMatch: 'presence-screenshots.test.js',
	outputDir: path.join( artifactsPath, 'test-results' ),
	use: {
		baseURL: process.env.WP_BASE_URL || 'http://localhost:8888',
		headless: true,
		viewport: { width: 1440, height: 900 },
		ignoreHTTPSErrors: true,
		locale: 'en-US',
	},
} );
