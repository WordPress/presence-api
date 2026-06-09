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

	$( document ).on( 'heartbeat-send', function ( event, data ) {
		if ( document.visibilityState === 'hidden' ) {
			return;
		}
		data[ 'presence-screen-ping' ] = { key: screenKey };
	} );

	$( document ).on( 'heartbeat-tick', function ( event, data ) {
		const info = data && data[ 'presence-screen-rev' ];
		if ( ! info || info.key !== screenKey ) {
			return;
		}
		const rev = parseInt( info.rev, 10 ) || 0;
		if ( rev <= baselineRev ) {
			return;
		}
		// If the latest save was by the current user AND the revision jumped
		// by exactly one, advance the baseline silently so we don't yell at
		// them about their own save. A jump of more than one means someone
		// else saved in between and only the latest actor reaches us — fall
		// through to render the notice in that case.
		if ( info.actor_is_me && rev === baselineRev + 1 ) {
			baselineRev = rev;
			return;
		}
		if ( bannerShown ) {
			return;
		}
		showBanner( info );
	} );

	function showBanner( info ) {
		bannerShown = true;
		const target = document.querySelector( '.wrap' ) || document.getElementById( 'wpbody-content' );
		if ( ! target ) {
			return;
		}

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
