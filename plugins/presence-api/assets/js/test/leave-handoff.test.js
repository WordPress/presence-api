/**
 * Unit tests for how presence-ping.js removes rows when the person leaves a page.
 *
 * @package Presence_API
 */

import { readFileSync } from 'node:fs';
import path from 'node:path';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

// A classic script, so each load runs a fresh copy the way a <script> tag does.
const PRESENCE_PING = readFileSync(
	path.join( import.meta.dirname, '../presence-ping.js' ),
	'utf8'
);

const ORIGIN = window.location.origin;
const REST_URL = `${ ORIGIN }/wp-json/wp-presence/v1/presence`;
const ADMIN = { room: 'admin/online', client_id: 'user-2' };
const EDITOR = { room: 'postType/post:7', client_id: 'editor-2' };

let added;
let fetch;

/**
 * Loads one page's copy of presence-ping.js.
 *
 * @param {Object} config What the page localizes as wpPresenceConfig.
 * @return {EventTarget} The page's window.navigation stand-in.
 */
function loadPage( config ) {
	unload();

	const navigation = new EventTarget();
	Object.defineProperty( window, 'navigation', {
		value: navigation,
		configurable: true,
	} );
	window.wpPresenceConfig = {
		restUrl: REST_URL,
		nonce: 'n',
		handoffUrls: [ `${ ORIGIN }/wp-admin/`, `${ ORIGIN }/` ],
		handoffSkipUrls: [ `${ ORIGIN }/wp-content/` ],
		...config,
	};
	new Function( PRESENCE_PING )();

	return navigation;
}

// Drops the last page's window listeners, as the browser does when it swaps the document.
function unload() {
	added.forEach( ( [ type, handler ] ) =>
		window.removeEventListener( type, handler )
	);
	added = [];
}

function navigate( navigation, url ) {
	const controller = new AbortController();
	const event = new Event( 'navigate' );
	Object.assign( event, {
		hashChange: false,
		downloadRequest: null,
		destination: { url, sameDocument: false },
		signal: controller.signal,
	} );
	navigation.dispatchEvent( event );
	return controller;
}

function deleted() {
	return fetch.mock.calls.map( ( [ url ] ) => {
		const params = new URL( url ).searchParams;
		return `${ params.get( 'room' ) } ${ params.get( 'client_id' ) }`;
	} );
}

describe( 'leaving a page', () => {
	beforeEach( () => {
		added = [];
		const addEventListener = window.addEventListener.bind( window );
		vi.spyOn( window, 'addEventListener' ).mockImplementation(
			( type, handler, options ) => {
				added.push( [ type, handler ] );
				addEventListener( type, handler, options );
			}
		);
		fetch = vi.fn();
		vi.stubGlobal( 'fetch', fetch );

		const hooks = {
			addAction() {},
			applyFilters: ( name, value ) => value,
		};
		global.wp = {
			hooks: { ...hooks, doAction() {} },
			heartbeat: { interval: () => 15, connectNow() {} },
		};
		window.wpPresenceCreateTabCoordinator = () => ( {
			isLeader: () => true,
		} );
		global.jQuery = ( subject ) =>
			typeof subject === 'function' ? subject() : { on() {} };
	} );

	afterEach( () => {
		unload();
		vi.restoreAllMocks();
		vi.unstubAllGlobals();
		window.sessionStorage.clear();
		delete window.navigation;
		delete global.wp;
		delete global.jQuery;
	} );

	it( 'keeps the row the next page writes when a save reloads the screen', () => {
		const navigation = loadPage( { entries: [ ADMIN ] } );
		navigate( navigation, `${ ORIGIN }/wp-admin/options.php` );
		window.dispatchEvent( new Event( 'pagehide' ) );

		loadPage( { entries: [ ADMIN ] } );

		expect( deleted() ).toEqual( [] );
	} );

	it( 'leaves it to the next page to remove the rooms it does not occupy', () => {
		const navigation = loadPage( { entries: [ ADMIN, EDITOR ] } );
		navigate( navigation, `${ ORIGIN }/wp-admin/edit.php` );
		window.dispatchEvent( new Event( 'pagehide' ) );
		expect( deleted() ).toEqual( [] );

		loadPage( { entries: [ ADMIN ] } );

		expect( deleted() ).toEqual( [ 'postType/post:7 editor-2' ] );
	} );

	it( 'removes the rows on the way out when the next page is another site in the network', () => {
		const navigation = loadPage( {
			entries: [ ADMIN ],
			handoffUrls: [ `${ ORIGIN }/other/wp-admin/` ],
		} );
		navigate( navigation, `${ ORIGIN }/wp-admin/` );
		window.dispatchEvent( new Event( 'pagehide' ) );

		expect( deleted() ).toEqual( [ 'admin/online user-2' ] );
	} );

	it( 'removes the rows on the way out for a file in uploads, which loads no script to take the handoff', () => {
		const navigation = loadPage( { entries: [ ADMIN ] } );
		navigate( navigation, `${ ORIGIN }/wp-content/uploads/report.pdf` );
		window.dispatchEvent( new Event( 'pagehide' ) );

		expect( deleted() ).toEqual( [ 'admin/online user-2' ] );
	} );

	it( 'removes every handed-off row at the old site when another site in the network takes it', () => {
		window.sessionStorage.setItem(
			'wpPresenceHandoff',
			JSON.stringify( {
				restUrl: REST_URL,
				entries: [ ADMIN ],
				at: Date.now(),
			} )
		);

		loadPage( {
			entries: [ ADMIN ],
			restUrl: `${ ORIGIN }/other/wp-json/wp-presence/v1/presence`,
		} );

		expect( fetch.mock.calls[ 0 ][ 0 ].href ).toContain( REST_URL );
		expect( deleted() ).toEqual( [ 'admin/online user-2' ] );
	} );

	it( 'removes the rows on the way out when the person leaves the site', () => {
		const navigation = loadPage( { entries: [ ADMIN ] } );
		navigate( navigation, 'https://example.org/' );
		window.dispatchEvent( new Event( 'pagehide' ) );

		expect( deleted() ).toEqual( [ 'admin/online user-2' ] );
	} );

	it( 'removes the rows on the way out when the tab closes', () => {
		loadPage( { entries: [ ADMIN ] } );
		window.dispatchEvent( new Event( 'pagehide' ) );

		expect( deleted() ).toEqual( [ 'admin/online user-2' ] );
	} );

	it( 'removes the rows on the way out when an earlier navigation was stopped', () => {
		const navigation = loadPage( { entries: [ ADMIN ] } );
		navigate( navigation, `${ ORIGIN }/wp-admin/edit.php` ).abort();
		window.dispatchEvent( new Event( 'pagehide' ) );

		expect( deleted() ).toEqual( [ 'admin/online user-2' ] );
		expect( window.sessionStorage.getItem( 'wpPresenceHandoff' ) ).toBe(
			null
		);
	} );

	it( 'removes the rooms a page restored from the back/forward cache does not occupy', () => {
		loadPage( { entries: [ ADMIN ] } );
		// The editor this page was left for hands off on the way back, but this page's script does not run again.
		window.sessionStorage.setItem(
			'wpPresenceHandoff',
			JSON.stringify( {
				restUrl: REST_URL,
				entries: [ ADMIN, EDITOR ],
				at: Date.now(),
			} )
		);
		window.dispatchEvent(
			Object.assign( new Event( 'pageshow' ), { persisted: true } )
		);

		expect( deleted() ).toEqual( [ 'postType/post:7 editor-2' ] );
	} );

	it( 'ignores a handoff older than the presence TTL', () => {
		window.sessionStorage.setItem(
			'wpPresenceHandoff',
			JSON.stringify( {
				restUrl: REST_URL,
				entries: [ EDITOR ],
				at: Date.now() - 200 * 1000,
			} )
		);

		loadPage( { entries: [ ADMIN ], ttl: 150 } );

		expect( deleted() ).toEqual( [] );
	} );
} );
