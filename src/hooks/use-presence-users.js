/**
 * WordPress dependencies
 */
import { useState, useEffect, useCallback } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';

/**
 * Subscribes to WordPress Heartbeat to poll for presence room occupants.
 *
 * When `room` is null or undefined, no requests run and the hook reports
 * empty presence.
 *
 * @param {string|null|undefined} room                        Presence room id (e.g. `postType/chart:123`).
 * @param {Object}                [options]
 * @param {boolean}               [options.includeSelf=false] When false, the current user is
 *                                                            excluded from `users`.
 * @param {string}                [options.fields]            Optional `_fields` parameter to
 *                                                            limit response fields.
 *
 * @return {{
 *   isPresent: boolean,
 *   users: Array<{
 *     userId: number,
 *     displayName: string,
 *     avatarUrl: string
 *   }>
 * }}
 */
export default function usePresenceUsers( room, options = {} ) {
	const { includeSelf = false, fields = 'user_id,display_name,avatar_url' } = options;

	const [ users, setUsers ] = useState( [] );

	const currentUserId = useSelect(
		( select ) => select( 'core' ).getCurrentUser()?.id,
		[]
	);

	const fetchPresence = useCallback( async () => {
		if ( ! room ) {
			setUsers( [] );
			return;
		}

		try {
			const params = new URLSearchParams( {
				room,
				_fields: fields,
			} );

			const entries = await apiFetch( {
				path: `/wp-presence/v1/presence?${ params }`,
			} );

			if ( ! Array.isArray( entries ) ) {
				setUsers( [] );
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
					userId,
					displayName: entry.display_name || `User ${ userId }`,
					avatarUrl: entry.avatar_url || '',
				} );
			}

			setUsers( presentUsers );
		} catch {
			setUsers( [] );
		}
	}, [ room, currentUserId, includeSelf, fields ] );

	useEffect( () => {
		if ( ! room ) {
			setUsers( [] );
			return;
		}

		if ( typeof window.wp?.heartbeat === 'undefined' ) {
			return;
		}

		fetchPresence();

		const handleHeartbeatTick = () => {
			fetchPresence();
		};

		window.jQuery( document ).on( 'heartbeat-tick', handleHeartbeatTick );

		return () => {
			window.jQuery( document ).off( 'heartbeat-tick', handleHeartbeatTick );
		};
	}, [ room, fetchPresence ] );

	return {
		isPresent: users.length > 0,
		users,
	};
}
