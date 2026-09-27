<?php
/**
 * Post list "Editors" column for post types with presence support.
 *
 * @package Presence_API
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the "Editors" column for post types with presence support.
 *
 * @since 0.1.1
 */
function wp_presence_register_post_list_columns() {
	if ( ! wp_can_access_presence_room( wp_presence_admin_room() ) ) {
		return;
	}

	$post_types = get_post_types( array( 'show_ui' => true ) );

	foreach ( $post_types as $post_type ) {
		if ( ! post_type_supports( $post_type, 'presence' ) ) {
			continue;
		}

		add_filter( "manage_{$post_type}_posts_columns", 'wp_presence_add_editors_column' );
		add_action( "manage_{$post_type}_posts_custom_column", 'wp_presence_render_editors_column', 10, 2 );
	}

	add_action( 'admin_enqueue_scripts', 'wp_presence_editors_column_css' );
}

/**
 * Adds the "Editors" column to the post list table.
 *
 * @since 0.1.1
 *
 * @param array $columns Existing columns.
 * @return array Modified columns.
 */
function wp_presence_add_editors_column( $columns ) {
	// Insert before the "date" column.
	$new_columns = array();

	foreach ( $columns as $key => $label ) {
		if ( 'date' === $key ) {
			$new_columns['presence_editors'] = __( 'Editors', 'presence-api' );
		}
		$new_columns[ $key ] = $label;
	}

	// If no date column, append.
	if ( ! isset( $new_columns['presence_editors'] ) ) {
		$new_columns['presence_editors'] = __( 'Editors', 'presence-api' );
	}

	return $new_columns;
}

/**
 * Renders the "Editors" column content for a post.
 *
 * Queries presence data once and caches it for the entire page load.
 *
 * @since 0.1.1
 *
 * @param string $column_name The column name.
 * @param int    $post_id     The post ID.
 */
function wp_presence_render_editors_column( $column_name, $post_id ) {
	if ( 'presence_editors' !== $column_name || ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	static $presence_map = null;

	if ( null === $presence_map ) {
		$presence_map = wp_presence_post_list_editors();
	}

	echo wp_presence_editors_stack( $presence_map[ $post_id ] ?? array() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in wp_presence_editors_stack().
}

/**
 * Returns the presence entries on each post, keyed by post ID then user ID.
 *
 * @since 0.10.0
 * @access private
 *
 * @return array<int, array<int, object>> Entries by post and user.
 */
function wp_presence_post_list_editors() {
	$presence_map = array();

	foreach ( wp_get_presence_by_room_prefix( 'postType/' ) as $entry ) {
		$parsed = wp_presence_parse_room( $entry->room );
		if ( ! $parsed ) {
			continue;
		}

		// Deduplicate by user_id.
		$presence_map[ $parsed['post_id'] ][ $entry->user_id ] = $entry;
	}

	// Prime user cache for all editors in one query.
	$all_user_ids = array();
	foreach ( $presence_map as $editors ) {
		$all_user_ids = array_merge( $all_user_ids, array_keys( $editors ) );
	}
	cache_users( array_unique( $all_user_ids ) );

	return $presence_map;
}

/**
 * Returns the avatar stack for a post's editors.
 *
 * @since 0.10.0
 * @access private
 *
 * @param object[] $editors Presence entries keyed by user ID.
 * @return string The stack markup, or an empty string when no one is there.
 */
function wp_presence_editors_stack( $editors ) {
	if ( ! $editors ) {
		return '';
	}

	$count = count( $editors );
	$index = 0;
	$html  = '<div class="presence-editors-stack">';

	foreach ( $editors as $entry ) {
		$user = get_userdata( $entry->user_id );

		if ( ! $user ) {
			continue;
		}

		$z      = $count - $index;
		$avatar = get_avatar( $user->ID, 24, '', $user->display_name );
		$avatar = str_replace( '<img ', '<img style="z-index:' . $z . '" title="' . esc_attr( $user->display_name ) . '" ', $avatar );
		$html  .= wp_kses_post( $avatar );
		++$index;
	}

	return $html . '</div>';
}

/**
 * Sends a fresh Editors cell for each post row core checks locks on.
 *
 * @since 0.10.0
 *
 * @param array $response The Heartbeat response.
 * @param array $data     The Heartbeat data.
 * @return array The Heartbeat response.
 */
function wp_presence_editors_column_heartbeat_received( $response, $data ) {
	if ( empty( $data['wp-check-locked-posts'] ) || ! is_array( $data['wp-check-locked-posts'] ) ) {
		return $response;
	}

	$post_ids = array();
	foreach ( $data['wp-check-locked-posts'] as $key ) {
		if ( is_string( $key ) && 0 === strpos( $key, 'post-' ) ) {
			$post_ids[] = absint( substr( $key, 5 ) );
		}
	}

	_prime_post_caches( $post_ids, false, false );

	$editors = wp_presence_post_list_editors();
	$cells   = array();

	foreach ( $post_ids as $post_id ) {
		$post = get_post( $post_id );

		if ( $post && post_type_supports( $post->post_type, 'presence' ) && current_user_can( 'edit_post', $post_id ) ) {
			$cells[ 'post-' . $post_id ] = wp_presence_editors_stack( $editors[ $post_id ] ?? array() );
		}
	}

	if ( $cells ) {
		$response['presence-fragments']['editors'] = $cells;
	}

	return $response;
}

/**
 * Enqueues CSS for the editors column on the post list screen.
 *
 * @since 0.1.1
 *
 * @param string $hook_suffix The current admin page.
 */
function wp_presence_editors_column_css( $hook_suffix ) {
	if ( 'edit.php' !== $hook_suffix ) {
		return;
	}

	$css = '
		.column-presence_editors { width: 80px; }
		.presence-editors-stack { display: flex; align-items: center; }
		.presence-editors-stack img {
			border-radius: 9999px;
			margin-inline-start: -8px;
			box-shadow: 0 0 0 2px #fff;
			position: relative;
		}
		.presence-editors-stack img:first-child { margin-inline-start: 0; }
	';

	wp_register_style( 'presence-post-list', false, array(), WP_PRESENCE_VERSION );
	wp_enqueue_style( 'presence-post-list' );
	wp_add_inline_style( 'presence-post-list', $css );
}
