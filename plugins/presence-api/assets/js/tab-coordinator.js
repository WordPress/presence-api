/**
 * Cross-tab Heartbeat ping coordinator.
 *
 * Elects one visible tab per key and site to send Heartbeat's ping payload; other
 * tabs relay the elected tab's response over BroadcastChannel instead of
 * pinging independently. Falls back to independent pinging when Web Locks
 * or BroadcastChannel aren't available.
 *
 * @param {jQuery} $ The jQuery instance.
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
		const hasLocks =
			typeof navigator !== 'undefined' &&
			navigator.locks &&
			typeof navigator.locks.request === 'function';

		// No Locks API: ping independently, same as before.
		let isPingLeader = ! hasLocks;

		// Locks and channels are shared across the origin, but each site on a subdirectory network has its own Heartbeat endpoint.
		const scope =
			typeof window.ajaxurl === 'string'
				? window.ajaxurl
				: window.heartbeatSettings?.ajaxurl || '';
		const scopedKey = scope + '|' + key;

		const channel =
			hasLocks && typeof BroadcastChannel === 'function'
				? new BroadcastChannel( scopedKey )
				: null;

		if ( channel ) {
			channel.addEventListener( 'message', function ( event ) {
				$( document ).trigger( 'heartbeat-tick', [ event.data ] );
			} );
		}

		if ( hasLocks ) {
			// Only visible tabs queue; one that takes over on becoming visible connects at once so followers don't wait an interval.
			let pending = null;
			let release = null;

			const requestLeadership = function ( connectOnGrant ) {
				const request = {};
				pending = request;
				navigator.locks
					.request( scopedKey, function () {
						// Resigned or requested again since queuing; returning releases the lock.
						if ( pending !== request ) {
							return;
						}
						pending = null;
						isPingLeader = true;
						if (
							connectOnGrant &&
							typeof window.wp?.heartbeat?.connectNow ===
								'function'
						) {
							window.wp.heartbeat.connectNow();
						}
						return new Promise( function ( resolve ) {
							release = resolve;
						} );
					} )
					.catch( function () {} );
			};

			const resignLeadership = function () {
				pending = null;
				if ( release ) {
					release();
					release = null;
				}
				isPingLeader = false;
			};

			$( document ).on( 'visibilitychange', function () {
				if ( document.visibilityState === 'hidden' ) {
					resignLeadership();
				} else {
					requestLeadership( true );
				}
			} );

			if ( document.visibilityState !== 'hidden' ) {
				requestLeadership( false );
			}
		}

		if ( channel ) {
			$( document ).on( 'heartbeat-tick', function ( event, data ) {
				if ( ! isPingLeader || ! data ) {
					return;
				}

				const relayed = {};
				let hasRelayedData = false;

				relayedKeys.forEach( function ( relayedKey ) {
					if (
						Object.prototype.hasOwnProperty.call( data, relayedKey )
					) {
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
			isLeader() {
				return isPingLeader;
			},
		};
	};
} )( jQuery );
