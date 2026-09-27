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

	// The node stays put when you are alone, so the bar never shifts and presence always shows it is on.
	$others = array_filter(
		$entries,
		function ( $e ) use ( $current_uid ) {
			return (int) $e->user_id !== $current_uid;
		}
	);

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

	// Others on this page only; your own face is already in My Account beside it. Five whole faces fit.
	$stack_ids = array_slice( array_unique( array_map( 'intval', wp_list_pluck( $here, 'user_id' ) ) ), 0, 5 );

	$colors = array();
	foreach ( $entries as $entry ) {
		$colors[ (int) $entry->user_id ] = wp_presence_entry_color( $entry );
	}

	// Only people on this page wear their color; it pairs them with what they do on the screen you share.
	$avatar = function ( $user, $size, $alt = '', $ring = true ) use ( $colors ) {
		$color = $ring ? ' style="outline-color:' . esc_attr( $colors[ $user->ID ] ?? wp_presence_default_user_color( $user->ID ) ) . '"' : '';
		return '<img class="presence-bar-avatar' . ( $ring ? '' : ' presence-bar-avatar-plain' ) . '" src="' . esc_url( get_avatar_url( $user->ID, array( 'size' => wp_presence_get_avatar_fetch_size( $size ) ) ) ) . '" width="' . (int) $size . '" height="' . (int) $size . '"' . $color . ' alt="' . esc_attr( $alt ) . '" />';
	};

	$stack_html = '';

	foreach ( $stack_ids as $stack_uid ) {
		$user = get_userdata( $stack_uid );
		if ( ! $user ) {
			continue;
		}
		$stack_html .= str_replace( ' />', ' title="' . esc_attr( $user->display_name ) . '" />', $avatar( $user, 20, $user->display_name ) );
	}

	$stack_html = '' !== $stack_html ? '<span class="presence-bar-avatars">' . $stack_html . '</span>' : '';

	$online_count = count( wp_presence_online_user_ids( $entries ) );

	/* translators: %d: Number of online users, including the current user. */
	$label = sprintf( _n( '%d online', '%d online', $online_count, 'presence-api' ), $online_count );
	/* translators: %d: Number of users currently online. */
	$aria_label = sprintf( _n( '%d user online', '%d users online', $online_count, 'presence-api' ), $online_count );

	if ( empty( $others ) ) {
		$label      = __( 'Just you', 'presence-api' );
		$aria_label = __( 'Only you are online', 'presence-api' );
	}

	$wp_admin_bar->add_node(
		array(
			// Just inside My Account, which core adds later at 9991; top-secondary renders in DOM order.
			'parent' => 'top-secondary',
			'id'     => 'presence-online',
			'title'  => $stack_html . '<span class="presence-bar-count">' . esc_html( $label ) . '</span>',
			'href'   => false,
			'meta'   => array(
				'class'      => 'presence-bar-node menupop',
				'tabindex'   => 0,
				'aria-label' => $aria_label,
			),
		)
	);

	// Each section is a core group, like the WordPress logo menu.
	$add_section = function ( $id, $label ) use ( $wp_admin_bar ) {
		$wp_admin_bar->add_group(
			array(
				'parent' => 'presence-online',
				'id'     => 'presence-' . $id,
			)
		);
		$wp_admin_bar->add_node(
			array(
				'parent' => 'presence-' . $id,
				'id'     => 'presence-group-' . $id,
				'title'  => '<span class="presence-bar-group-label">' . esc_html( $label ) . '</span>',
				'href'   => false,
				'meta'   => array( 'class' => 'presence-bar-group-header' ),
			)
		);
	};

	$row = function ( $group, $user, $context = '', $href = false ) use ( $wp_admin_bar, $avatar ) {
		$ring = 'here' === $group;
		$wp_admin_bar->add_node(
			array(
				'parent' => 'presence-' . $group,
				'id'     => 'presence-user-' . $user->ID,
				'title'  => $avatar( $user, 18, '', $ring ) . '<span class="presence-bar-name">' . esc_html( $user->display_name ) . '</span>' . ( '' !== $context ? '<span class="presence-bar-screen">' . esc_html( $context ) . '</span>' : '' ),
				'href'   => $href,
				'meta'   => $href ? array() : array( 'tabindex' => 0 ),
			)
		);
	};

	// Each group is capped so a busy site cannot grow the dropdown past the screen.
	$max_rows = 10;

	// You are left out, as in Google Docs; My Account sits right beside the faces.
	if ( ! empty( $here ) ) {
		$add_section( 'here', __( 'On this page', 'presence-api' ) );

		foreach ( array_slice( $here, 0, $max_rows ) as $entry ) {
			$user = get_userdata( $entry->user_id );
			if ( $user ) {
				$row( 'here', $user );
			}
		}
	}

	$elsewhere = array_filter(
		$elsewhere,
		function ( $entry ) {
			return current_user_can( 'view_presence_location', $entry->user_id );
		}
	);

	if ( ! empty( $elsewhere ) ) {
		$add_section( 'elsewhere', __( 'Elsewhere', 'presence-api' ) );

		$shown = 0;

		foreach ( $elsewhere as $entry ) {
			if ( $shown >= $max_rows ) {
				break;
			}

			$user = get_userdata( $entry->user_id );
			if ( ! $user ) {
				continue;
			}
			$screen       = wp_presence_get_entry_screen( $entry );
			$entry_ps     = isset( $entry->data['post_status'] ) ? $entry->data['post_status'] : '';
			$screen_label = $screen ? WP_Presence_Widget_Whos_Online::get_rich_screen_label( $screen, $entry_ps ) : '';
			$screen_url   = $screen ? WP_Presence_Widget_Whos_Online::get_screen_url( $screen ) : false;

			// If user is editing a specific post, show the post title and link to it.
			if ( in_array( $screen, array( 'post', 'edit-post' ), true ) && isset( $user_editing_post[ (int) $entry->user_id ] ) ) {
				$post_id    = $user_editing_post[ (int) $entry->user_id ];
				$post_title = get_the_title( $post_id );
				if ( $post_title ) {
					$screen_label = $post_title;
				}
				$screen_url = get_edit_post_link( $post_id, 'raw' );
			}

			// If user is viewing a post on the frontend, show the post title and link to it.
			if ( 'front' === $screen && ! empty( $entry->data['title'] ) ) {
				$screen_label = $entry->data['title'];
				if ( ! empty( $entry->data['post_id'] ) ) {
					$screen_url = get_permalink( (int) $entry->data['post_id'] );
				}
			}

			$row( 'elsewhere', $user, $screen_label, $screen_url ? $screen_url : false );
			++$shown;
		}
	}

	// The footer links to the Users list.
	if ( ! current_user_can( 'list_users' ) ) {
		return;
	}

	$wp_admin_bar->add_group(
		array(
			'parent' => 'presence-online',
			'id'     => 'presence-actions',
			'meta'   => array( 'class' => 'ab-sub-secondary' ),
		)
	);
	$wp_admin_bar->add_node(
		array(
			'parent' => 'presence-actions',
			'id'     => 'presence-view-all',
			'title'  => __( 'View online users', 'presence-api' ),
			'href'   => wp_nonce_url( admin_url( 'users.php?presence_status=online' ), 'presence_online_filter' ),
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
		#wp-admin-bar-presence-online > .ab-item { display: flex !important; align-items: center; gap: 6px; cursor: default; }
		#wp-admin-bar-presence-online .presence-bar-avatars { display: inline-flex; align-items: center; gap: 9px; margin-inline: 3px; }
		#wp-admin-bar-presence-online .presence-bar-avatar { width: 20px !important; height: 20px !important; border-radius: 50%; outline: 2px solid; outline-offset: 1px; }
		#wp-admin-bar-presence-online .ab-sub-wrapper { width: 320px; }
		#wp-admin-bar-presence-online .ab-submenu .ab-item { display: flex !important; align-items: center; gap: 10px; }
		#wp-admin-bar-presence-online .ab-submenu .presence-bar-avatar { width: 18px !important; height: 18px !important; flex: none; outline-offset: 0; }
		#wp-admin-bar-presence-online .presence-bar-avatar-plain { outline: none; }
		#wp-admin-bar-presence-online .presence-bar-name, #wp-admin-bar-presence-online .presence-bar-screen { overflow: hidden; text-overflow: ellipsis; }
		#wp-admin-bar-presence-online .presence-bar-name { flex: 0 0 auto; max-width: 60%; }
		#wp-admin-bar-presence-online .presence-bar-screen { flex: 0 1 auto; min-width: 0; margin-inline-start: auto; padding-inline-start: 16px; color: #a7aaad; font-size: 11px; }
		#wp-admin-bar-presence-online .presence-bar-group-header > .ab-item { color: #a7aaad !important; cursor: default; }
		#wp-admin-bar-presence-online .presence-bar-group-label { font-size: 11px; font-weight: 500; text-transform: uppercase; color: inherit; }
		.admin-color-light #wpadminbar #wp-admin-bar-presence-online .presence-bar-count,
		.admin-color-light #wpadminbar #wp-admin-bar-presence-online .presence-bar-screen,
		.admin-color-light #wpadminbar #wp-admin-bar-presence-online .presence-bar-group-header > .ab-item { color: #50575e !important; }
	';

	// You wear the admin theme color, as in the block editor, so your ring never matches anyone on the page.
	$css .= '#wpadminbar:has(#wp-admin-bar-presence-here) #wp-admin-bar-my-account.with-avatar > .ab-item img { outline: 2px solid var(--wp-admin-theme-color, #2271b1); outline-offset: 1px; }';

	wp_register_style( 'presence-admin-bar', false, array(), WP_PRESENCE_VERSION );
	wp_enqueue_style( 'presence-admin-bar' );
	wp_add_inline_style( 'presence-admin-bar', $css );
}
