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

	$sort_by_name = function ( $a, $b ) {
		$user_a = get_userdata( $a->user_id );
		$user_b = get_userdata( $b->user_id );
		$name_a = $user_a ? $user_a->display_name : '';
		$name_b = $user_b ? $user_b->display_name : '';
		return strcasecmp( $name_a, $name_b );
	};
	usort( $here, $sort_by_name );
	usort( $elsewhere, $sort_by_name );

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

	// Hide titles and edit links for posts the current user cannot edit.
	$user_editing_post = array();
	foreach ( $editing as $user_id => $post ) {
		if ( wp_can_access_presence_room( $post['room'], $current_uid ) ) {
			$user_editing_post[ $user_id ] = $post['post_id'];
		}
	}

	// Others on this page only, since My Account shows the current user. Five whole faces fit.
	$here_ids  = array_unique( array_map( 'intval', wp_list_pluck( $here, 'user_id' ) ) );
	$stack_ids = array_slice( $here_ids, 0, 5 );

	$colors = array();
	foreach ( $entries as $entry ) {
		$colors[ (int) $entry->user_id ] = wp_presence_entry_color( $entry );
	}

	// Seven colors cannot go round a busy site, so people on this page trade a clash for a free one.
	$colors = wp_presence_spread_colors( array_intersect_key( $colors, array_flip( $here_ids ) ) ) + $colors;

	// Only people on this page wear their color; it pairs them with what they do on the shared screen.
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

	// The current user is left out, since My Account already shows them.
	if ( ! empty( $here ) ) {
		$add_section( 'here', __( 'On this page', 'presence-api' ) );

		foreach ( array_slice( $here, 0, $max_rows ) as $entry ) {
			$user = get_userdata( $entry->user_id );
			if ( $user ) {
				$row( 'here', $user );
			}
		}
	}

	$shown = 0;

	if ( ! empty( $elsewhere ) ) {
		$add_section( 'elsewhere', __( 'Elsewhere', 'presence-api' ) );

		foreach ( $elsewhere as $entry ) {
			if ( $shown >= $max_rows ) {
				break;
			}

			$user = get_userdata( $entry->user_id );
			if ( ! $user ) {
				continue;
			}
			// Everyone sees the name; the location needs view_presence_location.
			$screen       = wp_presence_get_entry_screen( $entry );
			$entry_ps     = isset( $entry->data['post_status'] ) ? $entry->data['post_status'] : '';
			$screen_label = $screen ? wp_presence_get_rich_screen_label( $screen, $entry_ps ) : '';
			$screen_url   = $screen ? wp_presence_get_screen_url( $screen ) : false;

			if ( in_array( $screen, array( 'post', 'edit-post' ), true ) && isset( $user_editing_post[ (int) $entry->user_id ] ) ) {
				$post_id    = $user_editing_post[ (int) $entry->user_id ];
				$post_title = get_the_title( $post_id );
				if ( $post_title ) {
					$screen_label = $post_title;
				}
				$screen_url = get_edit_post_link( $post_id, 'raw' );
			}

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

	// Counts who the caps leave out, so the rows never read as everyone online.
	$more = max( 0, count( $here ) - $max_rows ) + max( 0, count( $elsewhere ) - $shown );
	if ( $more ) {
		$wp_admin_bar->add_node(
			array(
				'parent' => empty( $elsewhere ) ? 'presence-here' : 'presence-elsewhere',
				'id'     => 'presence-more',
				/* translators: %d: Number of online users the menu leaves out. */
				'title'  => sprintf( _n( '%d more', '%d more', $more, 'presence-api' ), $more ),
				'href'   => false,
				'meta'   => array( 'class' => 'presence-bar-more' ),
			)
		);
	}

	// Only users who can list users get the footer link.
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

	$response['presence-admin-bar'] = wp_presence_admin_bar_node_markup( sanitize_text_field( $data['presence-ping']['screen'] ) );

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
		#wp-admin-bar-presence-online .presence-bar-group-header > .ab-item, #wp-admin-bar-presence-online .presence-bar-more > .ab-item { color: #a7aaad !important; cursor: default; }
		#wp-admin-bar-presence-online .presence-bar-group-label { font-size: 11px; font-weight: 500; color: inherit; }
		.admin-color-light #wpadminbar #wp-admin-bar-presence-online .presence-bar-count,
		.admin-color-light #wpadminbar #wp-admin-bar-presence-online .presence-bar-screen,
		.admin-color-light #wpadminbar #wp-admin-bar-presence-online .presence-bar-group-header > .ab-item,
		.admin-color-light #wpadminbar #wp-admin-bar-presence-online .presence-bar-more > .ab-item { color: #50575e !important; }
	';

	// The current user wears the admin theme color, as in the block editor, so it never matches a ring on the page.
	$css .= '#wpadminbar:has(#wp-admin-bar-presence-here) #wp-admin-bar-my-account.with-avatar > .ab-item img { outline: 2px solid var(--wp-admin-theme-color, #2271b1); outline-offset: 1px; }';

	wp_register_style( 'presence-admin-bar', false, array(), WP_PRESENCE_VERSION );
	wp_enqueue_style( 'presence-admin-bar' );
	wp_add_inline_style( 'presence-admin-bar', $css );
}

/**
 * Returns a map of pagenow slugs to translatable screen labels.
 *
 * @since 0.9.0
 *
 * @return string[] Screen labels keyed by pagenow slug.
 */
function wp_presence_get_screen_labels() {
	return array(
		'dashboard'          => __( 'Dashboard', 'presence-api' ),
		'edit'               => __( 'Posts', 'presence-api' ),
		'post'               => __( 'Editing post', 'presence-api' ),
		'edit-post'          => __( 'Editing post', 'presence-api' ),
		'post-new'           => __( 'Writing post', 'presence-api' ),
		'edit-page'          => __( 'Pages', 'presence-api' ),
		'page'               => __( 'Editing page', 'presence-api' ),
		'upload'             => __( 'Media', 'presence-api' ),
		'media'              => __( 'Media', 'presence-api' ),
		'edit-comments'      => __( 'Comments', 'presence-api' ),
		'comment'            => __( 'Comments', 'presence-api' ),
		'themes'             => __( 'Themes', 'presence-api' ),
		'widgets'            => __( 'Widgets', 'presence-api' ),
		'nav-menus'          => __( 'Menus', 'presence-api' ),
		'plugins'            => __( 'Plugins', 'presence-api' ),
		'users'              => __( 'Users', 'presence-api' ),
		'profile'            => __( 'Profile', 'presence-api' ),
		'user-edit'          => __( 'Users', 'presence-api' ),
		'tools'              => __( 'Tools', 'presence-api' ),
		'import'             => __( 'Import', 'presence-api' ),
		'export'             => __( 'Export', 'presence-api' ),
		'options-general'    => __( 'Settings', 'presence-api' ),
		'options-writing'    => __( 'Settings', 'presence-api' ),
		'options-reading'    => __( 'Settings', 'presence-api' ),
		'options-discussion' => __( 'Settings', 'presence-api' ),
		'options-media'      => __( 'Settings', 'presence-api' ),
		'options-permalink'  => __( 'Settings', 'presence-api' ),
		'front'              => __( 'Viewing site', 'presence-api' ),
		'login'              => __( 'Logging in', 'presence-api' ),
	);
}

/**
 * Returns the admin URL for a pagenow screen slug, if linkable.
 *
 * @since 0.9.0
 *
 * @param string $screen The pagenow slug.
 * @return string|false The admin URL, or false if not linkable.
 */
function wp_presence_get_screen_url( $screen ) {
	$map = array(
		'dashboard'          => '',
		'edit'               => 'edit.php',
		'post'               => 'edit.php',
		'edit-post'          => 'edit.php',
		'post-new'           => 'post-new.php',
		'edit-page'          => 'edit.php?post_type=page',
		'page'               => 'edit.php?post_type=page',
		'upload'             => 'upload.php',
		'media'              => 'upload.php',
		'edit-comments'      => 'edit-comments.php',
		'comment'            => 'edit-comments.php',
		'themes'             => 'themes.php',
		'widgets'            => 'widgets.php',
		'nav-menus'          => 'nav-menus.php',
		'plugins'            => 'plugins.php',
		'users'              => 'users.php',
		'profile'            => 'profile.php',
		'user-edit'          => 'users.php',
		'tools'              => 'tools.php',
		'import'             => 'import.php',
		'export'             => 'export.php',
		'options-general'    => 'options-general.php',
		'options-writing'    => 'options-writing.php',
		'options-reading'    => 'options-reading.php',
		'options-discussion' => 'options-discussion.php',
		'options-media'      => 'options-media.php',
		'options-permalink'  => 'options-permalink.php',
	);

	if ( isset( $map[ $screen ] ) ) {
		return admin_url( $map[ $screen ] );
	}

	return false;
}

/**
 * Returns a context-aware screen label using post status when available.
 *
 * @since 0.9.0
 *
 * @param string $screen      The pagenow slug.
 * @param string $post_status Optional. The post status (draft, publish, etc.). Default empty.
 * @return string The friendly label.
 */
function wp_presence_get_rich_screen_label( $screen, $post_status = '' ) {
	if ( $post_status && in_array( $screen, array( 'post', 'edit-post', 'page' ), true ) ) {
		$type = in_array( $screen, array( 'page' ), true ) ? 'page' : 'post';
		switch ( $post_status ) {
			case 'draft':
			case 'auto-draft':
				return 'page' === $type ? __( 'Drafting page', 'presence-api' ) : __( 'Drafting post', 'presence-api' );
			case 'pending':
				return 'page' === $type ? __( 'Pending page', 'presence-api' ) : __( 'Pending post', 'presence-api' );
			case 'private':
				return 'page' === $type ? __( 'Editing private page', 'presence-api' ) : __( 'Editing private post', 'presence-api' );
			case 'future':
				return 'page' === $type ? __( 'Editing scheduled page', 'presence-api' ) : __( 'Editing scheduled post', 'presence-api' );
			default:
				return 'page' === $type ? __( 'Editing page', 'presence-api' ) : __( 'Editing post', 'presence-api' );
		}
	}

	return wp_presence_get_screen_label( $screen );
}

/**
 * Returns a human-readable label for a pagenow screen slug.
 *
 * @since 0.9.0
 *
 * @param string $screen The pagenow slug.
 * @return string The friendly label.
 */
function wp_presence_get_screen_label( $screen ) {
	$labels = wp_presence_get_screen_labels();
	if ( isset( $labels[ $screen ] ) ) {
		return $labels[ $screen ];
	}

	// Fallback: title-case and strip hyphens.
	return ucwords( str_replace( array( '-', '_' ), ' ', $screen ) );
}
