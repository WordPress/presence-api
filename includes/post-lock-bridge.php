<?php
/**
 * Post-lock bridge: keeps post locks in the presence table.
 *
 * `_edit_lock` lives in a reserved row in the post's room rather than in post
 * meta, so refreshing it no longer bumps the posts `last_changed` cache key.
 * See "Relationship to the block editor" in README.md for how this
 * relates to the block editor's own awareness data (cursors, selections,
 * who's editing which block), which reaches this table through the editor's
 * collaboration storage plugin rather than through this bridge.
 *
 * @package Presence_API
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bridges post-lock heartbeats into presence entries.
 *
 * Marks the user's entry in the post room as holding the lock whenever a post
 * lock is refreshed via Heartbeat.
 *
 * Fallback path, for a client that refreshes the core lock without sending
 * presence-editor-ping.
 *
 * @param array  $response  The Heartbeat response.
 * @param array  $data      The $_POST data sent.
 * @param string $screen_id The screen ID.
 * Nonce verification is handled by WordPress in wp_ajax_heartbeat().
 *
 * @return array The Heartbeat response.
 */
function wp_presence_bridge_post_lock( $response, $data, $screen_id ) {
	if ( empty( $data['wp-refresh-post-lock']['post_id'] ) ) {
		return $response;
	}

	$post_id = absint( $data['wp-refresh-post-lock']['post_id'] );

	// The editor handler already wrote this entry from this same payload.
	if ( ! empty( $data['presence-editor-ping']['post_id'] )
		&& absint( $data['presence-editor-ping']['post_id'] ) === $post_id ) {
		return $response;
	}

	$user_id = get_current_user_id();

	if ( ! $user_id || ! current_user_can( 'edit_post', $post_id ) ) {
		return $response;
	}

	$room = wp_presence_post_room( $post_id );

	if ( ! $room ) {
		return $response;
	}

	wp_set_presence(
		$room,
		'editor-' . $user_id,
		wp_presence_editor_state( $screen_id, true ),
		$user_id
	);

	return $response;
}

/**
 * The reserved client_id holding a post's `_edit_lock`.
 *
 * One per room, so the unique key keeps a single lock per post as meta did.
 *
 * @since 0.8.1
 *
 * @access private
 * @return string The reserved client_id.
 */
function wp_presence_post_lock_client_id() {
	return WP_PRESENCE_RESERVED_PREFIX . 'lock';
}

/**
 * Returns the room a post's lock is kept in, or false to leave it in meta.
 *
 * @since 0.8.1
 *
 * @access private
 * @param int    $post_id  The post ID.
 * @param string $meta_key The meta key being read or written.
 * @return string|false The room, or false when the lock stays in post meta.
 */
function wp_presence_post_lock_room( $post_id, $meta_key ) {
	if ( '_edit_lock' !== $meta_key || ! $post_id ) {
		return false;
	}

	// Recording off means wp_set_presence() writes nothing, so meta has to keep it.
	if ( ! wp_presence_recording_enabled() || ! wp_presence_has_table() ) {
		return false;
	}

	return wp_presence_post_room( $post_id );
}

/**
 * Returns a post's lock in the `time:user_id` shape core stores.
 *
 * @since 0.8.1
 *
 * @access private
 * @param string $room The post's room.
 * @return string The lock, or an empty string when there is none.
 */
function wp_presence_post_lock_value( $room ) {
	$rows = wp_presence_room_rows( $room, null, wp_presence_post_lock_client_id() );

	if ( ! $rows ) {
		return '';
	}

	return strtotime( $rows[0]->date_gmt . ' UTC' ) . ':' . (int) $rows[0]->user_id;
}

/**
 * Reads `_edit_lock` from the post's lock row.
 *
 * @since 0.8.1
 *
 * @param mixed  $check    The value to short-circuit with, null to read meta.
 * @param int    $post_id  The post ID.
 * @param string $meta_key The meta key.
 * @param bool   $single   Whether a single value was requested.
 * @return mixed The lock, or $check when the lock stays in post meta.
 */
function wp_presence_get_post_lock( $check, $post_id, $meta_key, $single ) {
	$room = wp_presence_post_lock_room( $post_id, $meta_key );

	if ( ! $room ) {
		return $check;
	}

	$lock = wp_presence_post_lock_value( $room );

	if ( '' === $lock ) {
		return $single ? '' : array();
	}

	return array( $lock );
}

/**
 * Writes `_edit_lock` to the post's lock row instead of post meta.
 *
 * The row is dated at the lock's own time and expires when core stops honouring
 * it, so a released lock, which core backdates, ages out on the same clock.
 *
 * @since 0.8.1
 *
 * @param null|bool $check      The value to short-circuit with, null to write meta.
 * @param int       $post_id    The post ID.
 * @param string    $meta_key   The meta key.
 * @param mixed     $meta_value The new lock, `time:user_id`.
 * @param mixed     $prev_value The lock to replace, or empty to replace any.
 * @return null|bool Whether the lock was written, or $check when it stays in post meta.
 */
function wp_presence_update_post_lock( $check, $post_id, $meta_key, $meta_value, $prev_value ) {
	$room = wp_presence_post_lock_room( $post_id, $meta_key );

	if ( ! $room ) {
		return $check;
	}

	$parts   = explode( ':', (string) $meta_value );
	$time    = absint( $parts[0] );
	$user_id = isset( $parts[1] ) ? absint( $parts[1] ) : 0;

	// A lock without a user falls back to _edit_last, which only meta can pair it with.
	if ( ! $time || ! $user_id ) {
		return $check;
	}

	// Same as meta: a stale $prev_value, as when someone else took over, changes nothing.
	if ( '' !== (string) $prev_value ) {
		$current = wp_presence_post_lock_value( $room );

		if ( '' !== $current && (string) $prev_value !== $current ) {
			return false;
		}
	}

	/** This filter is documented in wp-admin/includes/ajax-actions.php */
	$window = (int) apply_filters( 'wp_check_post_lock_window', 150 );

	return (bool) wp_set_presence(
		$room,
		wp_presence_post_lock_client_id(),
		array(),
		$user_id,
		gmdate( 'Y-m-d H:i:s', $time ),
		max( 1, $window )
	);
}

/**
 * Clears a post's lock row when `_edit_lock` is deleted.
 *
 * @since 0.8.1
 *
 * @param null|bool $check      The value to short-circuit with, null to delete meta.
 * @param int       $post_id    The post ID.
 * @param string    $meta_key   The meta key.
 * @param mixed     $meta_value The value to match, unused.
 * @param bool      $delete_all Whether to delete the key from every post.
 * @return null|bool Whether the lock was cleared, or $check when it stays in post meta.
 */
function wp_presence_delete_post_lock( $check, $post_id, $meta_key, $meta_value, $delete_all ) {
	// A delete across every post carries no post to find a room for.
	if ( $delete_all ) {
		return $check;
	}

	$room = wp_presence_post_lock_room( $post_id, $meta_key );

	if ( ! $room ) {
		return $check;
	}

	return wp_remove_presence( $room, wp_presence_post_lock_client_id() );
}
