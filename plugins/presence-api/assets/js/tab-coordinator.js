/**
 * Elects one visible tab per key and site to do work every tab would otherwise
 * repeat and relays its results to the rest, loaded both as a classic script
 * and as an import from src/, so it has no imports or exports.
 *
 * @package Presence_API
 */
( function () {
	'use strict';

	/**
	 * @param {string}                  key                   Unique lock/channel name.
	 * @param {Object}                  [options]
	 * @param {string[]}                [options.relayedKeys] heartbeat-tick response keys to relay from the leader to followers.
	 * @param {function(Object): void}  [options.onMessage]   Receives what the leader posts, replayed as a heartbeat-tick by default.
	 * @param {function(boolean): void} [options.onLeader]    Runs on taking the lead, passed true when that happened on being shown, and connects Heartbeat at once in that case by default.
	 * @return {{isLeader: function(): boolean, postMessage: function(Object): void, destroy: function(): void}} Coordinator handle.
	 */
	window.wpPresenceCreateTabCoordinator = function ( key, options = {} ) {
		const $ = window.jQuery;
		const relayedKeys = options.relayedKeys || [];

		const onMessage =
			options.onMessage ||
			function ( data ) {
				$( document ).trigger( 'heartbeat-tick', [ data ] );
			};

		const onLeader =
			options.onLeader ||
			function ( tookOver ) {
				if (
					tookOver &&
					typeof window.wp?.heartbeat?.connectNow === 'function'
				) {
					window.wp.heartbeat.connectNow();
				}
			};

		const hasLocks =
			typeof navigator !== 'undefined' &&
			navigator.locks &&
			typeof navigator.locks.request === 'function';

		// No Locks API: every tab leads, same as before.
		let isLeader = ! hasLocks;

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
				onMessage( event.data );
			} );
		}

		let pending = null;
		let release = null;
		let destroyed = false;
		let stopListening = function () {};

		const resignLeadership = function () {
			pending = null;
			if ( release ) {
				release();
				release = null;
			}
			isLeader = false;
		};

		if ( hasLocks ) {
			// Only visible tabs queue.
			const requestLeadership = function ( tookOver ) {
				const request = {};
				pending = request;
				navigator.locks
					.request( scopedKey, function () {
						// Resigned or requested again since queuing; returning releases the lock.
						if ( pending !== request ) {
							return;
						}
						pending = null;
						isLeader = true;
						onLeader( tookOver );
						return new Promise( function ( resolve ) {
							release = resolve;
						} );
					} )
					.catch( function () {} );
			};

			const onVisibilityChange = function () {
				if ( document.visibilityState === 'hidden' ) {
					resignLeadership();
				} else {
					requestLeadership( true );
				}
			};

			if ( $ ) {
				$( document ).on( 'visibilitychange', onVisibilityChange );
				stopListening = function () {
					$( document ).off( 'visibilitychange', onVisibilityChange );
				};
			} else {
				document.addEventListener(
					'visibilitychange',
					onVisibilityChange
				);
				stopListening = function () {
					document.removeEventListener(
						'visibilitychange',
						onVisibilityChange
					);
				};
			}

			if ( document.visibilityState !== 'hidden' ) {
				requestLeadership( false );
			}
		} else {
			// Deferred so the caller holds the handle before onLeader runs.
			Promise.resolve().then( function () {
				if ( ! destroyed ) {
					onLeader( false );
				}
			} );
		}

		if ( channel && relayedKeys.length ) {
			$( document ).on( 'heartbeat-tick', function ( event, data ) {
				if ( ! isLeader || ! data ) {
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
				return isLeader;
			},
			postMessage( data ) {
				if ( channel ) {
					channel.postMessage( data );
				}
			},
			destroy() {
				destroyed = true;
				stopListening();
				resignLeadership();
				if ( channel ) {
					channel.close();
				}
			},
		};
	};
} )();
