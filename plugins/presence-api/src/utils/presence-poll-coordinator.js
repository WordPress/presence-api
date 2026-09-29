/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import { onHeartbeatTick } from './heartbeat-events';

/**
 * Coalesces presence polling for a given room + fields pair.
 *
 * All subscribers for the same room and `_fields` share one coordinator.
 * Web Locks elects one tab as the poller; BroadcastChannel relays its
 * results to the rest. Falls back to independent per-tab polling if either
 * API is unavailable.
 *
 * Keyed room -> fields -> coordinator, so the two can't collide as a string.
 */
const coordinators = new Map();

/**
 * Subscribes to presence data for a room, sharing the poll with every other
 * subscriber asking for the same room and fields.
 *
 * @param {string}   room     Presence room id.
 * @param {string}   fields   `_fields` parameter for the REST request.
 * @param {Function} callback Called with `{ entries, error }` whenever new data arrives.
 * @return {Function} Unsubscribe function.
 */
export function subscribeToPresencePolling( room, fields, callback ) {
	let byFields = coordinators.get( room );
	if ( ! byFields ) {
		byFields = new Map();
		coordinators.set( room, byFields );
	}

	let coordinator = byFields.get( fields );
	if ( ! coordinator ) {
		coordinator = createCoordinator( room, fields );
		byFields.set( fields, coordinator );
	}

	coordinator.subscribers.add( callback );

	if ( coordinator.lastResult ) {
		callback( coordinator.lastResult );
	}

	coordinator.start();

	return () => {
		coordinator.subscribers.delete( callback );

		if ( coordinator.subscribers.size === 0 ) {
			coordinator.teardown();
			byFields.delete( fields );
			if ( byFields.size === 0 ) {
				coordinators.delete( room );
			}
		}
	};
}

function createCoordinator( room, fields ) {
	const lockName = `wp-presence-poll:${ room }:${ fields }`;

	const coordinator = {
		subscribers: new Set(),
		lastResult: null,
		started: false,
		fetchInProgress: false,
		abortController: null,
		heartbeatCleanup: null,
		releaseLock: null,
		lockAbortController:
			typeof AbortController === 'function'
				? new AbortController()
				: null,
		channel:
			typeof BroadcastChannel === 'function'
				? new BroadcastChannel( lockName )
				: null,
	};

	function notify( result ) {
		coordinator.lastResult = result;
		coordinator.subscribers.forEach( ( callback ) => callback( result ) );
	}

	if ( coordinator.channel ) {
		coordinator.channel.addEventListener( 'message', ( event ) => {
			notify( event.data );
		} );
	}

	function deliver( result ) {
		notify( result );
		if ( coordinator.channel ) {
			coordinator.channel.postMessage( result );
		}
	}

	async function fetchAndBroadcast() {
		if ( coordinator.fetchInProgress ) {
			return;
		}
		coordinator.fetchInProgress = true;

		if ( coordinator.abortController ) {
			coordinator.abortController.abort();
		}
		coordinator.abortController = new AbortController();
		const { signal } = coordinator.abortController;

		try {
			const params = new URLSearchParams( { room, _fields: fields } );
			const entries = await apiFetch( {
				path: `/wp-presence/v1/presence?${ params }`,
				signal,
			} );

			if ( signal.aborted ) {
				return;
			}

			deliver( { entries, error: null } );
		} catch ( err ) {
			if ( signal.aborted || err.name === 'AbortError' ) {
				return;
			}

			deliver( { entries: null, error: err } );
		} finally {
			coordinator.fetchInProgress = false;
		}
	}

	function becomeLeader() {
		fetchAndBroadcast();
		coordinator.heartbeatCleanup = onHeartbeatTick( () =>
			fetchAndBroadcast()
		);
	}

	coordinator.start = function () {
		if ( coordinator.started ) {
			return;
		}
		coordinator.started = true;

		const hasLocks =
			typeof navigator !== 'undefined' &&
			navigator.locks &&
			typeof navigator.locks.request === 'function';

		if ( ! hasLocks ) {
			becomeLeader();
			return;
		}

		const options = coordinator.lockAbortController
			? { signal: coordinator.lockAbortController.signal }
			: {};

		// All tabs queue on this lock; whichever gets it becomes leader.
		// The rest wait and listen on BroadcastChannel instead. Closing or
		// crashing the leader's tab releases the lock automatically.
		navigator.locks
			.request(
				lockName,
				options,
				() =>
					new Promise( ( resolve ) => {
						coordinator.releaseLock = resolve;
						becomeLeader();
					} )
			)
			.catch( () => {} );
	};

	coordinator.teardown = function () {
		coordinator.started = false;

		if ( coordinator.heartbeatCleanup ) {
			coordinator.heartbeatCleanup();
			coordinator.heartbeatCleanup = null;
		}

		if ( coordinator.abortController ) {
			coordinator.abortController.abort();
		}

		if ( coordinator.lockAbortController ) {
			coordinator.lockAbortController.abort();
		}

		if ( coordinator.releaseLock ) {
			coordinator.releaseLock();
			coordinator.releaseLock = null;
		}

		if ( coordinator.channel ) {
			coordinator.channel.close();
		}
	};

	return coordinator;
}
