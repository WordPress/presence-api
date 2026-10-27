/**
 * Unit tests for the live surface registry in presence-ping.js.
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

let listeners;

/**
 * Builds the slice of wp.hooks presence-ping.js uses.
 *
 * @return {Object} Hooks stand-in.
 */
function createHooks() {
	const filters = {};
	const actions = {};

	return {
		addFilter( name, namespace, callback ) {
			( filters[ name ] = filters[ name ] || [] ).push( callback );
			this.doAction( 'hookAdded', name );
		},
		removeFilter( name ) {
			delete filters[ name ];
			this.doAction( 'hookRemoved', name );
		},
		addAction( name, namespace, callback ) {
			( actions[ name ] = actions[ name ] || [] ).push( callback );
		},
		applyFilters( name, value ) {
			return ( filters[ name ] || [] ).reduce(
				( result, callback ) => callback( result ),
				value
			);
		},
		doAction( name, ...args ) {
			( actions[ name ] || [] ).forEach( ( callback ) =>
				callback( ...args )
			);
		},
	};
}

/**
 * Loads presence-ping.js against a fake jQuery bus and a tab coordinator.
 *
 * @param {Object}  options          Options.
 * @param {boolean} options.isLeader Whether this tab leads. Default true.
 * @param {Object}  options.config   What the page localizes as wpPresenceConfig.
 * @return {Object} The hooks instance the script registered against.
 */
function loadPing( { isLeader = true, config = {} } = {} ) {
	listeners = {};
	const hooks = createHooks();

	const $ = ( subject ) => {
		if ( typeof subject === 'function' ) {
			subject();
			return;
		}
		return {
			on( eventName, handler ) {
				listeners[ eventName ] = listeners[ eventName ] || [];
				listeners[ eventName ].push( handler );
			},
		};
	};

	global.wp = {
		hooks,
		heartbeat: { interval: () => 15, connectNow() {} },
	};
	window.pagenow = 'dashboard';
	window.wpPresenceConfig = config;
	window.wpPresenceCreateTabCoordinator = () => ( {
		isLeader: () => isLeader,
	} );
	global.jQuery = $;

	new Function( PRESENCE_PING )();

	return hooks;
}

function trigger( eventName, data ) {
	( listeners[ eventName ] || [] ).forEach( ( handler ) =>
		handler( { type: eventName }, data )
	);
}

function send() {
	const data = {};
	trigger( 'heartbeat-send', data );
	return data[ 'presence-fragments' ];
}

describe( 'live surfaces', () => {
	afterEach( () => {
		vi.restoreAllMocks();
		vi.unstubAllGlobals();
		document.body.innerHTML = '';
		delete global.wp;
		delete global.jQuery;
	} );

	it( 'asks only for surfaces on the page', () => {
		document.body.innerHTML = '<div id="presence-active-posts-list"></div>';
		loadPing();

		expect( send() ).toEqual( { 'active-posts': true } );
	} );

	it( 'swaps changed HTML once and leaves a busy surface alone', () => {
		document.body.innerHTML =
			'<div id="presence-active-posts-list"><a href="#">Old</a></div>';
		const hooks = loadPing();
		const updated = vi.fn();
		hooks.addAction( 'presence-api.surfaceUpdated', 'test', updated );
		const list = document.getElementById( 'presence-active-posts-list' );

		list.querySelector( 'a' ).focus();
		trigger( 'heartbeat-tick', {
			'presence-fragments': { 'active-posts': '<p>New</p>' },
		} );
		expect( list.innerHTML ).toBe( '<a href="#">Old</a>' );

		list.querySelector( 'a' ).blur();
		trigger( 'heartbeat-tick', {
			'presence-fragments': { 'active-posts': '<p>New</p>' },
		} );
		trigger( 'heartbeat-tick', {
			'presence-fragments': { 'active-posts': '<p>New</p>' },
		} );
		expect( list.innerHTML ).toBe( '<p>New</p>' );
		expect( updated ).toHaveBeenCalledTimes( 1 );
		expect( updated ).toHaveBeenCalledWith( 'active-posts', list );
	} );

	it( 'keeps a surface another script registers current', () => {
		document.body.innerHTML = '<span id="elsewhere"></span>';
		const hooks = loadPing();
		hooks.addFilter( 'presence-api.liveSurfaces', 'test', ( surfaces ) => [
			...surfaces,
			{
				key: 'elsewhere',
				target: () => document.getElementById( 'elsewhere' ),
			},
		] );

		expect( send() ).toEqual( { elsewhere: true } );

		trigger( 'heartbeat-tick', {
			'presence-fragments': { elsewhere: '3 online' },
		} );
		expect( document.getElementById( 'elsewhere' ).innerHTML ).toBe(
			'3 online'
		);
	} );

	it( 'hands each filter run a fresh list, so a callback that pushes does not pile up', () => {
		const hooks = loadPing();
		const lengths = [];
		hooks.addFilter( 'presence-api.liveSurfaces', 'test', ( surfaces ) => {
			lengths.push( surfaces.length );
			surfaces.push( { key: 'pushed', target: () => null } );
			return surfaces;
		} );

		send();
		send();

		expect( lengths[ 0 ] ).toBe( lengths[ 1 ] );
	} );

	it( 'flags both pings and skips the unload DELETE while a transport holds the rows', () => {
		const hooks = loadPing( {
			config: {
				editorPostId: 5,
				restUrl: 'https://example.test/wp-json/wp/v2/presence',
				entries: [ { room: 'admin/online', client_id: 'user-1' } ],
			},
		} );
		hooks.addFilter( 'presence-api.keepalive', 'test', () => false );
		const fetch = vi.fn();
		vi.stubGlobal( 'fetch', fetch );

		const data = {};
		trigger( 'heartbeat-send', data );
		window.dispatchEvent( new Event( 'pagehide' ) );

		expect( data[ 'presence-ping' ].keepalive ).toBe( false );
		expect( data[ 'presence-editor-ping' ] ).toEqual( {
			post_id: 5,
			keepalive: false,
		} );
		expect( fetch ).not.toHaveBeenCalled();
	} );

	it( 'skips the unload DELETE when the transport drops before pagehide', () => {
		const hooks = loadPing( {
			config: {
				restUrl: 'https://example.test/wp-json/wp/v2/presence',
				entries: [ { room: 'admin/online', client_id: 'user-1' } ],
			},
		} );
		const fetch = vi.fn();
		vi.stubGlobal( 'fetch', fetch );
		hooks.addFilter( 'presence-api.keepalive', 'test', () => false );

		// Chrome closes the connection first, and the transport removes its filter.
		hooks.removeFilter( 'presence-api.keepalive', 'test' );
		window.dispatchEvent( new Event( 'pagehide' ) );

		expect( fetch ).not.toHaveBeenCalled();
	} );

	it( 'closes the admin bar menu once Tab leaves it, so it refreshes again', () => {
		document.body.innerHTML =
			'<ul><li id="wp-admin-bar-presence-online" class="menupop hover"><a class="ab-item" href="#">2 online</a><div class="ab-sub-wrapper"><div class="ab-item" tabindex="0">Ada</div></div></li></ul><button>Help</button>';
		loadPing();
		const node = document.getElementById( 'wp-admin-bar-presence-online' );

		node.querySelector( 'a' ).focus();
		node.querySelector( 'div.ab-item' ).focus();
		expect( node.classList.contains( 'hover' ) ).toBe( true );

		document.querySelector( 'button' ).focus();
		expect( node.classList.contains( 'hover' ) ).toBe( false );

		trigger( 'heartbeat-tick', {
			'presence-fragments': {
				'admin-bar':
					'<li id="wp-admin-bar-presence-online" class="menupop"><a class="ab-item" href="#">3 online</a></li>',
			},
		} );
		expect( node.textContent ).toBe( '3 online' );
	} );
} );
