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
 * Adds a presence indicator to the admin bar showing other online users.
 *
 * @param WP_Admin_Bar $wp_admin_bar The admin bar instance.
 */
function wp_presence_admin_bar_node( $wp_admin_bar ) {
	if ( ! is_user_logged_in() || ! current_user_can( 'edit_posts' ) ) {
		return;
	}

	$entries     = wp_get_presence( 'admin/online' );
	$current_uid = get_current_user_id();

	// Only show the admin bar node when other users are online.
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

	// Build a map of user_id -> post_id for users currently editing a post.
	$user_editing_post = array();
	$post_entries      = wp_get_presence_by_room_prefix( 'postType/' );
	foreach ( $post_entries as $pe ) {
		// Room format: postType/{type}:{id}.
		if ( preg_match( '/^postType\/[^:]+:(\d+)$/', $pe->room, $m ) ) {
			$user_editing_post[ (int) $pe->user_id ] = (int) $m[1];
		}
	}
	if ( ! empty( $user_editing_post ) ) {
		_prime_post_caches( array_unique( array_values( $user_editing_post ) ) );
	}

	// "Here" count includes you.
	$here_count      = count( $here ) + 1; // +1 for current user.
	$elsewhere_count = count( $elsewhere );

	// Build avatar stack from users on this page only (not current user), capped at 10.
	$stack_html   = '<span class="presence-bar-avatars">';
	$current_user = get_userdata( $current_uid );
	$stack_limit  = 10;
	$stack_show   = array_slice( $here, 0, $stack_limit );
	$z            = count( $stack_show );

	foreach ( $stack_show as $entry ) {
		$user = get_userdata( $entry->user_id );
		if ( ! $user ) {
			continue;
		}
		$stack_html .= '<img src="' . esc_url( get_avatar_url( $user->ID, array( 'size' => 32 ) ) ) . '" width="16" height="16" style="z-index:' . (int) $z . '" alt="' . esc_attr( $user->display_name ) . '" title="' . esc_attr( $user->display_name ) . '" />';
		--$z;
	}

	$stack_html .= '</span>';

	$others_count = count( $others );

	/* translators: %d: Number of other online users (excluding current user). */
	$label = sprintf( _n( '%d online', '%d online', $others_count, 'presence-api' ), $others_count );

	$wp_admin_bar->add_node(
		array(
			'id'    => 'presence-online',
			'title' => $stack_html . '<span class="presence-bar-count">' . esc_html( $label ) . '</span>',
			'href'  => false,
			'meta'  => array(
				'class'      => 'presence-bar-node menupop',
				'tabindex'   => 0,
				'aria-label' => sprintf(
				/* translators: %d: Number of other users currently online. */
					_n( '%d other user online', '%d other users online', $others_count, 'presence-api' ),
					$others_count
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
			'meta'   => array( 'class' => 'presence-bar-group-header' ),
		)
	);

	// Current user first.
	$wp_admin_bar->add_node(
		array(
			'parent' => 'presence-online',
			'id'     => 'presence-user-self',
			'title'  => esc_html( $current_user ? $current_user->display_name : __( 'You', 'presence-api' ) ) . ' <span class="presence-bar-you">(' . esc_html__( 'you', 'presence-api' ) . ')</span>',
			'href'   => false,
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
				'meta'   => array( 'class' => 'presence-bar-group-header' ),
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
		#wp-admin-bar-presence-online .presence-bar-group-header > .ab-item { color: #a7aaad !important; font-size: 11px !important; text-transform: uppercase; letter-spacing: 0.5px; pointer-events: none; padding-bottom: 0 !important; }
		#wp-admin-bar-presence-group-elsewhere > .ab-item { border-top: 1px solid #3c4043 !important; margin-top: 4px !important; padding-top: 8px !important; }
		#wp-admin-bar-presence-view-all .ab-item { border-top: 1px solid #3c4043 !important; font-style: italic; }
	';

	wp_register_style( 'presence-admin-bar', false, array(), WP_PRESENCE_VERSION );
	wp_enqueue_style( 'presence-admin-bar' );
	wp_add_inline_style( 'presence-admin-bar', $css );
}
