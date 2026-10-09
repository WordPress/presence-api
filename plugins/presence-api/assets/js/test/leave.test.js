/**
 * Unit tests for the leave DELETE in presence-ping.js.
 *
 * @package Presence_API
 */

import { readFileSync } from 'node:fs';
import path from 'node:path';
import { describe, it, expect, afterEach, vi } from 'vitest';

// A classic script, so each load runs a fresh copy the way a <script> tag does.
const PRESENCE_PING = readFileSync(
	path.join( import.meta.dirname, '../presence-ping.js' ),
	'utf8'
);

/**
 * Loads presence-ping.js with one entry to leave and a stubbed fetch.
 *
 * @return {import('vitest').Mock} The fetch mock.
 */
function loadPing() {
	const $ = ( subject ) => {
		if ( typeof subject === 'function' ) {
			subject();
			return;
		}
		return { on() {} };
	};

	global.wp = {
		hooks: {
			addFilter() {},
			addAction() {},
			applyFilters: ( name, value ) => value,
			doAction() {},
		},
		heartbeat: { interval: () => 15, connectNow() {} },
	};
	window.pagenow = 'front';
	window.wpPresenceConfig = {
		restUrl: 'https://example.test/wp-json/wp-presence/v1/presence',
		nonce: 'nonce',
		entries: [ { room: 'admin/online', client_id: 'user-1' } ],
	};
	window.wpPresenceCreateTabCoordinator = () => ( { isLeader: () => true } );
	window.fetch = vi.fn();
	global.jQuery = $;

	new Function( PRESENCE_PING )();

	return window.fetch;
}

function pageshow( persisted ) {
	const event = new Event( 'pageshow' );
	event.persisted = persisted;
	window.dispatchEvent( event );
}

describe( 'leave', () => {
	afterEach( () => {
		delete global.wp;
		delete global.jQuery;
		delete window.fetch;
	} );

	it( 'leaves again after the page is restored from the back/forward cache', () => {
		const fetch = loadPing();

		window.dispatchEvent( new Event( 'pagehide' ) );
		pageshow( true );
		window.dispatchEvent( new Event( 'pagehide' ) );

		expect( fetch ).toHaveBeenCalledTimes( 2 );
		expect( fetch.mock.calls[ 1 ][ 1 ].method ).toBe( 'DELETE' );
	} );
} );
