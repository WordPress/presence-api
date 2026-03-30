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
 */
function wp_presence_register_post_list_columns() {
	if ( ! current_user_can( 'edit_posts' ) ) {
		return;
	}

	$post_types = get_post_types( array( 'public' => true ) );

	foreach ( $post_types as $post_type ) {
		if ( ! post_type_supports( $post_type, 'presence' ) ) {
			continue;
		}

		add_filter( "manage_{$post_type}_posts_columns", 'wp_presence_add_editors_column' );
		add_action( "manage_{$post_type}_posts_custom_column", 'wp_presence_render_editors_column', 10, 2 );
	}

	add_action( 'admin_head-edit.php', 'wp_presence_editors_column_css' );
}

/**
 * Adds the "Editors" column to the post list table.
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
 * @param string $column_name The column name.
 * @param int    $post_id     The post ID.
 */
function wp_presence_render_editors_column( $column_name, $post_id ) {
	if ( 'presence_editors' !== $column_name ) {
		return;
	}

	static $presence_map = null;

	if ( null === $presence_map ) {
		$presence_map = array();
		$entries      = wp_get_presence_by_room_prefix( 'postType/' );

		foreach ( $entries as $entry ) {
			$room_parts = explode( ':', $entry->room, 2 );

			if ( count( $room_parts ) < 2 ) {
				continue;
			}

			$pid = (int) $room_parts[1];

			if ( ! isset( $presence_map[ $pid ] ) ) {
				$presence_map[ $pid ] = array();
			}

			// Deduplicate by user_id.
			$presence_map[ $pid ][ $entry->user_id ] = $entry;
		}

		// Prime user cache for all editors in one query.
		$all_user_ids = array();
		foreach ( $presence_map as $editors ) {
			$all_user_ids = array_merge( $all_user_ids, array_keys( $editors ) );
		}
		cache_users( array_unique( $all_user_ids ) );
	}

	if ( empty( $presence_map[ $post_id ] ) ) {
		return;
	}

	$editors = $presence_map[ $post_id ];
	$count   = count( $editors );
	$index   = 0;

	echo '<div class="presence-editors-stack">';

	foreach ( $editors as $entry ) {
		$user = get_userdata( $entry->user_id );

		if ( ! $user ) {
			continue;
		}

		$z      = $count - $index;
		$avatar = get_avatar( $user->ID, 24, '', $user->display_name );
		$avatar = str_replace( '<img ', '<img style="z-index:' . $z . '" title="' . esc_attr( $user->display_name ) . '" ', $avatar );
		echo wp_kses_post( $avatar );
		++$index;
	}

	echo '</div>';
}

/**
 * Outputs CSS for the editors column on the post list screen.
 */
function wp_presence_editors_column_css() {
	?>
	<style>
		.column-presence_editors { width: 80px; }
		.presence-editors-stack { display: flex; align-items: center; }
		.presence-editors-stack img {
			border-radius: 9999px;
			margin-inline-start: -8px;
			box-shadow: 0 0 0 2px #fff;
			position: relative;
		}
		.presence-editors-stack img:first-child { margin-inline-start: 0; }
	</style>
	<?php
}
