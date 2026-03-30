<?php
/**
 * Post-lock bridge: writes presence entries alongside post lock heartbeats.
 *
 * This bridge is transitional. It creates presence entries alongside the
 * existing _edit_lock postmeta so both systems coexist. The intent is for
 * the block editor (Gutenberg) to consume presence data directly in the
 * future — enabling real-time awareness (cursors, selections, who's editing
 * which block) rather than the current blunt lock/takeover model.
 *
 * @package Presence_API
 * @since 7.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bridges post-lock heartbeats into presence entries.
 *
 * Writes a presence entry alongside the existing _edit_lock postmeta
 * whenever a post lock is refreshed via Heartbeat.
 *
 * @since 7.1.0
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
		'lock-' . $user_id,
		array(
			'action' => 'editing',
			'screen' => $screen_id,
		),
		$user_id
	);

	return $response;
}
