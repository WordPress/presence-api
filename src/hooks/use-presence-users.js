/**
 * WordPress dependencies
 */
import { useState, useEffect } from '@wordpress/element';
import { useSelect } from '@wordpress/data';

/**
 * Internal dependencies
 */
import { isHeartbeatAvailable } from '../utils/heartbeat-events';
import { subscribeToPresencePolling } from '../utils/presence-poll-coordinator';

/**
 * Subscribes to WordPress Heartbeat to poll for presence room occupants.
 *
 * When `room` is null or undefined, no requests run and the hook reports
 * empty presence.
 *
 * @param {string|null|undefined} room                        Presence room id (e.g. `postType/post:123`).
 * @param {Object}                [options]
 * @param {boolean}               [options.includeSelf=false] When false, the current user is
 *                                                            excluded from `users`.
 * @param {string}                [options.fields]            Optional `_fields` parameter to
 *                                                            limit response fields.
 *
 * @return {{
 *   isPresent: boolean,
 *   isLoading: boolean,
 *   users: Array<{
 *     id: number,
 *     displayName: string,
 *     avatarUrl: string
 *   }>,
 *   error: Error|null
 * }}
 */
export default function usePresenceUsers( room, options = {} ) {
	const { includeSelf = false, fields = 'user_id,display_name,avatar_url' } = options;

	const [ users, setUsers ] = useState( [] );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ error, setError ] = useState( null );

	const currentUserId = useSelect(
		( select ) => select( 'core' ).getCurrentUser()?.id,
		[]
	);

	useEffect( () => {
		if ( ! room ) {
			setUsers( [] );
			setIsLoading( false );
			setError( null );
			return;
		}

		if ( ! isHeartbeatAvailable() ) {
			setIsLoading( false );
			return;
		}

		let isInitialFetch = true;

		const unsubscribe = subscribeToPresencePolling(
			room,
			fields,
			( { entries, error: fetchError } ) => {
				if ( fetchError ) {
					setUsers( [] );
					setError( fetchError );
					if ( isInitialFetch ) {
						setIsLoading( false );
						isInitialFetch = false;
					}
					return;
				}

				if ( ! Array.isArray( entries ) ) {
					setUsers( [] );
					setError( null );
					if ( isInitialFetch ) {
						setIsLoading( false );
						isInitialFetch = false;
					}
					return;
				}

				const presentUsers = [];
				const seen = new Set();

				for ( const entry of entries ) {
					const userId = Number( entry.user_id );

					if (
						userId <= 0 ||
						seen.has( userId ) ||
						( ! includeSelf && userId === currentUserId )
					) {
						continue;
					}

					seen.add( userId );
					presentUsers.push( {
						id: userId,
						displayName: entry.display_name || `User ${ userId }`,
						avatarUrl: entry.avatar_url || '',
					} );
				}

				setUsers( presentUsers );
				setError( null );
				if ( isInitialFetch ) {
					setIsLoading( false );
					isInitialFetch = false;
				}
			}
		);

		return unsubscribe;
	}, [ room, currentUserId, includeSelf, fields ] );

	return {
		isPresent: users.length > 0,
		isLoading,
		users,
		error,
	};
}
