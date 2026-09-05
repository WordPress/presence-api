/**
 * Presence API — Admin Bar Contrast E2E Tests
 *
 * Verifies that presence text remains readable when the Light admin color
 * scheme changes the admin bar submenu from a dark to a white background.
 *
 * @package WordPress
 * @since 7.1.0
 */
import { test as base, expect } from '@wordpress/e2e-test-utils-playwright';
import { execSync } from 'node:child_process';

const SEEDER_PATH =
	'/var/www/html/wp-content/plugins/presence-api/tests/e2e/demo-seeder.php';

function wpCli( command ) {
	execSync( `npx wp-env run cli wp ${ command }`, {
		stdio: 'pipe',
		timeout: 30_000,
	} );
}

function demoSeeder( php ) {
	wpCli( `eval 'require "${ SEEDER_PATH }"; ${ php }'` );
}

function relativeLuminance( rgb ) {
	const channels = rgb
		.match( /\d+(?:\.\d+)?/g )
		.slice( 0, 3 )
		.map( ( value ) => {
			const channel = Number( value ) / 255;
			return channel <= 0.04045
				? channel / 12.92
				: ( ( channel + 0.055 ) / 1.055 ) ** 2.4;
		} );

	return (
		0.2126 * channels[ 0 ] + 0.7152 * channels[ 1 ] + 0.0722 * channels[ 2 ]
	);
}

function contrastRatio( foreground, background ) {
	const lighter = Math.max(
		relativeLuminance( foreground ),
		relativeLuminance( background )
	);
	const darker = Math.min(
		relativeLuminance( foreground ),
		relativeLuminance( background )
	);

	return ( lighter + 0.05 ) / ( darker + 0.05 );
}

async function computedColors( locator ) {
	return locator.evaluate( ( element ) => {
		const foreground = getComputedStyle( element ).color;
		let current = element;

		while ( current ) {
			const background = getComputedStyle( current ).backgroundColor;
			if (
				background !== 'rgba(0, 0, 0, 0)' &&
				background !== 'transparent'
			) {
				return { foreground, background };
			}
			current = current.parentElement;
		}

		return { foreground, background: 'rgb(255, 255, 255)' };
	} );
}

const test = base.extend( {} );

test.describe.serial( 'Presence admin bar contrast', () => {
	test.beforeAll( () => {
		demoSeeder( 'wp_presence_demo_seed( 5 );' );
		wpCli( 'user meta update admin admin_color light' );
	} );

	test.afterAll( () => {
		wpCli( 'user meta update admin admin_color fresh' );
		demoSeeder( 'wp_presence_demo_cleanup();' );
	} );

	test( 'Light scheme text meets WCAG AA contrast', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( '/' );

		const barNode = page.locator( '#wp-admin-bar-presence-online' );
		await expect( barNode ).toBeVisible();
		await barNode.hover();

		const text = [
			page.locator( '.presence-bar-count' ).first(),
			page.locator( '.presence-bar-you' ).first(),
			page.locator( '.presence-bar-screen' ).first(),
			page.locator( '.presence-bar-group-label' ).first(),
		];

		for ( const element of text ) {
			await expect( element ).toBeVisible();
			const { foreground, background } = await computedColors( element );
			expect(
				contrastRatio( foreground, background )
			).toBeGreaterThanOrEqual( 4.5 );
		}
	} );
} );
