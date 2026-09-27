<?php
/**
 * Admin bar presence indicator.
 *
 * @package Presence_API
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds a presence indicator to the admin bar showing online users.
 *
 * @since 0.1.1
 *
 * @param WP_Admin_Bar $wp_admin_bar The admin bar instance.
 * @param string|null  $screen       Optional. The screen to group by, when not rendering the current page.
 *                                   Default null.
 */
function wp_presence_admin_bar_node( $wp_admin_bar, $screen = null ) {
	if ( ! is_user_logged_in() || ! current_user_can( 'edit_posts' ) ) {
		return;
	}

	$entries     = wp_get_presence( wp_presence_admin_room() );
	$current_uid = get_current_user_id();

	// The node stays put when the current user is alone, so the bar never shifts and presence always shows it is on.
	$others = array_filter(
		$entries,
		function ( $e ) use ( $current_uid ) {
			return (int) $e->user_id !== $current_uid;
		}
	);

	// The ping reports window.pagenow, which core prints from the current screen's ID.
	if ( null !== $screen ) {
		$current_screen = $screen;
	} elseif ( ! is_admin() ) {
		$current_screen = 'front';
	} else {
		$wp_screen      = get_current_screen();
		$current_screen = $wp_screen ? $wp_screen->id : 'unknown';
	}

	$editing  = array();
	$post_ids = array();
	foreach ( wp_get_presence_by_room_prefix( 'postType/' ) as $pe ) {
		$parsed = wp_presence_parse_room( $pe->room );
		if ( ! $parsed || isset( $editing[ (int) $pe->user_id ] ) ) {
			continue;
		}
		$editing[ (int) $pe->user_id ] = array(
			'room'    => $pe->room,
			'post_id' => $parsed['post_id'],
		);
		$post_ids[]                    = $parsed['post_id'];
	}

	// The capability check below calls get_post() per room, so prime in one go.
	// It reads neither the term nor the meta cache.
	if ( ! empty( $post_ids ) ) {
		_prime_post_caches( array_unique( $post_ids ), false, false );
	}

	// Hide titles and edit links for posts the current user cannot edit.
	$user_editing_post = array();
	foreach ( $editing as $user_id => $post ) {
		if ( wp_can_access_presence_room( $post['room'], $current_uid ) ) {
			$user_editing_post[ $user_id ] = $post['post_id'];
		}
	}

	$place = function ( $entry ) use ( $user_editing_post ) {
		$screen  = isset( $entry->data['screen'] ) ? (string) $entry->data['screen'] : '';
		$post_id = 'front' === $screen ? (int) ( $entry->data['post_id'] ?? 0 ) : (int) ( $user_editing_post[ (int) $entry->user_id ] ?? 0 );
		return $post_id && ( 'front' === $screen || get_post_type( $post_id ) === $screen ) ? $screen . ':' . $post_id : $screen;
	};

	$own        = wp_list_filter( $entries, array( 'user_id' => $current_uid ) );
	$own        = reset( $own );
	$here_place = $own && isset( $own->data['screen'] ) && $own->data['screen'] === $current_screen ? $place( $own ) : $current_screen;

	$here = array_values(
		array_filter(
			$others,
			function ( $entry ) use ( $place, $here_place ) {
				return $place( $entry ) === $here_place;
			}
		)
	);

	$elsewhere = array_values(
		array_filter(
			$others,
			function ( $entry ) use ( $place, $here_place ) {
				return $place( $entry ) !== $here_place;
			}
		)
	);

	cache_users( wp_list_pluck( $entries, 'user_id' ) );

	$by_name = function ( $a, $b ) {
		$user_a = get_userdata( $a->user_id );
		$user_b = get_userdata( $b->user_id );
		return strcasecmp( $user_a ? $user_a->display_name : '', $user_b ? $user_b->display_name : '' );
	};
	usort( $here, $by_name );

	// Links are derived from the screen, never taken from the ping, so a row can only lead somewhere the admin already routes.
	$where = function ( $entry ) use ( $user_editing_post ) {
		$screen = wp_presence_get_entry_screen( $entry );
		if ( '' === $screen || sanitize_key( $screen ) !== $screen ) {
			return array( '', '' );
		}

		$title   = isset( $entry->data['title'] ) ? (string) $entry->data['title'] : '';
		$post_id = (int) ( $user_editing_post[ (int) $entry->user_id ] ?? 0 );
		$type    = substr( $screen, 5 );
		$path    = null;

		if ( 'front' === $screen ) {
			$post_id = (int) ( $entry->data['post_id'] ?? 0 );
			if ( ! $post_id ) {
				return array( $title, '' );
			}
			return current_user_can( 'read_post', $post_id ) ? array( $title, get_permalink( $post_id ) ) : array( '', '' );
		} elseif ( $post_id && get_post_type( $post_id ) === $screen ) {
			return array( get_the_title( $post_id ), (string) get_edit_post_link( $post_id, 'raw' ), true );
		} elseif ( 'dashboard' === $screen ) {
			$path = '';
		} elseif ( preg_match( '/_page_(.+)$/', $screen, $matches ) ) {
			$path = 'admin.php?page=' . $matches[1];
		} elseif ( 0 === strpos( $screen, 'edit-' ) && post_type_exists( $type ) ) {
			$path = 'edit.php?post_type=' . $type;
		} elseif ( 0 === strpos( $screen, 'edit-' ) && taxonomy_exists( $type ) ) {
			$path = 'edit-tags.php?taxonomy=' . $type;
		} elseif ( ! in_array( $screen, array( 'post', 'post-new', 'profile', 'comment', 'user-edit', 'term', 'media' ), true ) && file_exists( ABSPATH . 'wp-admin/' . $screen . '.php' ) ) {
			$path = $screen . '.php';
		}

		return array( $title, null === $path ? '' : admin_url( $path ) );
	};

	$places = array();
	foreach ( $elsewhere as $entry ) {
		$places[ (int) $entry->user_id ] = $where( $entry );
	}

	$key    = function ( $place ) {
		return '' === $place[0] ? '' : $place[0] . "\0" . $place[1];
	};
	$crowds = array_count_values( array_filter( array_map( $key, $places ) ) );

	// Posts being edited come first, then the busiest shared screens, then screens each person has to themselves, and hidden places last.
	$rank = function ( $entry ) use ( $places, $crowds, $key ) {
		$place = $places[ (int) $entry->user_id ];
		return array( empty( $place[2] ), '' === $place[1], -( $crowds[ $key( $place ) ] ?? 0 ), $key( $place ) );
	};
	usort(
		$elsewhere,
		function ( $a, $b ) use ( $rank, $by_name ) {
			$order = $rank( $a ) <=> $rank( $b );
			return $order ? $order : $by_name( $a, $b );
		}
	);

	// My Account already shows the current user, so the faces are only the others on this page.
	$here_ids  = array_unique( array_map( 'intval', wp_list_pluck( $here, 'user_id' ) ) );
	$stack_ids = array_slice( $here_ids, 0, 8 );

	$colors = array();
	foreach ( $entries as $entry ) {
		$colors[ (int) $entry->user_id ] = wp_presence_entry_color( $entry );
	}

	// Seven colors cannot go round a busy site, so people on this page trade a clash for a free one.
	$colors = wp_presence_spread_colors( array_intersect_key( $colors, array_flip( $here_ids ) ) ) + $colors;

	$avatar = function ( $user, $size, $alt = '' ) use ( $colors ) {
		$color = ' style="outline-color:' . esc_attr( $colors[ $user->ID ] ?? wp_presence_default_user_color( $user->ID ) ) . '"';
		return '<img class="presence-bar-avatar" src="' . esc_url( get_avatar_url( $user->ID, array( 'size' => wp_presence_get_avatar_fetch_size( $size ) ) ) ) . '" width="' . (int) $size . '" height="' . (int) $size . '"' . $color . ' alt="' . esc_attr( $alt ) . '" />';
	};

	$stack_html = '';

	foreach ( $stack_ids as $stack_uid ) {
		$user = get_userdata( $stack_uid );
		if ( ! $user ) {
			continue;
		}
		$stack_html .= str_replace( ' />', ' title="' . esc_attr( $user->display_name ) . '" />', $avatar( $user, 20, $user->display_name ) );
	}

	// Core drops a node's aria-label, so the spoken label rides in the title and the faces stay quiet.
	$stack_html = '' !== $stack_html ? '<span class="presence-bar-avatars" aria-hidden="true">' . $stack_html . '</span>' : '';

	$online_count = count( wp_presence_online_user_ids( $entries ) );

	/* translators: %d: Number of online users, including the current user. */
	$label = sprintf( _n( '%d online', '%d online', $online_count, 'presence-api' ), $online_count );
	/* translators: %d: Number of users currently online. */
	$sr_label = sprintf( _n( '%d user online', '%d users online', $online_count, 'presence-api' ), $online_count );

	$users_url = current_user_can( 'list_users' ) ? wp_nonce_url( admin_url( 'users.php?presence_status=online' ), 'presence_online_filter' ) : false;

	$wp_admin_bar->add_node(
		array(
			// Just inside My Account, which core adds later at 9991; top-secondary renders in DOM order.
			'parent' => 'top-secondary',
			'id'     => 'presence-online',
			'title'  => $stack_html . '<span class="presence-bar-count" aria-hidden="true">' . esc_html( $label ) . '</span><span class="screen-reader-text">' . esc_html( $sr_label ) . '</span>',
			'href'   => $users_url,
			'meta'   => $users_url ? array( 'class' => 'presence-bar-node menupop' ) : array(
				'class'    => 'presence-bar-node menupop',
				'tabindex' => 0,
			),
		)
	);

	// The menu scrolls, so the cap only bounds the markup every Heartbeat tick resends.
	$max_rows = 100;

	foreach ( array_slice( $here, 0, $max_rows ) as $entry ) {
		$user = get_userdata( $entry->user_id );
		if ( ! $user ) {
			continue;
		}
		$idle = time() - strtotime( $entry->date_gmt . ' +0000' ) > wp_presence_idle_threshold();
		$wp_admin_bar->add_node(
			array(
				'parent' => 'presence-online',
				'id'     => 'presence-user-' . $user->ID,
				'title'  => $avatar( $user, 18 ) . esc_html( $user->display_name ) . ( $idle ? '<span class="presence-bar-idle">' . esc_html__( 'Idle', 'presence-api' ) . '</span>' : '' ),
			)
		);
	}

	$budget = max( 0, $max_rows - count( $here ) );
	$rows   = array();
	$last   = '';
	foreach ( $elsewhere as $entry ) {
		$place = $places[ (int) $entry->user_id ];
		// People on a shared screen fold into one row with a count; unlinked screens like Profile are each person's own.
		if ( '' !== $place[1] && empty( $place[2] ) ) {
			if ( $key( $place ) !== $last ) {
				if ( count( $rows ) + 1 > $budget ) {
					break;
				}
				$rows[] = array( $place[0], $place[1], false, array() );
			}
			$rows[ count( $rows ) - 1 ][3][] = get_userdata( $entry->user_id );
			$last                            = $key( $place );
			continue;
		}
		// Each post heads the people on it, and is never left without one.
		$heads = '' !== $key( $place ) && $key( $place ) !== $last;
		if ( count( $rows ) + ( $heads ? 2 : 1 ) > $budget ) {
			break;
		}
		if ( $heads ) {
			$rows[] = $place;
		}
		$rows[] = $entry;
		$last   = $key( $place );
	}

	if ( $rows ) {
		$wp_admin_bar->add_group(
			array(
				'parent' => 'presence-online',
				'id'     => 'presence-elsewhere',
				'meta'   => array( 'class' => 'ab-sub-secondary' ),
			)
		);
	}

	$shown = 0;
	foreach ( $rows as $i => $row ) {
		if ( is_array( $row ) && isset( $row[3] ) ) {
			$people = array_filter( $row[3] );
			$names  = wp_sprintf( '%l', wp_list_pluck( $people, 'display_name' ) );
			$shown += count( $row[3] );
			$wp_admin_bar->add_node(
				array(
					'parent' => 'presence-elsewhere',
					'id'     => 'presence-place-' . $i,
					'title'  => '<span class="presence-bar-label">' . esc_html( $row[0] ) . '</span><span class="presence-bar-crowd" aria-hidden="true">' . count( $row[3] ) . '</span><span class="screen-reader-text">, ' . esc_html( $names ) . '</span>',
					'href'   => '' !== $row[1] ? $row[1] : false,
					'meta'   => array(
						'class' => 'presence-bar-screen',
						'title' => $names,
					),
				)
			);
			continue;
		}
		if ( is_array( $row ) ) {
			$wp_admin_bar->add_node(
				array(
					'parent' => 'presence-elsewhere',
					'id'     => 'presence-place-' . $i,
					'title'  => esc_html( $row[0] ),
					'href'   => '' !== $row[1] ? $row[1] : false,
					'meta'   => array(
						'class' => 'presence-bar-place',
						'title' => $row[0],
					),
				)
			);
			continue;
		}
		++$shown;
		$user = get_userdata( $row->user_id );
		if ( ! $user ) {
			continue;
		}
		$label = $places[ $user->ID ][0];
		$wp_admin_bar->add_node(
			array(
				'parent' => 'presence-elsewhere',
				'id'     => 'presence-user-' . $user->ID,
				/* translators: %s: Where the person is, such as a post title or an admin screen. */
				'title'  => esc_html( $user->display_name ) . ( '' !== $label ? '<span class="screen-reader-text">, ' . esc_html( sprintf( __( 'on %s', 'presence-api' ), $label ) ) . '</span>' : '' ),
				'meta'   => '' !== $label ? array( 'class' => 'presence-bar-there' ) : array(),
			)
		);
	}

	$more = count( $here ) + count( $elsewhere ) - min( count( $here ), $max_rows ) - $shown;
	if ( $more > 0 ) {
		$wp_admin_bar->add_node(
			array(
				'parent' => $rows ? 'presence-elsewhere' : 'presence-online',
				'id'     => 'presence-more',
				'title'  => $users_url
					? __( 'See everyone online', 'presence-api' )
					/* translators: %d: Number of people online the menu leaves out. */
					: sprintf( _n( '%d more', '%d more', $more, 'presence-api' ), $more ),
				'href'   => $users_url,
				'meta'   => array( 'class' => 'presence-bar-more' ),
			)
		);
	}
}

/**
 * Renders the admin bar presence node on its own, for the heartbeat to swap in.
 *
 * @since 0.9.0
 *
 * @param string $screen The screen the heartbeat came from.
 * @return string The node's list item markup, or an empty string.
 */
function wp_presence_admin_bar_node_markup( $screen ) {
	require_once ABSPATH . 'wp-includes/class-wp-admin-bar.php';

	// Core only renders the whole bar, so a subclass reaches its item renderer.
	$bar = new class() extends WP_Admin_Bar {
		/**
		 * Renders one top-level node.
		 *
		 * @since 0.9.0
		 *
		 * @param string $id Node ID.
		 * @return string The node's list item markup.
		 */
		public function render_node( $id ) {
			// Fetched first; _bind() fills in its children and then hides every node.
			$node = $this->_get_node( $id );
			if ( ! $node ) {
				return '';
			}
			$this->_bind();
			ob_start();
			$this->_render_item( $node );
			return (string) ob_get_clean();
		}
	};

	$bar->add_group( array( 'id' => 'top-secondary' ) );
	wp_presence_admin_bar_node( $bar, $screen );

	return $bar->render_node( 'presence-online' );
}

/**
 * Sends a fresh admin bar presence node with each heartbeat that asks for one.
 *
 * @since 0.9.0
 *
 * @param array $response Heartbeat response data.
 * @param array $data     Data received from the client.
 * @return array The Heartbeat response.
 */
function wp_presence_admin_bar_heartbeat_received( $response, $data ) {
	if ( empty( $data['presence-admin-bar'] ) || empty( $data['presence-ping']['screen'] ) || ! current_user_can( 'edit_posts' ) ) {
		return $response;
	}

	// The screen decides who is "On this page", so an unproven one would reveal where anyone is.
	$screen = sanitize_text_field( $data['presence-ping']['screen'] );
	$token  = $data['presence-ping']['token'] ?? '';
	if ( ! is_string( $token ) || ! wp_verify_nonce( $token, 'wp_presence_screen_' . $screen ) ) {
		return $response;
	}

	$response['presence-admin-bar'] = wp_presence_admin_bar_node_markup( $screen );

	return $response;
}

/**
 * Sends a fresh screen token whenever core refreshes its Heartbeat nonce.
 *
 * @since 0.9.0
 *
 * @param array  $response  The Heartbeat response.
 * @param array  $data      Data received from the client.
 * @param string $screen_id The screen ID.
 * @return array The Heartbeat response.
 */
function wp_presence_refresh_screen_token( $response, $data, $screen_id ) {
	$response['presence-screen-token'] = array(
		'screen' => $screen_id,
		'token'  => wp_create_nonce( 'wp_presence_screen_' . $screen_id ),
	);

	return $response;
}

/**
 * Enqueues CSS for the admin bar presence indicator.
 *
 * @since 0.1.1
 */
function wp_presence_admin_bar_assets() {
	if ( ! is_user_logged_in() || ! is_admin_bar_showing() || ! current_user_can( 'edit_posts' ) ) {
		return;
	}

	$css = '
		#wp-admin-bar-presence-online > .ab-item { display: flex !important; align-items: center; gap: 6px; }
		#wp-admin-bar-presence-online > div.ab-item { cursor: default; }
		#wp-admin-bar-presence-online .presence-bar-avatars { display: inline-flex; align-items: center; gap: 9px; margin-inline: 3px; }
		#wp-admin-bar-presence-online .presence-bar-avatar { width: 20px !important; height: 20px !important; border-radius: 50%; outline: 2px solid; outline-offset: 1px; }
		@media (max-width: 1024px) { #wp-admin-bar-presence-online .presence-bar-avatars > :nth-child(n+6) { display: none; } }
		#wp-admin-bar-presence-online .ab-sub-wrapper { max-height: calc(100vh - 64px); overflow-y: auto; color-scheme: dark; }
		.admin-color-light #wp-admin-bar-presence-online .ab-sub-wrapper { color-scheme: light; }
		#wp-admin-bar-presence-online .ab-submenu .ab-item { display: flex !important; align-items: center; gap: 8px; }
		#wp-admin-bar-presence-online .ab-submenu .presence-bar-avatar { width: 18px !important; height: 18px !important; flex: none; outline-offset: 0; }
		#wp-admin-bar-presence-online .presence-bar-idle { margin-inline-start: auto; padding-inline-start: 16px; }
		#wp-admin-bar-presence-online .presence-bar-place > .ab-item { display: block !important; max-width: 20em; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-weight: 600; }
		#wp-admin-bar-presence-online .presence-bar-there > .ab-item { padding-inline-start: 24px; }
		#wp-admin-bar-presence-online .presence-bar-screen > .ab-item { max-width: 24em; }
		#wp-admin-bar-presence-online .presence-bar-label { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
		#wp-admin-bar-presence-online .presence-bar-crowd { margin-inline-start: auto; padding-inline-start: 16px; }
		#wp-admin-bar-presence-online .ab-submenu div.ab-item { cursor: default; }
		#wp-admin-bar-presence-online :is(.presence-bar-idle, .presence-bar-crowd, .presence-bar-more > .ab-item) { opacity: .8; }
		.admin-color-light #wp-admin-bar-presence-online :is(.presence-bar-idle, .presence-bar-crowd, .presence-bar-more > .ab-item) { opacity: 1; }
		.admin-color-light #wpadminbar #wp-admin-bar-presence-online .presence-bar-count { color: #50575e !important; }
	';

	// The current user wears the admin theme color, as in the block editor, so it never matches a ring on the page.
	$css .= '#wpadminbar:has(.presence-bar-avatars) #wp-admin-bar-my-account.with-avatar > .ab-item img { outline: 2px solid var(--wp-admin-theme-color, #2271b1); outline-offset: 1px; }';

	wp_register_style( 'presence-admin-bar', false, array(), WP_PRESENCE_VERSION );
	wp_enqueue_style( 'presence-admin-bar' );
	wp_add_inline_style( 'presence-admin-bar', $css );
}
