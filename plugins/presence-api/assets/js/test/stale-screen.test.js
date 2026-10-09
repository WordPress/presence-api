/**
 * Unit tests for the stale-screen notice in stale-screen.js.
 *
 * @package Presence_API
 */

import { readFileSync } from 'node:fs';
import path from 'node:path';
import { describe, it, expect, afterEach, vi } from 'vitest';

// A classic script, so each load runs a fresh copy the way a <script> tag does.
const STALE_SCREEN = readFileSync(
	path.join( import.meta.dirname, '../stale-screen.js' ),
	'utf8'
);

let listeners;

/**
 * Loads stale-screen.js against a fake jQuery bus on a screen at a revision.
 *
 * @param {Object}  [config] Overrides for window.wpPresenceStaleScreen.
 * @param {boolean} [leader] Whether this tab leads its coordinator.
 * @param {Object}  [ajax]   Stand-in for $.ajax.
 */
function loadStaleScreen( config = {}, leader = true, ajax = vi.fn() ) {
	listeners = {};
	const $ = ( subject ) => ( {
		on( eventName, handler ) {
			( listeners[ eventName ] = listeners[ eventName ] || [] ).push(
				handler
			);
		},
		subject,
	} );
	$.ajax = ajax;
	$.Deferred = () => {
		let reason;
		return {
			reject( value ) {
				reason = value;
				return this;
			},
			promise() {
				return { rejected: reason };
			},
		};
	};

	global.wp = {
		heartbeat: {},
		i18n: {
			__: ( text ) => text,
			sprintf: ( format, ...args ) =>
				format
					.replace( /%(\d)\$s/g, ( match, n ) => args[ n - 1 ] )
					.replace( '%s', args[ 0 ] ),
		},
	};
	window.wpPresenceStaleScreen = {
		screenKey: 'options/general',
		baselineRev: 100,
		...config,
	};
	window.wpPresenceCreateTabCoordinator = () => ( {
		isLeader: () => leader,
	} );
	document.body.innerHTML =
		'<div class="wrap"><h1>General</h1><hr class="wp-header-end"><form></form></div>';

	new Function( 'jQuery', STALE_SCREEN )( $ );
}

function trigger( eventName, data ) {
	( listeners[ eventName ] || [] ).forEach( ( handler ) =>
		handler( { type: eventName }, data )
	);
}

function tick( rev, info = {} ) {
	trigger( 'heartbeat-tick', {
		'presence-screen-rev': {
			key: 'options/general',
			rev,
			time_ago: '2 minutes ago',
			...info,
		},
	} );
}

function notices() {
	return document.querySelectorAll( '.wp-presence-stale-notice' );
}

describe( 'stale screen', () => {
	afterEach( () => {
		document.body.innerHTML = '';
		delete global.wp;
		delete window.wpPresenceStaleScreen;
	} );

	it( 'asks after its screen only from the leading tab', () => {
		loadStaleScreen();
		const data = {};
		trigger( 'heartbeat-send', data );
		expect( data[ 'presence-screen-ping' ] ).toEqual( {
			key: 'options/general',
		} );

		loadStaleScreen( {}, false );
		const follower = {};
		trigger( 'heartbeat-send', follower );
		expect( follower ).toEqual( {} );
	} );

	it( 'names who changed the screen, below the heading', () => {
		loadStaleScreen();

		tick( 101, { actor_name: 'Ada' } );

		const notice = notices()[ 0 ];
		expect( notice.previousElementSibling.tagName ).toBe( 'H1' );
		expect( notice.textContent ).toContain(
			'Ada updated this screen 2 minutes ago.'
		);
	} );

	it( 'falls back to an unnamed message without an actor', () => {
		loadStaleScreen();

		tick( 101 );

		expect( notices()[ 0 ].textContent ).toContain(
			'This screen was updated 2 minutes ago.'
		);
	} );

	it( 'ignores revisions the page already has and other screens', () => {
		loadStaleScreen();

		tick( 100 );
		tick( 101, { key: 'options/reading' } );

		expect( notices() ).toHaveLength( 0 );
	} );

	it( 'takes its own save as the new baseline instead of warning', () => {
		loadStaleScreen();

		tick( 101, { actor_is_me: true } );
		tick( 101 );

		expect( notices() ).toHaveLength( 0 );
	} );

	it( 'shows one notice however many changes follow', () => {
		loadStaleScreen();

		tick( 101 );
		tick( 102 );

		expect( notices() ).toHaveLength( 1 );
	} );

	it( 'stays dismissed until a newer change arrives', () => {
		loadStaleScreen();

		tick( 101 );
		notices()[ 0 ].querySelector( '.notice-dismiss' ).click();
		tick( 101 );
		expect( notices() ).toHaveLength( 0 );

		tick( 102 );
		expect( notices() ).toHaveLength( 1 );
	} );

	it( 'posts a stale mark with the REST nonce, or refuses without one', () => {
		const ajax = vi.fn();
		loadStaleScreen( { restUrl: '/stale', nonce: 'abc' }, true, ajax );

		window.wp.presence.markScreenStale( 'options/general' );

		const request = ajax.mock.calls[ 0 ][ 0 ];
		const xhr = { setRequestHeader: vi.fn() };
		request.beforeSend( xhr );
		expect( request ).toMatchObject( {
			url: '/stale',
			method: 'POST',
			data: { screen_key: 'options/general' },
		} );
		expect( xhr.setRequestHeader ).toHaveBeenCalledWith(
			'X-WP-Nonce',
			'abc'
		);

		loadStaleScreen();
		expect( window.wp.presence.markScreenStale( 'x' ) ).toEqual( {
			rejected: 'Missing REST configuration.',
		} );
	} );
} );
