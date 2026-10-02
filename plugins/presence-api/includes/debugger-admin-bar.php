<?php
/**
 * Admin bar debugger: the next Heartbeat, and every client in the rooms the current user is in.
 *
 * @package Presence_API
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds the debugger node to the admin bar.
 *
 * @since 0.12.0
 *
 * @param WP_Admin_Bar $wp_admin_bar The admin bar instance.
 */
function wp_presence_debugger_admin_bar_node( $wp_admin_bar ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$indicators = '';
	/**
	 * Filters the icons beside the debugger's countdown, which refresh with its menu.
	 *
	 * @since 0.12.0
	 *
	 * @param array[] $indicators {
	 *     Indicators to show, none by default.
	 *
	 *     @type string $icon  Dashicons class, such as `dashicons-controls-play`.
	 *     @type string $label What the icon means, for its tooltip and screen readers.
	 * }
	 */
	foreach ( (array) apply_filters( 'wp_presence_debugger_indicators', array() ) as $indicator ) {
		$indicators .= '<span class="presence-debug-indicator dashicons ' . esc_attr( $indicator['icon'] ) . '" title="' . esc_attr( $indicator['label'] ) . '"><span class="screen-reader-text">' . esc_html( $indicator['label'] ) . '</span></span>';
	}

	$wp_admin_bar->add_node(
		array(
			'parent' => 'top-secondary',
			'id'     => 'presence-debug',
			'title'  => '<span class="ab-icon" aria-hidden="true"></span>'
				. '<span class="presence-debug-countdown" aria-hidden="true"></span>'
				. '<span class="screen-reader-text">' . esc_html__( 'Presence API Debugger', 'presence-api' ) . '</span>'
				. '<span class="presence-debug-indicators">' . $indicators . '</span>',
			'meta'   => array(
				'class'    => 'menupop',
				'tabindex' => 0,
			),
		)
	);

	$rows = array(
		'interval' => __( 'Interval', 'presence-api' ),
		'ttl'      => __( 'TTL', 'presence-api' ),
	);
	foreach ( $rows as $key => $label ) {
		$wp_admin_bar->add_node(
			array(
				'parent' => 'presence-debug',
				'id'     => 'presence-debug-' . $key,
				'title'  => '<span>' . esc_html( $label ) . '</span><span class="presence-debug-value" data-presence-debug="' . esc_attr( $key ) . '"' . ( 'ttl' === $key ? ' data-presence-debug-seconds="' . esc_attr( wp_presence_get_timeout() ) . '"' : '' ) . '></span>',
				'meta'   => array( 'class' => 'presence-debug-row' ),
			)
		);
	}

	/**
	 * Fires after the debugger's Interval and TTL rows, on page load and on every Heartbeat refresh.
	 *
	 * @since 0.12.0
	 *
	 * @param WP_Admin_Bar $wp_admin_bar The admin bar, holding the `presence-debug` node to add rows under.
	 */
	do_action( 'wp_presence_debugger_menu', $wp_admin_bar );

	global $wpdb;

	$rooms = array();
	if ( wp_presence_has_table() ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rooms = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT room FROM {$wpdb->presence} WHERE user_id = %d AND expires_gmt > %s ORDER BY room",
				get_current_user_id(),
				gmdate( 'Y-m-d H:i:s' )
			)
		);
	}

	// The catch-all admin room goes last, so the room for this screen leads.
	usort(
		$rooms,
		function ( $a, $b ) {
			return ( wp_presence_admin_room() === $a ) - ( wp_presence_admin_room() === $b );
		}
	);

	if ( $rooms ) {
		$wp_admin_bar->add_group(
			array(
				'parent' => 'presence-debug',
				'id'     => 'presence-debug-rooms',
			)
		);
	}

	foreach ( $rooms as $i => $room ) {
		$group = 'presence-debug-room-' . $i;

		// Reserved rows included, since the plugin's own bookkeeping is part of what is being debugged.
		$rows = wp_presence_room_rows( $room );

		// You first, then by name, so rows keep their place between ticks.
		$me = get_current_user_id();
		usort(
			$rows,
			function ( $a, $b ) use ( $me ) {
				if ( ( $me === (int) $a->user_id ) !== ( $me === (int) $b->user_id ) ) {
					return $me === (int) $a->user_id ? -1 : 1;
				}
				$user_a = get_userdata( (int) $a->user_id );
				$user_b = get_userdata( (int) $b->user_id );
				$order  = strcasecmp( $user_a ? $user_a->display_name : '', $user_b ? $user_b->display_name : '' );
				return $order ? $order : strcmp( $a->client_id, $b->client_id );
			}
		);

		$wp_admin_bar->add_node(
			array(
				'parent' => 'presence-debug-rooms',
				'id'     => $group . '-name',
				'title'  => '<code>' . esc_html( $room ) . '</code><span class="presence-debug-value">' . esc_html( number_format_i18n( count( $rows ) ) ) . '</span>',
				'meta'   => array(
					'class' => 'presence-debug-room',
					'html'  => file_exists( __DIR__ . '/db-viewer.php' ) ? '<a class="presence-debug-table" role="menuitem" href="' . esc_url( wp_nonce_url( add_query_arg( 'room', rawurlencode( $room ), home_url( '/?presence-db=1' ) ), 'wp_presence_db_viewer' ) ) . '" target="_blank" title="' . esc_attr__( 'View in the presence table', 'presence-api' ) . '"><span class="screen-reader-text">' . esc_html__( 'View in the presence table', 'presence-api' ) . ' ' . esc_html__( '(opens in a new window)', 'presence-api' ) . '</span></a>' : '',
				),
			)
		);

		// Colors only mark who shares your post room, as in the block editor.
		$page   = function ( $row ) use ( $room ) {
			if ( ! current_user_can( 'view_presence_location', (int) $row->user_id ) ) {
				return 'user:' . $row->user_id;
			}
			return wp_presence_admin_room() === $room ? ( $row->data['screen'] ?? '' ) . ':' . ( $row->data['post_id'] ?? '' ) . ':' . ( $row->data['object_id'] ?? '' ) : $room;
		};
		$people = array();
		$mine   = null;
		foreach ( $rows as $row ) {
			$people[ $page( $row ) ][ (int) $row->user_id ] = true;
			if ( get_current_user_id() === (int) $row->user_id ) {
				$mine = $page( $row );
			}
		}
		$shared = null !== $mine && count( $people[ $mine ] ) > 1 ? $mine : null;
		$here   = array();
		$rest   = array();
		foreach ( $rows as $row ) {
			if ( $page( $row ) === $mine ) {
				$here[] = $row;
			} else {
				$rest[] = $row;
			}
		}
		// Your page comes first, so the row limit never hides who is with you.
		$rows = array_merge( $here, $rest );
		foreach ( array_slice( $rows, 0, 20 ) as $j => $row ) {
			$user = get_userdata( (int) $row->user_id );
			$age  = max( 0, time() - (int) strtotime( $row->date_gmt . ' UTC' ) );
			$wp_admin_bar->add_node(
				array(
					'parent' => 'presence-debug-rooms',
					'id'     => $group . '-' . $j,
					'title'  => '<span><span class="presence-debug-color"' . ( wp_presence_admin_room() !== $room && $row->user_id && $page( $row ) === $shared ? ' style="background:' . ( get_current_user_id() === (int) $row->user_id ? 'var(--wp-admin-theme-color, #2271b1)' : esc_attr( wp_presence_get_user_color( $row->user_id ) ) ) . '"' : '' ) . ' aria-hidden="true"></span>' . esc_html( $user ? $user->display_name : '#' . $row->user_id ) . ' <code>' . esc_html( $row->client_id ) . '</code></span><span class="presence-debug-value" data-presence-debug-age="' . esc_attr( $age ) . '"></span>',
					'meta'   => array( 'class' => 'presence-debug-row' . ( $here && count( $here ) === $j ? ' presence-debug-break' : '' ) ),
				)
			);
		}
		$overflow = count( $rows ) - 20;
		if ( $overflow > 0 ) {
			$wp_admin_bar->add_node(
				array(
					'parent' => 'presence-debug-rooms',
					'id'     => $group . '-more',
					'title'  => esc_html(
						sprintf(
							/* translators: %s: Number of clients not listed. */
							_n( '+%s more', '+%s more', $overflow, 'presence-api' ),
							number_format_i18n( $overflow )
						)
					),
					'meta'   => array( 'class' => 'presence-debug-more' ),
				)
			);
		}
	}
}

/**
 * Renders the debugger node on its own, for the heartbeat to swap in.
 *
 * @since 0.12.0
 *
 * @return string A toolbar holding only the debugger node.
 */
function wp_presence_debugger_admin_bar_markup() {
	require_once ABSPATH . 'wp-includes/class-wp-admin-bar.php';

	$bar = new WP_Admin_Bar();
	$bar->add_group( array( 'id' => 'top-secondary' ) );
	wp_presence_debugger_admin_bar_node( $bar );

	ob_start();
	$bar->render();
	return (string) ob_get_clean();
}

/**
 * Sends a fresh debugger node with each heartbeat that asks for one.
 *
 * @since 0.12.0
 *
 * @param array $response Heartbeat response data.
 * @param array $data     Data received from the client.
 * @return array The Heartbeat response.
 */
function wp_presence_debugger_heartbeat_received( $response, $data ) {
	if ( ! wp_presence_fragment_request( $data, 'debugger' ) || ! current_user_can( 'manage_options' ) ) {
		return $response;
	}

	$response['presence-fragments']['debugger'] = wp_presence_debugger_admin_bar_markup();

	return $response;
}

/**
 * Enqueues the debugger node's styles and heartbeat readout.
 *
 * @since 0.12.0
 */
function wp_presence_debugger_admin_bar_assets() {
	if ( ! is_admin_bar_showing() || ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$css = '
		#wp-admin-bar-presence-debug > .ab-item { display: flex !important; align-items: center; gap: 6px; cursor: default; user-select: none; }
		#wp-admin-bar-presence-debug .ab-icon { margin: 0 !important; }
		#wpadminbar #wp-admin-bar-presence-debug .ab-icon::before { content: "\\f487"; top: 2px; }
		#wpadminbar #wp-admin-bar-presence-debug.is-pinned > .ab-item { color: var(--presence-debug-open-color); background: var(--presence-debug-open-background); }
		#wpadminbar #wp-admin-bar-presence-debug.is-pinned .ab-icon::before { color: inherit; }
		#wpadminbar #wp-admin-bar-presence-debug.is-lost .ab-icon::before { color: #dba617; }
		#wpadminbar #wp-admin-bar-presence-debug.is-pinned > .ab-item::after { content: "\\f537"; font: 16px/32px dashicons; margin-inline-start: 6px; vertical-align: top; }
		.admin-color-light #wpadminbar #wp-admin-bar-presence-debug.is-lost .ab-icon::before { color: #996800; }
		#wp-admin-bar-presence-debug .presence-debug-countdown { min-width: 2.5em; font-variant-numeric: tabular-nums; }
		#wp-admin-bar-presence-debug .presence-debug-indicators { display: contents; }
		#wpadminbar #wp-admin-bar-presence-debug .presence-debug-indicator::before { font: 16px/32px dashicons; }
		#wp-admin-bar-presence-debug.is-beating .ab-icon { animation: presence-debug-heart 2s cubic-bezier(0.22, 0.61, 0.36, 1); }
		@keyframes presence-debug-heart { 0% { transform: scale(1); } 5% { transform: scale(1.45); } 14% { transform: scale(0.92); } 22% { transform: scale(1.3); } 32% { transform: scale(0.97); } 42%, 100% { transform: scale(1); } }
		#wpadminbar #wp-admin-bar-presence-debug > .ab-sub-wrapper { min-width: 320px; max-height: calc(100vh - 64px); overflow-y: auto; }
		#wpadminbar #wp-admin-bar-presence-debug.is-pinned > .ab-sub-wrapper { display: block; }
		#wpadminbar #wp-admin-bar-presence-debug.is-pinned { position: relative; z-index: 1; }
		#wpadminbar #wp-admin-bar-presence-debug .ab-submenu .ab-item { display: flex; align-items: center; height: auto; min-height: 26px; }
		#wpadminbar #wp-admin-bar-presence-debug-rooms .presence-debug-row > .ab-item { padding-inline-start: 34px; }
		#wpadminbar #wp-admin-bar-presence-debug-rooms .presence-debug-more > .ab-item { padding-inline-start: 58px; }
		#wpadminbar #wp-admin-bar-presence-debug .ab-submenu .ab-item, #wpadminbar #wp-admin-bar-presence-debug .ab-submenu .ab-item > * { line-height: 1.4; }
		#wp-admin-bar-presence-debug .presence-debug-row > .ab-item { gap: 16px; cursor: default; }
		#wp-admin-bar-presence-debug .presence-debug-value { margin-inline-start: auto; font-variant-numeric: tabular-nums; user-select: none; }
		#wpadminbar #wp-admin-bar-presence-debug .presence-debug-color { display: inline-block; width: 8px; height: 8px; margin-inline: 4px 12px; border-radius: 50%; }
		#wpadminbar #wp-admin-bar-presence-debug .presence-debug-room { display: flex; }
		#wpadminbar #wp-admin-bar-presence-debug .presence-debug-room > .ab-item { flex: 1; }
		#wpadminbar #wp-admin-bar-presence-debug .presence-debug-room:has(.presence-debug-table) > .ab-item { padding-inline-start: 0; }
		#wpadminbar #wp-admin-bar-presence-debug .presence-debug-table { display: flex; order: -1; align-items: center; padding-inline: 10px 8px; color: inherit; }
		#wpadminbar #wp-admin-bar-presence-debug .presence-debug-table::before { content: "\\f17e"; font: 16px/1 dashicons; }
		#wpadminbar #wp-admin-bar-presence-debug .presence-debug-table:is(:hover, :focus) { color: var(--wp-admin-theme-color, #72aee6); }
		#wp-admin-bar-presence-debug code { padding: 0; background: none; color: inherit; font-size: 12px; font-weight: inherit; line-height: inherit; }
		#wp-admin-bar-presence-debug .presence-debug-room > .ab-item { font-weight: 600; cursor: default; }
		#wp-admin-bar-presence-debug .presence-debug-room .presence-debug-value { font-weight: 400; }
		#wp-admin-bar-presence-debug .presence-debug-row code { opacity: 0.75; }
		#wpadminbar #wp-admin-bar-presence-debug .presence-debug-break { margin-top: 6px; border-top: 1px solid color-mix(in srgb, currentColor 20%, transparent); padding-top: 6px; }
		#wp-admin-bar-presence-debug .presence-debug-more > .ab-item { cursor: default; }
		@media (prefers-reduced-motion: reduce) { #wp-admin-bar-presence-debug.is-beating .ab-icon { animation: none; } }
	';

	$i18n = array(
		'fast'       => __( 'fast', 'presence-api' ),
		'background' => __( 'background or idle', 'presence-api' ),
		'suspended'  => __( 'Suspended', 'presence-api' ),
		'lost'       => __( 'Connection lost', 'presence-api' ),
	);

	// Intl spells out the units and plurals in the page's own language.
	$js = sprintf(
		'( function ( $ ) {
			const node = document.getElementById( "wp-admin-bar-presence-debug" );
			if ( ! node || ! window.wp || ! wp.heartbeat ) {
				return;
			}
			const i18n = %s;
			const lang = document.documentElement.lang || undefined;
			const seconds = new Intl.NumberFormat( lang, { style: "unit", unit: "second", unitDisplay: "narrow" } );
			let lastSend = Date.now();

			function render() {
				// Core waits 120 seconds between beats while the window is in the background or the user is idle.
				const focused = wp.heartbeat.hasFocus();
				const period = focused ? wp.heartbeat.interval() : 120;
				const elapsed = ( Date.now() - lastSend ) / 1000;
				const left = Math.ceil( period - elapsed );
				// Only a suspended Heartbeat stops sending; a lost connection keeps retrying on schedule.
				const state = left < -10 ? "suspended" : ( wp.heartbeat.hasConnectionError() ? "lost" : "" );
				node.classList.toggle( "is-lost", "lost" === state );
				node.querySelector( ".presence-debug-countdown" ).textContent = state ? i18n[ state ] : seconds.format( Math.max( 0, left ) );
				node.querySelectorAll( "[data-presence-debug]" ).forEach( function ( el ) {
					const key = el.dataset.presenceDebug;
					if ( "interval" === key ) {
						const mode = focused ? ( 5 === period ? i18n.fast : "" ) : i18n.background;
						el.textContent = seconds.format( period ) + ( mode ? " (" + mode + ")" : "" );
					} else if ( el.dataset.presenceDebugSeconds ) {
						el.textContent = seconds.format( +el.dataset.presenceDebugSeconds );
					}
				} );
				node.querySelectorAll( "[data-presence-debug-age]" ).forEach( function ( el ) {
					el.dataset.presenceDebugT0 = el.dataset.presenceDebugT0 || Date.now();
					el.textContent = seconds.format( Math.round( +el.dataset.presenceDebugAge + ( Date.now() - el.dataset.presenceDebugT0 ) / 1000 ) );
				} );
			}

			// The heart beats when the server answers, so a failed request leaves it still.
			$( document ).on( "heartbeat-tick", function () {
				node.classList.remove( "is-beating" );
				void node.offsetWidth;
				node.classList.add( "is-beating" );
			} );
			node.addEventListener( "animationend", function () {
				node.classList.remove( "is-beating" );
			} );
			$( document ).on( "heartbeat-connection-lost heartbeat-connection-restored", render );
			$( document ).on( "heartbeat-send", function () {
				lastSend = Date.now();
				render();
			} );
			render();
			setInterval( render, 1000 );

			// A pinned menu stays open, so it slides aside for any other menu that opens over it.
			function reposition() {
				const menu = node.querySelector( ".ab-sub-wrapper" );
				if ( ! menu ) {
					return;
				}
				node.style.transform = "";
				if ( ! node.classList.contains( "is-pinned" ) ) {
					return;
				}
				const open = document.querySelector( "#wpadminbar li.menupop.hover:not(#wp-admin-bar-presence-debug) > .ab-sub-wrapper" );
				if ( ! open ) {
					return;
				}
				const mine = menu.getBoundingClientRect();
				const theirs = open.getBoundingClientRect();
				if ( mine.right > theirs.left && mine.left < theirs.right ) {
					node.style.transform = "translateX(" + ( theirs.left - mine.right ) + "px)";
				}
			}
			// Core marks the first link in a menu as expanded, which here is a table link, not this node.
			function expand() {
				node.firstElementChild.setAttribute( "aria-expanded", node.matches( ".hover, .is-pinned" ) ? "true" : "false" );
			}
			new MutationObserver( function () {
				expand();
				window.requestAnimationFrame( reposition );
			} ).observe( document.getElementById( "wpadminbar" ), { attributes: true, attributeFilter: [ "class" ], subtree: true } );

			// Core applies the open-menu colors only while hovered, so a probe borrows them.
			const probe = document.createElement( "li" );
			probe.className = "menupop hover";
			probe.appendChild( document.createElement( "span" ) ).className = "ab-item";
			document.getElementById( "wp-admin-bar-top-secondary" ).appendChild( probe );
			const open = window.getComputedStyle( probe.firstChild );
			node.style.setProperty( "--presence-debug-open-color", open.color );
			node.style.setProperty( "--presence-debug-open-background", open.backgroundColor );
			probe.remove();

			function pin( pinned ) {
				node.classList.toggle( "is-pinned", pinned );
				try {
					window.localStorage.setItem( "presence-debug-pinned", pinned ? "1" : "" );
				} catch ( e ) {}
			}
			try {
				pin( "1" === window.localStorage.getItem( "presence-debug-pinned" ) );
			} catch ( e ) {}
			node.firstElementChild.addEventListener( "click", function ( event ) {
				pin( ! node.classList.contains( "is-pinned" ) );
				// Live updates skip a focused menu, so a pinned one would stop refreshing.
				if ( event.detail ) {
					this.blur();
				}
			} );

			// Delegated, since each beat swaps the menu.
			$( node ).on( "click", ".presence-debug-table", function ( event ) {
				if ( window.open( this.href, "presence-db", "popup,width=960,height=640" ) ) {
					event.preventDefault();
				}
			} );

			if ( wp.hooks ) {
				wp.hooks.addFilter( "presence-api.liveSurfaces", "presence-api/debugger", function ( surfaces ) {
					surfaces.push( {
						key: "debugger",
						target: function () {
							return document.getElementById( "wp-admin-bar-presence-debug" );
						},
						// Only the menu and indicators are swapped, so the heart keeps beating mid-animation.
						apply: function ( element, html ) {
							const template = document.createElement( "template" );
							template.innerHTML = html.trim();
							const fresh = template.content.querySelector( ".ab-sub-wrapper" );
							const menu = element.querySelector( ".ab-sub-wrapper" );
							if ( fresh && menu ) {
								menu.replaceWith( fresh );
								element.querySelector( ".presence-debug-indicators" ).replaceWith( template.content.querySelector( ".presence-debug-indicators" ) );
								render();
								reposition();
							}
						},
					} );
					return surfaces;
				} );
			}
		} )( jQuery );',
		wp_json_encode( $i18n )
	);

	wp_register_style( 'presence-debugger-admin-bar', false, array(), WP_PRESENCE_VERSION );
	wp_enqueue_style( 'presence-debugger-admin-bar' );
	wp_add_inline_style( 'presence-debugger-admin-bar', $css );

	wp_register_script( 'presence-debugger-admin-bar', false, array( 'jquery', 'heartbeat', 'wp-hooks' ), WP_PRESENCE_VERSION, true );
	wp_enqueue_script( 'presence-debugger-admin-bar' );
	wp_add_inline_script( 'presence-debugger-admin-bar', $js );
}
