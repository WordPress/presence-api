/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import '../../assets/js/tab-coordinator';
import { onHeartbeatTick } from './heartbeat-events';

/**
 * Coalesces presence polling for a given room + fields pair.
 *
 * All subscribers for the same room and `_fields` share one coordinator,
 * and the tab coordinator elects one tab to poll for the rest.
 *
 * Keyed room -> fields -> coordinator, so the two can't collide as a string.
 */
const coordinators = new Map();

/**
 * Subscribes to presence data for a room, sharing the poll with every other
 * subscriber asking for the same room and fields.
 *
 * @param {string}                   room     Presence room id.
 * @param {string}                   fields   `_fields` parameter for the REST request.
 * @param {(result: Object) => void} callback Called with `{ entries, error }` whenever new data arrives.
 * @return {() => void} Unsubscribe function.
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
	const coordinator = {
		subscribers: new Set(),
		lastResult: null,
		started: false,
		fetchInProgress: false,
		abortController: null,
		heartbeatCleanup: null,
		tabCoordinator: null,
	};

	function notify( result ) {
		coordinator.lastResult = result;
		coordinator.subscribers.forEach( ( callback ) => callback( result ) );
	}

	function deliver( result ) {
		notify( result );
		coordinator.tabCoordinator.postMessage( result );
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

	coordinator.start = function () {
		if ( coordinator.started ) {
			return;
		}
		coordinator.started = true;

		coordinator.tabCoordinator = window.wpPresenceCreateTabCoordinator(
			`wp-presence-poll:${ room }:${ fields }`,
			{ onMessage: notify, onLeader: fetchAndBroadcast }
		);

		coordinator.heartbeatCleanup = onHeartbeatTick( () => {
			if ( coordinator.tabCoordinator.isLeader() ) {
				fetchAndBroadcast();
			}
		} );
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

		coordinator.tabCoordinator.destroy();
	};

	return coordinator;
}
