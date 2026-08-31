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
 * @param WP_Admin_Bar $wp_admin_bar The admin bar instance.
 */
function wp_presence_admin_bar_node( $wp_admin_bar ) {
	if ( ! is_user_logged_in() || ! current_user_can( 'edit_posts' ) ) {
		return;
	}

	$entries     = wp_get_presence( wp_presence_admin_room() );
	$current_uid = get_current_user_id();

	// The count includes you, the gate does not. A lone user gets no node,
	// but once anyone else is here the number matches every other surface.
	$others = array_filter(
		$entries,
		function ( $e ) use ( $current_uid ) {
			return (int) $e->user_id !== $current_uid;
		}
	);

	if ( empty( $others ) ) {
		return;
	}

	/*
	 * Determine the current screen slug to match against what the JS heartbeat
	 * sends as window.pagenow. Map $pagenow -> pagenow values.
	 */
	global $pagenow;
	$pagenow_map = array(
		'index.php'              => 'dashboard',
		'edit.php'               => 'edit',
		'post.php'               => 'post',
		'post-new.php'           => 'post-new',
		'upload.php'             => 'upload',
		'edit-comments.php'      => 'edit-comments',
		'themes.php'             => 'themes',
		'widgets.php'            => 'widgets',
		'nav-menus.php'          => 'nav-menus',
		'plugins.php'            => 'plugins',
		'users.php'              => 'users',
		'profile.php'            => 'profile',
		'user-edit.php'          => 'user-edit',
		'tools.php'              => 'tools',
		'import.php'             => 'import',
		'export.php'             => 'export',
		'options-general.php'    => 'options-general',
		'options-writing.php'    => 'options-writing',
		'options-reading.php'    => 'options-reading',
		'options-discussion.php' => 'options-discussion',
		'options-media.php'      => 'options-media',
		'options-permalink.php'  => 'options-permalink',
	);

	if ( ! is_admin() ) {
		$current_screen = 'front';
	} elseif ( isset( $pagenow_map[ $pagenow ] ) ) {
		$current_screen = $pagenow_map[ $pagenow ];
	} else {
		$current_screen = $pagenow ? str_replace( '.php', '', $pagenow ) : 'unknown';
	}

	// Split others into "here" (same screen) and "elsewhere".
	$here      = array();
	$elsewhere = array();

	foreach ( $others as $entry ) {
		$screen = isset( $entry->data['screen'] ) ? $entry->data['screen'] : '';
		if ( $screen === $current_screen ) {
			$here[] = $entry;
		} else {
			$elsewhere[] = $entry;
		}
	}

	cache_users( wp_list_pluck( $entries, 'user_id' ) );

	// Sort both groups alphabetically by display name.
	$sort_by_name = function ( $a, $b ) {
		$user_a = get_userdata( $a->user_id );
		$user_b = get_userdata( $b->user_id );
		$name_a = $user_a ? $user_a->display_name : '';
		$name_b = $user_b ? $user_b->display_name : '';
		return strcasecmp( $name_a, $name_b );
	};
	usort( $here, $sort_by_name );
	usort( $elsewhere, $sort_by_name );

	// Build a map of user_id -> post for users currently editing a post.
	$editing  = array();
	$post_ids = array();
	foreach ( wp_get_presence_by_room_prefix( 'postType/' ) as $pe ) {
		$parsed = wp_presence_parse_room( $pe->room );
		if ( ! $parsed ) {
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

	// Drop the posts the current user cannot edit. Without this the menu gives
	// the title and edit link of every post being worked on to anyone with
	// `edit_posts`. Those entries keep the generic screen label instead.
	$user_editing_post = array();
	foreach ( $editing as $user_id => $post ) {
		if ( wp_can_access_presence_room( $post['room'], $current_uid ) ) {
			$user_editing_post[ $user_id ] = $post['post_id'];
		}
	}

	// You first, then others on this page. Mirrors the flyout's "On this page".
	$stack_ids = array( $current_uid );
	foreach ( $here as $entry ) {
		$stack_ids[] = (int) $entry->user_id;
	}
	// You, plus nine others. The cap counts you now that you are in the stack.
	$stack_limit = 10;
	$stack_ids   = array_slice( array_unique( $stack_ids ), 0, $stack_limit );

	$stack_html = '<span class="presence-bar-avatars">';
	$z          = count( $stack_ids );

	foreach ( $stack_ids as $stack_uid ) {
		$user = get_userdata( $stack_uid );
		if ( ! $user ) {
			continue;
		}
		$stack_html .= '<img src="' . esc_url( get_avatar_url( $user->ID, array( 'size' => 32 ) ) ) . '" width="16" height="16" style="z-index:' . (int) $z . '" alt="' . esc_attr( $user->display_name ) . '" title="' . esc_attr( $user->display_name ) . '" />';
		--$z;
	}

	$stack_html .= '</span>';

	$online_count = count( wp_presence_online_user_ids( $entries ) );

	/* translators: %d: Number of online users, including the current user. */
	$label = sprintf( _n( '%d online', '%d online', $online_count, 'presence-api' ), $online_count );

	$wp_admin_bar->add_node(
		array(
			'id'    => 'presence-online',
			'title' => $stack_html . '<span class="presence-bar-count">' . esc_html( $label ) . '</span>',
			'href'  => false,
			'meta'  => array(
				'class'      => 'presence-bar-node menupop',
				'tabindex'   => 0,
				'aria-label' => sprintf(
				/* translators: %d: Number of users currently online. */
					_n( '%d user online', '%d users online', $online_count, 'presence-api' ),
					$online_count
				),
			),
		)
	);

	// Add dropdown items grouped by "here" then "elsewhere".
	// "On this page" always shows (you're always here).
	$wp_admin_bar->add_node(
		array(
			'parent' => 'presence-online',
			'id'     => 'presence-group-here',
			'title'  => '<span class="presence-bar-group-label">' . esc_html__( 'On this page', 'presence-api' ) . '</span>',
			'href'   => false,
			'meta'   => array(
				'class'    => 'presence-bar-group-header',
				'tabindex' => 0,
			),
		)
	);

	// Current user first.
	$current_user = get_userdata( $current_uid );

	$wp_admin_bar->add_node(
		array(
			'parent' => 'presence-online',
			'id'     => 'presence-user-self',
			'title'  => esc_html( $current_user ? $current_user->display_name : __( 'You', 'presence-api' ) ) . ' <span class="presence-bar-you">(' . esc_html__( 'you', 'presence-api' ) . ')</span>',
			'href'   => false,
			'meta'   => array( 'tabindex' => 0 ),
		)
	);

	// Others on the same page (capped at 10).
	$max_here = 10;
	$shown    = 0;

	foreach ( $here as $entry ) {
		if ( $shown >= $max_here ) {
			$remaining = count( $here ) - $max_here;
			$wp_admin_bar->add_node(
				array(
					'parent' => 'presence-online',
					'id'     => 'presence-here-overflow',
					/* translators: %d: Number of additional online users. */
					'title'  => '<span class="presence-bar-screen">' . esc_html( sprintf( __( '+%d more', 'presence-api' ), $remaining ) ) . '</span>',
					'href'   => false,
					'meta'   => array( 'tabindex' => 0 ),
				)
			);
			break;
		}

		$user = get_userdata( $entry->user_id );
		if ( ! $user ) {
			continue;
		}
		$wp_admin_bar->add_node(
			array(
				'parent' => 'presence-online',
				'id'     => 'presence-user-' . $entry->user_id,
				'title'  => esc_html( $user->display_name ),
				'href'   => false,
				'meta'   => array( 'tabindex' => 0 ),
			)
		);
		++$shown;
	}

	if ( ! empty( $elsewhere ) ) {
		$wp_admin_bar->add_node(
			array(
				'parent' => 'presence-online',
				'id'     => 'presence-group-elsewhere',
				'title'  => '<span class="presence-bar-group-label">' . esc_html__( 'Elsewhere', 'presence-api' ) . '</span>',
				'href'   => false,
				'meta'   => array(
					'class'    => 'presence-bar-group-header',
					'tabindex' => 0,
				),
			)
		);

		$max_elsewhere = 10;
		$shown         = 0;

		foreach ( $elsewhere as $entry ) {
			if ( $shown >= $max_elsewhere ) {
				$remaining = count( $elsewhere ) - $max_elsewhere;
				$wp_admin_bar->add_node(
					array(
						'parent' => 'presence-online',
						'id'     => 'presence-elsewhere-overflow',
						/* translators: %d: Number of additional online users. */
						'title'  => '<span class="presence-bar-screen">' . esc_html( sprintf( __( '+%d more', 'presence-api' ), $remaining ) ) . '</span>',
						'href'   => admin_url( 'users.php?presence_status=online' ),
					)
				);
				break;
			}

			$user = get_userdata( $entry->user_id );
			if ( ! $user ) {
				continue;
			}
			$screen       = isset( $entry->data['screen'] ) ? $entry->data['screen'] : '';
			$entry_ps     = isset( $entry->data['post_status'] ) ? $entry->data['post_status'] : '';
			$screen_label = $screen ? WP_Presence_Widget_Whos_Online::get_rich_screen_label( $screen, $entry_ps ) : '';
			$screen_url   = $screen ? WP_Presence_Widget_Whos_Online::get_screen_url( $screen ) : false;
			$is_title     = false;

			// If user is editing a specific post, show the post title and link to it.
			if ( in_array( $screen, array( 'post', 'edit-post' ), true ) && isset( $user_editing_post[ (int) $entry->user_id ] ) ) {
				$post_id    = $user_editing_post[ (int) $entry->user_id ];
				$post_title = get_the_title( $post_id );
				if ( $post_title ) {
					$screen_label = $post_title;
					$is_title     = true;
				}
				$screen_url = get_edit_post_link( $post_id, 'raw' );
			}

			// If user is viewing a post on the frontend, show the post title and link to it.
			if ( 'front' === $screen && ! empty( $entry->data['title'] ) ) {
				$screen_label = $entry->data['title'];
				$is_title     = true;
				if ( ! empty( $entry->data['post_id'] ) ) {
					$screen_url = get_permalink( (int) $entry->data['post_id'] );
				}
			}

			$item_title = esc_html( $user->display_name );

			if ( $screen_label ) {
				if ( $is_title ) {
					$formatted = esc_html( $screen_label );
				} else {
					$parts     = explode( ' ', $screen_label, 2 );
					$formatted = count( $parts ) > 1
						? '<em>' . esc_html( $parts[0] ) . '</em> ' . esc_html( $parts[1] )
						: esc_html( $screen_label );
				}
				$item_title .= ' <span class="presence-bar-screen">&middot; ' . $formatted . '</span>';
			}

			$wp_admin_bar->add_node(
				array(
					'parent' => 'presence-online',
					'id'     => 'presence-user-' . $entry->user_id,
					'title'  => $item_title,
					'href'   => $screen_url ? $screen_url : false,
					'meta'   => $screen_url ? array() : array( 'tabindex' => 0 ),
				)
			);
			++$shown;
		}
	}

	// Add "View online users" link at the bottom.
	$wp_admin_bar->add_node(
		array(
			'parent' => 'presence-online',
			'id'     => 'presence-view-all',
			'title'  => __( 'View online users', 'presence-api' ),
			'href'   => admin_url( 'users.php?presence_status=online' ),
		)
	);
}

/**
 * Enqueues CSS for the admin bar presence indicator.
 */
function wp_presence_admin_bar_assets() {
	if ( ! is_user_logged_in() || ! is_admin_bar_showing() || ! current_user_can( 'edit_posts' ) ) {
		return;
	}

	$css = '
		#wp-admin-bar-presence-online > .ab-item { display: flex !important; align-items: center; gap: 2px; cursor: default; }
		#wp-admin-bar-presence-online .presence-bar-avatars { display: inline-flex; align-items: center; vertical-align: middle; margin-right: 4px; }
		#wp-admin-bar-presence-online .presence-bar-avatars img { border-radius: 50%; width: 16px !important; height: 16px !important; margin-inline-start: -4px; box-shadow: 0 0 0 1.5px #1d2327; position: relative; }
		#wp-admin-bar-presence-online .presence-bar-avatars img:first-child { margin-inline-start: 0; }
		#wp-admin-bar-presence-online .presence-bar-count { vertical-align: middle; }
		#wp-admin-bar-presence-online .presence-bar-you { color: #a7aaad; font-weight: normal; }
		#wp-admin-bar-presence-online .presence-bar-screen { color: #a7aaad; font-size: 12px; }
		#wp-admin-bar-presence-online .presence-bar-screen em { font-style: italic; }
		#wp-admin-bar-presence-online .presence-bar-group-header > .ab-item { font-size: 11px !important; text-transform: uppercase; letter-spacing: 0.5px; pointer-events: none; padding-bottom: 0 !important; }
		#wp-admin-bar-presence-online .presence-bar-group-header > .ab-item:not(:focus) { color: #a7aaad !important; }
		.admin-color-light #wpadminbar #wp-admin-bar-presence-online .presence-bar-count,
		.admin-color-light #wpadminbar #wp-admin-bar-presence-online .presence-bar-you,
		.admin-color-light #wpadminbar #wp-admin-bar-presence-online .presence-bar-screen { color: #50575e !important; }
		.admin-color-light #wpadminbar #wp-admin-bar-presence-online .presence-bar-group-header > .ab-item:not(:focus) { color: #50575e !important; }
		#wp-admin-bar-presence-group-elsewhere > .ab-item { border-top: 1px solid #3c4043 !important; margin-top: 4px !important; padding-top: 8px !important; }
		#wp-admin-bar-presence-view-all .ab-item { border-top: 1px solid #3c4043 !important; font-style: italic; }
	';

	wp_register_style( 'presence-admin-bar', false, array(), WP_PRESENCE_VERSION );
	wp_enqueue_style( 'presence-admin-bar' );
	wp_add_inline_style( 'presence-admin-bar', $css );
}
