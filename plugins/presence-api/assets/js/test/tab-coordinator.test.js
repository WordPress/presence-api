/**
 * Unit tests for the cross-tab Heartbeat ping coordinator.
 *
 * Each "tab" is a fresh require of tab-coordinator.js against its own fake
 * jQuery document bus, so the two closures see separate `heartbeat-tick`
 * event streams the way two browser tabs would.
 *
 * @package Presence_API
 */

/**
 * Builds a minimal jQuery stand-in exposing `on`/`trigger` on a shared bus.
 *
 * @return {Function} jQuery-like factory.
 */
function createFakeJQuery() {
	const listeners = {};

	const factory = () => ( {
		on( eventName, handler ) {
			listeners[ eventName ] = listeners[ eventName ] || [];
			listeners[ eventName ].push( handler );
		},
		trigger( eventName, args ) {
			( listeners[ eventName ] || [] )
				.slice()
				.forEach( ( handler ) =>
					handler( { type: eventName }, ...( args || [] ) )
				);
		},
	} );

	return factory;
}

const channelsByName = {};
const postedMessages = [];
let pendingDeliveries = [];

class FakeBroadcastChannel {
	constructor( name ) {
		this.name = name;
		this.listeners = [];
		channelsByName[ name ] = channelsByName[ name ] || [];
		channelsByName[ name ].push( this );
	}

	addEventListener( type, handler ) {
		if ( 'message' === type ) {
			this.listeners.push( handler );
		}
	}

	postMessage( data ) {
		postedMessages.push( { name: this.name, data } );

		// Real BroadcastChannel never echoes to the posting context.
		channelsByName[ this.name ]
			.filter( ( channel ) => channel !== this )
			.forEach( ( channel ) =>
				pendingDeliveries.push( () =>
					channel.listeners.forEach( ( handler ) =>
						handler( { data } )
					)
				)
			);
	}
}

/**
 * Drains queued BroadcastChannel deliveries, stopping after `maxRounds` so a
 * rebroadcast loop bounds out instead of hanging the test run.
 *
 * @param {number} maxRounds
 */
function drainDeliveries( maxRounds = 10 ) {
	for (
		let round = 0;
		round < maxRounds && pendingDeliveries.length;
		round++
	) {
		const batch = pendingDeliveries;
		pendingDeliveries = [];
		batch.forEach( ( deliver ) => deliver() );
	}
}

/**
 * Loads a fresh coordinator closure bound to its own jQuery bus.
 *
 * @param {string}   key
 * @param {string[]} relayedKeys
 * @return {{coordinator: object, jQuery: Function, ticks: object[]}} Tab handle.
 */
function openTab( key, relayedKeys ) {
	const fakeJQuery = createFakeJQuery();
	const ticks = [];

	jest.isolateModules( () => {
		global.jQuery = fakeJQuery;
		require( '../tab-coordinator' );
	} );

	fakeJQuery( document ).on( 'heartbeat-tick', ( event, data ) =>
		ticks.push( data )
	);

	return {
		coordinator: window.wpPresenceCreateTabCoordinator( key, relayedKeys ),
		jQuery: fakeJQuery,
		ticks,
	};
}

const flush = () => new Promise( ( resolve ) => setTimeout( resolve, 0 ) );

/**
 * Sets the shared document's visibility; tabs only react once their own bus fires visibilitychange.
 *
 * @param {string} state
 */
function setVisibility( state ) {
	Object.defineProperty( document, 'visibilityState', {
		configurable: true,
		get: () => state,
	} );
}

describe( 'wpPresenceCreateTabCoordinator', () => {
	beforeEach( () => {
		global.BroadcastChannel = FakeBroadcastChannel;
		postedMessages.length = 0;
		pendingDeliveries = [];
		Object.keys( channelsByName ).forEach(
			( name ) => delete channelsByName[ name ]
		);
	} );

	afterEach( () => {
		delete global.navigator.locks;
		delete global.BroadcastChannel;
		delete global.jQuery;
		delete window.wpPresenceCreateTabCoordinator;
	} );

	describe( 'without the Web Locks API', () => {
		it( 'makes every tab ping independently', () => {
			const tabA = openTab( 'presence-key', [ 'presence-online' ] );
			const tabB = openTab( 'presence-key', [ 'presence-online' ] );

			expect( tabA.coordinator.isLeader() ).toBe( true );
			expect( tabB.coordinator.isLeader() ).toBe( true );
		} );

		it( 'does not rebroadcast ticks between tabs', () => {
			const tabA = openTab( 'presence-key', [ 'presence-online' ] );
			const tabB = openTab( 'presence-key', [ 'presence-online' ] );

			tabA.jQuery( document ).trigger( 'heartbeat-tick', [
				{ 'presence-online': [ { user_id: 1 } ] },
			] );
			drainDeliveries();

			expect( postedMessages ).toHaveLength( 0 );
			expect( tabB.ticks ).toHaveLength( 0 );
		} );
	} );

	describe( 'with the Web Locks API', () => {
		beforeEach( () => {
			const queues = {};

			const grantNext = ( name ) => {
				const queue = queues[ name ];
				if ( ! queue.length || queue[ 0 ].granted ) {
					return;
				}
				const next = queue[ 0 ];
				next.granted = true;
				Promise.resolve()
					.then( () => next.callback() )
					.then( () => {
						queue.shift();
						grantNext( name );
					} );
			};

			global.navigator.locks = {
				request: ( name, callback ) =>
					new Promise( () => {
						queues[ name ] = queues[ name ] || [];
						queues[ name ].push( { callback, granted: false } );
						grantNext( name );
					} ),
			};
		} );

		afterEach( () => {
			delete window.ajaxurl;
			delete window.heartbeatSettings;
			delete window.wp;
			setVisibility( 'visible' );
		} );

		it( 'relays the leader tick to followers exactly once', async () => {
			const tabA = openTab( 'presence-key', [ 'presence-online' ] );
			const tabB = openTab( 'presence-key', [ 'presence-online' ] );
			await flush();

			expect( tabA.coordinator.isLeader() ).toBe( true );
			expect( tabB.coordinator.isLeader() ).toBe( false );

			tabA.jQuery( document ).trigger( 'heartbeat-tick', [
				{ 'presence-online': [ { user_id: 1 } ], other: 'dropped' },
			] );
			drainDeliveries();

			expect( postedMessages ).toHaveLength( 1 );
			expect( tabB.ticks ).toEqual( [
				{ 'presence-online': [ { user_id: 1 } ] },
			] );
		} );

		it( 'does not relay ticks carrying none of the relayed keys', async () => {
			const tabA = openTab( 'presence-key', [ 'presence-online' ] );
			openTab( 'presence-key', [ 'presence-online' ] );
			await flush();

			tabA.jQuery( document ).trigger( 'heartbeat-tick', [
				{ 'wp-refresh-post-lock': {} },
			] );
			drainDeliveries();

			expect( postedMessages ).toHaveLength( 0 );
		} );

		it( 'hands leadership to a visible tab when the leader is hidden', async () => {
			const tabA = openTab( 'presence-key', [ 'presence-online' ] );
			const tabB = openTab( 'presence-key', [ 'presence-online' ] );
			await flush();

			setVisibility( 'hidden' );
			tabA.jQuery( document ).trigger( 'visibilitychange' );
			setVisibility( 'visible' );
			await flush();

			expect( tabA.coordinator.isLeader() ).toBe( false );
			expect( tabB.coordinator.isLeader() ).toBe( true );
		} );

		it( 'does not queue a tab that opens hidden until it is shown', async () => {
			setVisibility( 'hidden' );
			const tab = openTab( 'presence-key', [ 'presence-online' ] );
			await flush();

			expect( tab.coordinator.isLeader() ).toBe( false );

			setVisibility( 'visible' );
			tab.jQuery( document ).trigger( 'visibilitychange' );
			await flush();

			expect( tab.coordinator.isLeader() ).toBe( true );
		} );

		it( 'does not lead when hidden before its request is granted', async () => {
			const tab = openTab( 'presence-key', [ 'presence-online' ] );

			setVisibility( 'hidden' );
			tab.jQuery( document ).trigger( 'visibilitychange' );
			await flush();

			expect( tab.coordinator.isLeader() ).toBe( false );

			setVisibility( 'visible' );
			const nextTab = openTab( 'presence-key', [ 'presence-online' ] );
			await flush();

			expect( nextTab.coordinator.isLeader() ).toBe( true );
		} );

		it( 'connects at once when a shown tab takes over', async () => {
			window.wp = { heartbeat: { connectNow: jest.fn() } };
			const tab = openTab( 'presence-key', [ 'presence-online' ] );
			await flush();

			expect( window.wp.heartbeat.connectNow ).not.toHaveBeenCalled();

			setVisibility( 'hidden' );
			tab.jQuery( document ).trigger( 'visibilitychange' );
			setVisibility( 'visible' );
			tab.jQuery( document ).trigger( 'visibilitychange' );
			await flush();

			expect( window.wp.heartbeat.connectNow ).toHaveBeenCalledTimes( 1 );
		} );

		it.each( [
			[ 'admin', ( url ) => ( window.ajaxurl = url ) ],
			[
				'front end',
				( url ) => ( window.heartbeatSettings = { ajaxurl: url } ),
			],
		] )(
			'elects a leader per site on a subdirectory network (%s)',
			async ( screen, setEndpoint ) => {
				setEndpoint( '/site-a/wp-admin/admin-ajax.php' );
				const tabA = openTab( 'presence-key', [ 'presence-online' ] );
				setEndpoint( '/site-b/wp-admin/admin-ajax.php' );
				const tabB = openTab( 'presence-key', [ 'presence-online' ] );
				await flush();

				expect( tabA.coordinator.isLeader() ).toBe( true );
				expect( tabB.coordinator.isLeader() ).toBe( true );
			}
		);
	} );
} );
