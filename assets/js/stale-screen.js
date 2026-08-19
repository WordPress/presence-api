/**
 * Stale-screen banner client.
 *
 * Pings the server with the current screen key on every Heartbeat tick and
 * renders a non-blocking warning notice when the server reports a revision
 * newer than this page's baseline. The baseline, current screen key, and
 * translated strings are passed in via `window.wpPresenceStaleScreen`,
 * which the enqueue handler emits as a `before` inline script.
 *
 * @package Presence_API
 */

( function ( $ ) {
	'use strict';

	if ( typeof wp === 'undefined' || typeof wp.heartbeat === 'undefined' ) {
		return;
	}

	const config      = window.wpPresenceStaleScreen || {};
	const screenKey   = config.screenKey || '';
	const strings     = config.strings || {};
	let baselineRev   = parseInt( config.baselineRev, 10 ) || 0;
	let bannerShown   = false;

	if ( ! screenKey ) {
		return;
	}

	// Tabs on the same screen send an identical ping — key by screenKey.
	const pingContextKey = 'wp-presence-screen-ping:' + screenKey;

	const hasLocks = typeof navigator !== 'undefined' &&
		navigator.locks &&
		typeof navigator.locks.request === 'function';

	// No Locks API: ping independently, same as before.
	let isPingLeader = ! hasLocks;

	const pingChannel = typeof BroadcastChannel === 'function'
		? new BroadcastChannel( pingContextKey )
		: null;

	if ( pingChannel ) {
		pingChannel.addEventListener( 'message', function ( event ) {
			$( document ).trigger( 'heartbeat-tick', [ event.data ] );
		} );
	}

	if ( hasLocks ) {
		// All tabs queue on this lock; the winner leads until its tab closes.
		navigator.locks
			.request( pingContextKey, function () {
				isPingLeader = true;
				return new Promise( function () {} );
			} )
			.catch( function () {} );
	}

	window.wp = window.wp || {};
	window.wp.presence = window.wp.presence || {};

	/**
	 * Marks a screen as stale, bumping its revision on the server.
	 *
	 * Custom REST or AJAX-driven screens should call this after a successful
	 * save so other users viewing the same screen receive a stale notice.
	 *
	 * @param {string} key     The screen key (e.g. 'options/my-plugin').
	 * @return {Promise} jQuery Promise.
	 */
	window.wp.presence.markScreenStale = function ( key ) {
		if ( ! config.restUrl || ! config.nonce ) {
			return $.Deferred().reject( 'Missing REST configuration.' ).promise();
		}

		return $.ajax( {
			url: config.restUrl,
			method: 'POST',
			beforeSend: function ( xhr ) {
				xhr.setRequestHeader( 'X-WP-Nonce', config.nonce );
			},
			data: {
				screen_key: key,
			},
		} );
	};

	$( document ).on( 'heartbeat-send', function ( event, data ) {
		if ( document.visibilityState === 'hidden' ) {
			return;
		}
		if ( ! isPingLeader ) {
			return;
		}
		data[ 'presence-screen-ping' ] = { key: screenKey };
	} );

	if ( pingChannel ) {
		$( document ).on( 'heartbeat-tick', function ( event, data ) {
			if ( ! isPingLeader || ! data || ! data[ 'presence-screen-rev' ] ) {
				return;
			}
			pingChannel.postMessage( { 'presence-screen-rev': data[ 'presence-screen-rev' ] } );
		} );
	}

	$( document ).on( 'heartbeat-tick', function ( event, data ) {
		const info = data && data[ 'presence-screen-rev' ];
		if ( ! info || info.key !== screenKey ) {
			return;
		}
		const rev = parseInt( info.rev, 10 ) || 0;
		if ( rev <= baselineRev ) {
			return;
		}
		// If the latest save was by the current user, advance the baseline
		// silently so we don't yell at them about their own save. Only the
		// latest bump's actor reaches us, so this can't tell a lone self-save
		// apart from a self-save that landed after someone else's, but the
		// revision itself is a timestamp now rather than a counter, so there
		// is no increment-by-one to check against.
		if ( info.actor_is_me ) {
			baselineRev = rev;
			return;
		}
		if ( bannerShown ) {
			return;
		}
		showBanner( info );
	} );

	function showBanner( info ) {
		const target = document.querySelector( '.wrap' ) || document.getElementById( 'wpbody-content' );
		if ( ! target ) {
			return;
		}
		bannerShown = true;

		// Place the notice after the screen heading, matching where
		// `do_action('admin_notices')` injects on a normal page load.
		const heading = target.querySelector( ':scope > h1' );
		const before  = heading && heading.nextSibling ? heading.nextSibling : target.firstChild;

		const notice = document.createElement( 'div' );
		notice.className = 'notice notice-warning is-dismissible wp-presence-stale-notice';
		// Announce the new banner to assistive tech without interrupting
		// whatever the user is currently doing on the screen.
		notice.setAttribute( 'role', 'status' );
		notice.setAttribute( 'aria-live', 'polite' );

		const p = document.createElement( 'p' );

		if ( info.actor_avatar_url ) {
			const avatar = document.createElement( 'img' );
			avatar.src       = info.actor_avatar_url;
			avatar.width     = 24;
			avatar.height    = 24;
			// Decorative — the actor name is already in the adjacent text.
			avatar.alt       = '';
			avatar.className = 'wp-presence-stale-avatar';
			p.appendChild( avatar );
		}

		const text = document.createElement( 'span' );
		text.className   = 'wp-presence-stale-text';
		text.textContent = formatMessage( info );
		p.appendChild( text );

		const reload = document.createElement( 'button' );
		reload.type        = 'button';
		reload.className   = 'button button-primary';
		reload.textContent = strings.reload || 'Reload';
		reload.addEventListener( 'click', function () {
			window.location.reload();
		} );
		p.appendChild( reload );
		notice.appendChild( p );

		const dismiss = document.createElement( 'button' );
		dismiss.type      = 'button';
		dismiss.className = 'notice-dismiss';
		const sr = document.createElement( 'span' );
		sr.className   = 'screen-reader-text';
		sr.textContent = strings.dismiss || 'Dismiss this notice.';
		dismiss.appendChild( sr );
		dismiss.addEventListener( 'click', function () {
			notice.remove();
		} );
		notice.appendChild( dismiss );

		target.insertBefore( notice, before );
	}

	function formatMessage( info ) {
		const timeAgo = info.time_ago || '';
		if ( info.actor_name ) {
			// `split('%1$s').join(name)` avoids String.replace's $-pattern
			// interpretation so display names with `$&`, `$1`, etc. don't
			// get reinterpreted as backreferences.
			return ( strings.updatedBy || '%1$s updated this screen %2$s.' )
				.split( '%1$s' ).join( info.actor_name )
				.split( '%2$s' ).join( timeAgo );
		}
		return ( strings.updatedAnonymously || 'This screen was updated %s.' )
			.split( '%s' ).join( timeAgo );
	}
} )( jQuery );
