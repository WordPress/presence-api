/**
 * Network Who's Online dashboard widget client.
 *
 * @param {jQuery} $ The jQuery instance.
 * @package Presence_API
 */

( function ( $ ) {
	if ( typeof wp === 'undefined' || typeof wp.heartbeat === 'undefined' ) {
		return;
	}

	const config = window.wpPresenceNetworkWhosOnline || {};
	const i18n = config.i18n || {};
	const viewAllUrl = config.viewAllUrl || '';
	const avatarMax = config.avatarMax;
	let lastHash = '';
	let lastSignature = '';

	function esc( str ) {
		const el = document.createElement( 'span' );
		el.textContent = str;
		return el.innerHTML;
	}

	// The swap below replaces every node, so a keyboard user standing on a site
	// link lands on the body unless the spot is recorded and handed back.
	function captureFocus( container ) {
		const active = document.activeElement;
		if ( ! active || ! $.contains( container[ 0 ], active ) ) {
			return null;
		}
		const item = $( active ).closest( '[data-blog-id]' );
		if ( item.length ) {
			return { type: 'site', id: item.data( 'blog-id' ) };
		}
		if ( $( active ).hasClass( 'presence-more-link' ) ) {
			return { type: 'more' };
		}
		return { type: 'none' };
	}

	function restoreFocus( container, info ) {
		if ( ! info ) {
			return;
		}
		let target = null;
		if ( info.type === 'site' ) {
			target = container
				.find( '[data-blog-id="' + info.id + '"] a' )
				.first();
		} else if ( info.type === 'more' ) {
			target = container.find( '.presence-more-link' );
		}
		if ( target && target.length ) {
			target.trigger( 'focus' );
		} else {
			container.trigger( 'focus' );
		}
	}

	// Already cut to the sites and avatars this widget shows, so nothing is
	// sliced here; overflow is a count the server sends, not what is left over.
	function buildListHtml( sites, overflow, aggregating ) {
		if ( ! aggregating ) {
			return '<p>' + esc( i18n.notAggregated ) + '</p>';
		}

		if ( ! sites.length ) {
			return '<p>' + esc( i18n.noUsersOnline ) + '</p>';
		}

		let html =
			'<ul class="presence-user-list" aria-label="' +
			esc( i18n.sitesOnline ) +
			'">';
		sites.forEach( function ( site ) {
			html +=
				'<li class="presence-site-item" data-blog-id="' +
				parseInt( site.blog_id, 10 ) +
				'">' +
				window.wpPresenceBuildAvatarStack( site.users, avatarMax );
			html +=
				'<span class="presence-site-info"><a href="' +
				esc( site.url ) +
				'">' +
				esc( site.domain + site.path ) +
				'</a></span>';
			html +=
				'<span class="presence-site-count">' +
				site.user_count +
				'</span></li>';
		} );
		html += '</ul>';

		if ( overflow > 0 ) {
			// Both forms come from the server because _n() cannot be called from
			// here; picking on the count is as close as this gets to its rules.
			const moreLabel = (
				overflow === 1 ? i18n.moreSite : i18n.moreSites
			).replace( '%d', overflow );
			html +=
				'<a href="' +
				esc( viewAllUrl ) +
				'" class="presence-more-link">' +
				esc( moreLabel ) +
				'</a>';
		}

		return html;
	}

	$( document ).on( 'heartbeat-send', function ( event, data ) {
		data[ 'presence-network-widget-ping' ] = true;
		if ( lastHash ) {
			data[ 'presence-network-widget-hash' ] = lastHash;
		}
	} );

	$( document ).on( 'heartbeat-tick', function ( event, data ) {
		if ( data[ 'presence-network-widget-unchanged' ] ) {
			return;
		}

		if ( ! data[ 'presence-network-widget' ] ) {
			return;
		}

		lastHash = data[ 'presence-network-widget-hash' ] || '';

		const container = $( '#presence-network-widget-list' );
		if ( ! container.length ) {
			return;
		}

		const sites = data[ 'presence-network-widget' ];
		const overflow = data[ 'presence-network-widget-overflow' ] || 0;
		const aggregating =
			data[ 'presence-network-widget-aggregating' ] !== false;

		// Signature over what gets drawn, matching the server-side hash: a
		// rename or a new avatar has to repaint, and the order is already the
		// order it renders in.
		const sig = JSON.stringify( [ sites, overflow, aggregating ] );

		if ( sig !== lastSignature ) {
			const focusInfo = captureFocus( container );
			container.html( buildListHtml( sites, overflow, aggregating ) );
			restoreFocus( container, focusInfo );
			lastSignature = sig;
		}
	} );
} )( jQuery );
