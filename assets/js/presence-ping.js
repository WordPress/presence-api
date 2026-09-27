( function ( $ ) {
	if (
		typeof wp === 'undefined' ||
		typeof wp.heartbeat === 'undefined' ||
		typeof wp.hooks === 'undefined'
	) {
		return;
	}

	const config = window.wpPresenceConfig || {};
	const entries = Array.isArray( config.entries ) ? config.entries : [];
	const pageContext = config.pageContext || null;
	const editorPostId = parseInt( config.editorPostId, 10 ) || 0;
	const editorRoom = config.editorRoom || '';
	const initialCollaboratorCount =
		parseInt( config.initialCollaboratorCount, 10 ) || 0;
	const restUrl = config.restUrl || '';
	const nonce = config.nonce || '';
	const idleTicks = parseInt( config.idleTicks, 10 ) || 0;
	const idleInterval = parseInt( config.idleInterval, 10 ) || 0;
	const ttl = parseInt( config.ttl, 10 ) || 150;
	const ttlMargin = parseInt( config.ttlMargin, 10 ) || 15;
	const backoffEnabled = idleTicks > 0 && idleInterval > 0;
	const usersList =
		[ 'users', 'users-network' ].includes( window.pagenow ) &&
		new URLSearchParams( window.location.search ).get(
			'presence_status'
		) === 'online'
			? window.location.search
			: '';

	// Fired synchronously, ahead of Heartbeat's first tick, so a listener can
	// tell "presence-api isn't here" apart from "here, no tick yet."
	if ( editorRoom ) {
		wp.hooks.doAction( 'presence-api.watchingRoom', editorRoom );
	}

	// Guards against duplicate leave() invocations.
	let hasLeft = false;

	let unchangedTicks = 0;
	let lastOnlineHash = '';
	let normalInterval = null;
	// Seeded from the room's actual state at page load, so a reload or late
	// join into an already 2+ room doesn't re-fire an edge the PHP side
	// already crossed.
	let hasCollaborators = initialCollaboratorCount > 1;

	// Reads the interval lazily (not at page load) so it reflects whatever
	// another script, e.g. post.js's lock-refresh interval, already set.
	function widenInterval() {
		if ( normalInterval !== null ) {
			return;
		}
		const current = wp.heartbeat.interval();
		// Stay under the TTL, or an idle-but-open tab would drop out of its own room between ticks.
		const target = Math.min( idleInterval, ttl - ttlMargin );
		if ( target <= current ) {
			return;
		}
		normalInterval = current;
		wp.heartbeat.interval( target );
	}

	function resetBackoff() {
		unchangedTicks = 0;
		if ( normalInterval !== null ) {
			wp.heartbeat.interval( normalInterval );
			normalInterval = null;
		}
	}

	// Tabs on the same screen and post send an identical ping. Key by both
	// so a different post or screen never gets coalesced with this one.
	const pingContextKey =
		'wp-presence-ping:' +
		JSON.stringify( {
			restUrl,
			screen: window.pagenow || 'front',
			editorPostId,
			pageTitle: ( pageContext && pageContext.title ) || '',
			pagePostId: ( pageContext && pageContext.post_id ) || 0,
			usersList,
		} );

	// Response keys other presence-api features read off heartbeat-tick.
	// Followers relay these from the leader instead of going stale. This
	// includes the backoff's own hash/unchanged fields, so followers count
	// idle ticks and widen their own interval in step with the leader.
	const RELAYED_TICK_KEYS = [
		'presence-online-hash',
		'presence-online-unchanged',
		'presence-heartbeat-users',
		'presence-heartbeat-entries',
		'presence-heartbeat-query-ms',
		'presence-heartbeat-ttl',
		'presence-heartbeat-room-list',
		'presence-heartbeat-collaborators',
		'presence-admin-bar',
		'presence-users-list',
	];

	const tabCoordinator = window.wpPresenceCreateTabCoordinator(
		pingContextKey,
		RELAYED_TICK_KEYS
	);

	// Defer registration to document ready to ensure it runs after WP Core's post.js handler.
	$( function () {
		$( document ).on( 'heartbeat-send', function ( event, data ) {
			// Skip while the document is hidden (background tab, minimized
			// window, app switched away) so the existing entries expire via
			// the default TTL. One early-return suppresses both presence-ping
			// and presence-editor-ping, since the consolidated handler emits
			// both.
			if ( document.visibilityState === 'hidden' ) {
				delete data[ 'wp-refresh-post-lock' ];
				return;
			}

			hasLeft = false;

			if ( ! tabCoordinator.isLeader() ) {
				return;
			}

			const ping = {
				screen: window.pagenow || 'front',
				token: config.screenToken || '',
			};
			if ( pageContext ) {
				if ( pageContext.title ) {
					ping.title = pageContext.title;
				}
				if ( pageContext.post_id ) {
					ping.post_id = pageContext.post_id;
				}
			}
			data[ 'presence-ping' ] = ping;

			if ( document.getElementById( 'wp-admin-bar-presence-online' ) ) {
				data[ 'presence-admin-bar' ] = 1;
			}

			if ( usersList ) {
				data[ 'presence-users-list' ] = usersList;
			}

			if ( editorPostId ) {
				data[ 'presence-editor-ping' ] = { post_id: editorPostId };
			}
		} );

		const adminBarNode = document.getElementById(
			'wp-admin-bar-presence-online'
		);

		if ( adminBarNode ) {
			let lastAdminBar = '';

			$( document ).on( 'heartbeat-tick', function ( event, data ) {
				const minted = data[ 'presence-screen-token' ];
				if (
					minted &&
					minted.screen === ( window.pagenow || 'front' )
				) {
					config.screenToken = minted.token;
				}
				const html = data[ 'presence-admin-bar' ];
				if ( ! html || html === lastAdminBar ) {
					return;
				}
				// Swapping an open menu would close it; the next tick catches up.
				if (
					adminBarNode.matches( ':hover' ) ||
					adminBarNode.classList.contains( 'hover' ) ||
					adminBarNode.contains( document.activeElement )
				) {
					return;
				}
				const template = document.createElement( 'template' );
				template.innerHTML = html.trim();
				const fresh = template.content.firstElementChild;
				if ( ! fresh ) {
					return;
				}
				// Keep the list item itself, which core's admin-bar.js bound its hover and Enter handlers to.
				adminBarNode.className = fresh.className;
				adminBarNode.innerHTML = fresh.innerHTML;
				lastAdminBar = html;
			} );

			// Core binds Escape to each .ab-item at load, which the swapped rows no longer are.
			adminBarNode.addEventListener( 'keydown', function ( event ) {
				if ( event.key !== 'Escape' ) {
					return;
				}
				adminBarNode.querySelector( '.ab-item' ).focus();
				adminBarNode.classList.remove( 'hover' );
			} );

			// Core toggles aria-expanded on the first link inside, which is a row, not this toggle.
			new window.MutationObserver( function () {
				adminBarNode
					.querySelector( ':scope > .ab-item' )
					.setAttribute(
						'aria-expanded',
						String( adminBarNode.classList.contains( 'hover' ) )
					);
			} ).observe( adminBarNode, { attributeFilter: [ 'class' ] } );
		}

		const usersTable = usersList && document.getElementById( 'the-list' );

		if ( usersTable ) {
			let lastRows = '';

			$( document ).on( 'heartbeat-tick', function ( event, data ) {
				const list = data[ 'presence-users-list' ];
				if ( ! list ) {
					return;
				}
				const count = document.querySelector(
					'.subsubsub .presence_online .count'
				);
				if ( count ) {
					count.textContent = '(' + list.count + ')';
				}
				// Swapping would drop a selection or keyboard focus; the next tick catches up.
				if (
					list.rows === lastRows ||
					usersTable.matches( ':hover' ) ||
					usersTable.contains( document.activeElement ) ||
					usersTable.querySelector( 'input:checked' )
				) {
					return;
				}
				usersTable.innerHTML = list.rows;
				lastRows = list.rows;
			} );
		}

		if ( document.querySelector( '#the-list .column-presence_editors' ) ) {
			const lastCells = {};

			$( document ).on( 'heartbeat-tick', function ( event, data ) {
				const cells = data[ 'presence-editors' ] || {};
				Object.keys( cells ).forEach( function ( id ) {
					const cell = document.querySelector(
						'#' + CSS.escape( id ) + ' .column-presence_editors'
					);
					if ( cell && lastCells[ id ] !== cells[ id ] ) {
						cell.innerHTML = cells[ id ];
						lastCells[ id ] = cells[ id ];
					}
				} );
			} );
		}

		if ( editorRoom ) {
			$( document ).on( 'heartbeat-tick', function ( event, data ) {
				if (
					! Object.prototype.hasOwnProperty.call(
						data,
						'presence-heartbeat-collaborators'
					)
				) {
					return;
				}

				const count = data[ 'presence-heartbeat-collaborators' ];
				const nowHasCollaborators = count > 1;

				if ( nowHasCollaborators === hasCollaborators ) {
					return;
				}

				hasCollaborators = nowHasCollaborators;
				wp.hooks.doAction(
					nowHasCollaborators
						? 'presence-api.collaborationStarted'
						: 'presence-api.collaborationEnded',
					editorRoom,
					count
				);
			} );
		}

		if ( backoffEnabled ) {
			// The server answers with a new hash only when the room changed.
			$( document ).on( 'heartbeat-send', function ( event, data ) {
				if ( lastOnlineHash ) {
					data[ 'presence-online-hash' ] = lastOnlineHash;
				}
			} );

			$( document ).on( 'heartbeat-tick', function ( event, data ) {
				if ( data[ 'presence-online-unchanged' ] ) {
					unchangedTicks++;
					if ( unchangedTicks >= idleTicks ) {
						widenInterval();
					}
					return;
				}
				if ( data[ 'presence-online-hash' ] ) {
					lastOnlineHash = data[ 'presence-online-hash' ];
					resetBackoff();
				}
			} );

			document.addEventListener( 'keydown', resetBackoff );
		}
	} );

	function leave() {
		if ( hasLeft || ! restUrl || ! entries.length ) {
			return;
		}
		hasLeft = true;

		// keepalive lets the DELETE outlive the unload; sendBeacon is POST-only.
		if ( typeof window.fetch !== 'function' ) {
			return;
		}

		entries.forEach( function ( entry ) {
			if ( ! entry || ! entry.room || ! entry.client_id ) {
				return;
			}
			const url = new URL( restUrl );
			url.searchParams.set( 'room', entry.room );
			url.searchParams.set( 'client_id', entry.client_id );
			try {
				window.fetch( url, {
					method: 'DELETE',
					credentials: 'same-origin',
					keepalive: true,
					headers: { 'X-WP-Nonce': nonce },
				} );
			} catch {
				// Best-effort: TTL cleanup will catch entries we couldn't remove.
			}
		} );
	}

	// Re-establish presence on every page load so in-admin navigation doesn't
	// leave a gap between the unload DELETE and the heartbeat's first tick.
	function tickNow() {
		if ( typeof wp?.heartbeat?.connectNow === 'function' ) {
			wp.heartbeat.connectNow();
		}
	}
	$( tickNow );
	// bfcache restore: DOMContentLoaded won't fire.
	window.addEventListener( 'pageshow', function ( event ) {
		if ( event.persisted ) {
			tickNow();
		}
	} );

	// When the tab becomes visible again, re-establish presence so the user
	// does not sit out the next heartbeat interval.
	document.addEventListener( 'visibilitychange', function () {
		if ( document.visibilityState === 'visible' ) {
			resetBackoff();
			tickNow();
		}
	} );

	window.addEventListener( 'pagehide', function () {
		leave();
	} );
} )( jQuery );
