/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import { subscribeToPresencePolling } from '../presence-poll-coordinator';

jest.mock( '@wordpress/api-fetch' );

let mockHeartbeatTickCallbacks = [];

jest.mock( '../heartbeat-events', () => ( {
	onHeartbeatTick: jest.fn( ( callback ) => {
		mockHeartbeatTickCallbacks.push( callback );
		return () => {
			mockHeartbeatTickCallbacks = mockHeartbeatTickCallbacks.filter(
				( cb ) => cb !== callback
			);
		};
	} ),
} ) );

const flush = () => new Promise( ( resolve ) => setTimeout( resolve, 0 ) );

describe( 'subscribeToPresencePolling', () => {
	beforeEach( () => {
		apiFetch.mockClear();
		mockHeartbeatTickCallbacks = [];
	} );

	afterEach( () => {
		delete global.navigator.locks;
		delete global.BroadcastChannel;
		jest.clearAllMocks();
	} );

	it( 'shares a single request across multiple subscribers for the same room and fields', async () => {
		apiFetch.mockResolvedValue( [ { user_id: 2 } ] );

		const callbackA = jest.fn();
		const callbackB = jest.fn();

		const unsubscribeA = subscribeToPresencePolling(
			'room-shared',
			'user_id',
			callbackA
		);
		const unsubscribeB = subscribeToPresencePolling(
			'room-shared',
			'user_id',
			callbackB
		);

		await flush();

		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
		expect( callbackA ).toHaveBeenCalledWith( {
			entries: [ { user_id: 2 } ],
			error: null,
		} );
		expect( callbackB ).toHaveBeenCalledWith( {
			entries: [ { user_id: 2 } ],
			error: null,
		} );

		unsubscribeA();
		unsubscribeB();
	} );

	it( 'polls independently for different fields on the same room', async () => {
		apiFetch.mockResolvedValue( [] );

		const unsubscribeA = subscribeToPresencePolling(
			'room-fields',
			'user_id',
			jest.fn()
		);
		const unsubscribeB = subscribeToPresencePolling(
			'room-fields',
			'user_id,display_name',
			jest.fn()
		);

		await flush();

		expect( apiFetch ).toHaveBeenCalledTimes( 2 );

		unsubscribeA();
		unsubscribeB();
	} );

	it( 'stops polling on heartbeat tick once every subscriber has left', async () => {
		apiFetch.mockResolvedValue( [] );

		const unsubscribe = subscribeToPresencePolling(
			'room-teardown',
			'user_id',
			jest.fn()
		);
		await flush();
		expect( mockHeartbeatTickCallbacks ).toHaveLength( 1 );

		unsubscribe();
		expect( mockHeartbeatTickCallbacks ).toHaveLength( 0 );

		mockHeartbeatTickCallbacks.forEach( ( cb ) => cb() );
		await flush();
		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'does not call apiFetch until this tab wins the Web Lock', async () => {
		let grantLock;
		global.navigator.locks = {
			request: jest.fn( ( name, options, callback ) => {
				return new Promise( ( resolve ) => {
					grantLock = () => callback().then( resolve );
				} );
			} ),
		};
		apiFetch.mockResolvedValue( [] );

		const unsubscribe = subscribeToPresencePolling(
			'room-lock',
			'user_id',
			jest.fn()
		);

		await flush();
		expect( apiFetch ).not.toHaveBeenCalled();
		expect( global.navigator.locks.request ).toHaveBeenCalledWith(
			'wp-presence-poll:room-lock:user_id',
			expect.any( Object ),
			expect.any( Function )
		);

		grantLock();
		await flush();
		expect( apiFetch ).toHaveBeenCalledTimes( 1 );

		unsubscribe();
	} );

	it( 'delivers results received over BroadcastChannel without fetching itself', () => {
		let messageHandler;
		global.BroadcastChannel = function () {
			return {
				addEventListener: ( type, handler ) => {
					messageHandler = handler;
				},
				postMessage: jest.fn(),
				close: jest.fn(),
			};
		};
		// Another tab holds the lock, so this request never resolves.
		global.navigator.locks = {
			request: jest.fn( () => new Promise( () => {} ) ),
		};

		const callback = jest.fn();
		const unsubscribe = subscribeToPresencePolling(
			'room-follower',
			'user_id',
			callback
		);

		messageHandler( {
			data: { entries: [ { user_id: 9 } ], error: null },
		} );

		expect( callback ).toHaveBeenCalledWith( {
			entries: [ { user_id: 9 } ],
			error: null,
		} );
		expect( apiFetch ).not.toHaveBeenCalled();

		unsubscribe();
	} );

	it( 'delivers a fetch error to subscribers', async () => {
		const mockError = new Error( 'Network error' );
		apiFetch.mockRejectedValue( mockError );

		const callback = jest.fn();
		const unsubscribe = subscribeToPresencePolling(
			'room-error',
			'user_id',
			callback
		);

		await flush();

		expect( callback ).toHaveBeenCalledWith( {
			entries: null,
			error: mockError,
		} );

		unsubscribe();
	} );
} );
