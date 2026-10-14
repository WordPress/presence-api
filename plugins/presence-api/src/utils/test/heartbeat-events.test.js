/**
 * External dependencies
 */
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

/**
 * Internal dependencies
 */
import { isHeartbeatAvailable, onHeartbeatTick } from '../heartbeat-events';

describe( 'heartbeat-events', () => {
	const originalJQuery = window.jQuery;
	const originalWp = window.wp;

	afterEach( () => {
		if ( typeof originalJQuery === 'undefined' ) {
			delete window.jQuery;
		} else {
			window.jQuery = originalJQuery;
		}

		if ( typeof originalWp === 'undefined' ) {
			delete window.wp;
		} else {
			window.wp = originalWp;
		}

		vi.restoreAllMocks();
	} );

	describe( 'isHeartbeatAvailable', () => {
		it( 'returns false when window.wp is undefined', () => {
			delete window.wp;
			expect( isHeartbeatAvailable() ).toBe( false );
		} );

		it( 'returns false when window.wp.heartbeat is undefined', () => {
			window.wp = {};
			expect( isHeartbeatAvailable() ).toBe( false );
		} );

		it( 'returns true when window.wp.heartbeat is defined', () => {
			window.wp = { heartbeat: {} };
			expect( isHeartbeatAvailable() ).toBe( true );
		} );
	} );

	describe( 'onHeartbeatTick', () => {
		describe( 'with jQuery present', () => {
			let handlers;
			let mockJQuery;

			beforeEach( () => {
				handlers = {};
				mockJQuery = vi.fn( () => ( {
					on: vi.fn( ( eventName, handler ) => {
						handlers[ eventName ] = handler;
					} ),
					off: vi.fn( ( eventName, handler ) => {
						if ( handlers[ eventName ] === handler ) {
							delete handlers[ eventName ];
						}
					} ),
				} ) );
				window.jQuery = mockJQuery;
			} );

			it( 'subscribes via jQuery and receives tick data', () => {
				const callback = vi.fn();
				const tickData = { 'wp-presence': { room: 'demo' } };

				const unsubscribe = onHeartbeatTick( callback );

				expect( mockJQuery ).toHaveBeenCalledWith( document );
				expect( typeof handlers[ 'heartbeat-tick.presenceApi' ] ).toBe(
					'function'
				);

				handlers[ 'heartbeat-tick.presenceApi' ]( {}, tickData );
				expect( callback ).toHaveBeenCalledWith( tickData );

				unsubscribe();
				expect(
					handlers[ 'heartbeat-tick.presenceApi' ]
				).toBeUndefined();
			} );
		} );

		describe( 'without jQuery present', () => {
			beforeEach( () => {
				delete window.jQuery;
			} );

			it( 'subscribes via native CustomEvent and receives detail data', () => {
				const callback = vi.fn();
				const tickData = { 'wp-presence': { room: 'native-demo' } };

				const addSpy = vi.spyOn( document, 'addEventListener' );
				const removeSpy = vi.spyOn( document, 'removeEventListener' );

				const unsubscribe = onHeartbeatTick( callback );

				expect( addSpy ).toHaveBeenCalledWith(
					'heartbeat-tick',
					expect.any( Function )
				);

				const event = new CustomEvent( 'heartbeat-tick', {
					detail: tickData,
				} );
				document.dispatchEvent( event );

				expect( callback ).toHaveBeenCalledWith( tickData );

				unsubscribe();
				expect( removeSpy ).toHaveBeenCalledWith(
					'heartbeat-tick',
					addSpy.mock.calls[ 0 ][ 1 ]
				);

				document.dispatchEvent(
					new CustomEvent( 'heartbeat-tick', { detail: tickData } )
				);
				expect( callback ).toHaveBeenCalledTimes( 1 );
			} );
		} );
	} );
} );
