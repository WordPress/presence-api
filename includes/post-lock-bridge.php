<?php
/**
 * Post-lock bridge: writes presence entries alongside post lock heartbeats.
 *
 * This bridge is transitional. It records the lock on the editing user's
 * presence entry alongside the existing _edit_lock postmeta so both systems
 * coexist. The intent is for the block editor (Gutenberg) to consume presence
 * data directly in the future — enabling real-time awareness (cursors,
 * selections, who's editing which block) rather than the current blunt
 * lock/takeover model.
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
 * lock is refreshed via Heartbeat, alongside the existing _edit_lock postmeta.
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
