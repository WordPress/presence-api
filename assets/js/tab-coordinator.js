/**
 * Cross-tab Heartbeat ping coordinator.
 *
 * Elects one tab per key to actually send Heartbeat's ping payload; other
 * tabs relay the elected tab's response over BroadcastChannel instead of
 * pinging independently. Falls back to independent pinging when Web Locks
 * or BroadcastChannel aren't available.
 *
 * @package Presence_API
 */
( function ( $ ) {
	'use strict';

	/**
	 * @param {string}   key         Unique lock/channel name for this ping type.
	 * @param {string[]} relayedKeys heartbeat-tick response keys to relay from the leader to followers.
	 * @return {{isLeader: function(): boolean}} Coordinator handle.
	 */
	window.wpPresenceCreateTabCoordinator = function ( key, relayedKeys ) {
		var hasLocks = typeof navigator !== 'undefined' &&
			navigator.locks &&
			typeof navigator.locks.request === 'function';

		// No Locks API: ping independently, same as before.
		var isPingLeader = ! hasLocks;

		var channel = typeof BroadcastChannel === 'function'
			? new BroadcastChannel( key )
			: null;

		if ( channel ) {
			channel.addEventListener( 'message', function ( event ) {
				$( document ).trigger( 'heartbeat-tick', [ event.data ] );
			} );
		}

		if ( hasLocks ) {
			// All tabs queue on this lock; the winner leads until its tab
			// closes. Closing or crashing the leader's tab releases the
			// lock automatically, promoting the next queued tab.
			navigator.locks
				.request( key, function () {
					isPingLeader = true;
					return new Promise( function () {} );
				} )
				.catch( function () {} );
		}

		if ( channel ) {
			$( document ).on( 'heartbeat-tick', function ( event, data ) {
				if ( ! isPingLeader || ! data ) {
					return;
				}

				var relayed = {};
				var hasRelayedData = false;

				relayedKeys.forEach( function ( relayedKey ) {
					if ( Object.prototype.hasOwnProperty.call( data, relayedKey ) ) {
						relayed[ relayedKey ] = data[ relayedKey ];
						hasRelayedData = true;
					}
				} );

				if ( hasRelayedData ) {
					channel.postMessage( relayed );
				}
			} );
		}

		return {
			isLeader: function () {
				return isPingLeader;
			},
		};
	};
} )( jQuery );
