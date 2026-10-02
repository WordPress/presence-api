/**
 * Presence Scenes — Screenshot Artifacts
 *
 * Plays every scene in the library and, after each step, captures the screen
 * the step happened on with the debugger open. Outputs to
 * artifacts/screenshots/scenes/. CI skips these and keeps Playwright's
 * screenshot and trace of a failure instead.
 *
 * Run from plugin root:
 *   npm run test:scenes
 *
 * @package Presence_Scenes
 * @since 0.1.0
 */
import { test } from '@wordpress/e2e-test-utils-playwright';
import { expect } from '@playwright/test';
import { execSync, spawn } from 'node:child_process';
import path from 'node:path';
import fs from 'node:fs';

const LIBRARY_DIR = path.resolve(
	__dirname,
	'../../plugins/presence-scenes/library'
);

const SCREENSHOTS_DIR = path.resolve(
	__dirname,
	'../../artifacts/screenshots/scenes'
);

const scenes = fs
	.readdirSync( LIBRARY_DIR )
	.filter( ( file ) => file.endsWith( '.json' ) )
	.map( ( file ) =>
		JSON.parse( fs.readFileSync( path.join( LIBRARY_DIR, file ) ) )
	);

// The admin page for each place in wp_presence_scene_places().
const PLACES = {
	dashboard: '/',
	posts: 'edit.php',
	pages: 'edit.php?post_type=page',
	media: 'upload.php',
	comments: 'edit-comments.php',
	profile: 'profile.php',
};

/**
 * Returns the admin page to watch a step from, following the actors around.
 *
 * Post steps are watched from the Posts list, which shows locks and editors,
 * because opening the editor as the admin would take the lock itself.
 *
 * @param {Object} step A scene step.
 * @return {string|undefined} Admin page, or undefined to stay on the current one.
 */
function watchFrom( step ) {
	if ( step.place ) {
		if ( ! PLACES[ step.place ] ) {
			throw new Error( `No admin page for the ${ step.place } place.` );
		}
		return PLACES[ step.place ];
	}
	return step.post || step.step === 'write' ? PLACES.posts : undefined;
}

async function snap( admin, page, dir, name, step ) {
	const url = watchFrom( step );
	await ( url ? admin.visitAdminPage( url ) : page.reload() );
	await page.locator( '#wp-admin-bar-presence-debug' ).hover();
	await page
		.locator( '#wp-admin-bar-presence-debug .ab-sub-wrapper' )
		.waitFor( { state: 'visible' } );
	await page.screenshot( { path: path.join( dir, `${ name }.png` ) } );
}

test.describe.serial( 'Presence Scenes', () => {
	test.afterEach( () => {
		execSync( 'npx wp-env run cli wp presence scene stop', {
			stdio: 'pipe',
		} );
	} );

	for ( const scene of scenes ) {
		test( scene.title, async ( { admin, page } ) => {
			// The scene plays in real time, on top of the usual budget.
			test.setTimeout(
				test.info().timeout + scene.steps.at( -1 ).at * 1000
			);

			const dir = path.join( SCREENSHOTS_DIR, scene.name );
			if ( ! process.env.CI ) {
				fs.mkdirSync( dir, { recursive: true } );
			}
			await admin.visitAdminPage( '/' );

			const run = spawn( 'npx', [
				'wp-env',
				'run',
				'cli',
				'wp',
				'presence',
				'scene',
				'run',
				scene.name,
			] );

			let step = 0;
			let shots = Promise.resolve();
			let output = '';
			run.stdout.on( 'data', ( chunk ) => {
				const lines = ( output + chunk ).split( '\n' );
				output = lines.pop();
				for ( const line of lines.filter( ( l ) =>
					/^[✓✗] /.test( l )
				) ) {
					const name = [
						String( ++step ).padStart( 2, '0' ),
						line
							.slice( 2 )
							.toLowerCase()
							.replace( /[^a-z0-9]+/g, '-' )
							.replace( /^-|-$/g, '' ),
					].join( '-' );
					const played = scene.steps[ step - 1 ];
					if ( ! process.env.CI ) {
						shots = shots.then( () =>
							snap( admin, page, dir, name, played )
						);
					}
				}
			} );

			const code = await new Promise( ( resolve ) =>
				run.on( 'close', resolve )
			);
			await shots;

			expect( step ).toBe( scene.steps.length );
			expect( code ).toBe( 0 );
		} );
	}
} );
