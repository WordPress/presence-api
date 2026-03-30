<?php
/**
 * Heartbeat presence handlers.
 *
 * @package Presence_API
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueues heartbeat and the presence ping script on all admin pages.
 *
 * @param string $hook_suffix The current admin page.
 */
function wp_presence_enqueue_heartbeat_ping() {
	if ( ! is_user_logged_in() || ! current_user_can( 'edit_posts' ) ) {
		return;
	}

	// On the front-end, only enqueue if the admin bar is showing.
	if ( ! is_admin() && ! is_admin_bar_showing() ) {
		return;
	}

	wp_enqueue_script( 'heartbeat' );

	// On frontend singular views, pass the current post context to the heartbeat ping.
	$front_context = '{}';
	if ( ! is_admin() && is_singular() ) {
		$queried = get_queried_object();
		if ( $queried instanceof WP_Post ) {
			$front_context = wp_json_encode(
				array(
					'postId'   => $queried->ID,
					'postType' => $queried->post_type,
					'title'    => get_the_title( $queried ),
				)
			);
		}
	}

	wp_add_inline_script(
		'heartbeat',
		sprintf(
			'(function($) {
			if (typeof wp === "undefined" || typeof wp.heartbeat === "undefined") { return; }
			var frontContext = %s;
			$(document).on("heartbeat-send", function(event, data) {
				var ping = { screen: window.pagenow || "front" };
				if (frontContext.postId) {
					ping.post_id = frontContext.postId;
					ping.post_type = frontContext.postType;
					ping.title = frontContext.title;
				}
				data["presence-ping"] = ping;
			});
		})(jQuery);',
			$front_context
		)
	);
}

/**
 * Enqueues a heartbeat ping for the block editor that reports which post is being edited.
 *
 * @param string $hook_suffix The current admin page.
 */
function wp_presence_enqueue_editor_ping( $hook_suffix ) {
	if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
		return;
	}

	$post = get_post();

	if ( ! $post || ! post_type_supports( $post->post_type, 'presence' ) ) {
		return;
	}

	wp_add_inline_script(
		'heartbeat',
		sprintf(
			'(function($) {
			if (typeof wp === "undefined" || typeof wp.heartbeat === "undefined") { return; }
			$(document).on("heartbeat-send", function(event, data) {
				data["presence-editor-ping"] = { post_id: %d };
			});
		})(jQuery);',
			$post->ID
		)
	);
}

/**
 * Handles the editor presence heartbeat and creates a presence entry for the post being edited.
 *
 * @param array  $response  The Heartbeat response.
 * @param array  $data      The $_POST data sent.
 * @param string $screen_id The screen ID.
 * @return array The Heartbeat response.
 */
function wp_presence_editor_heartbeat_received( $response, $data, $screen_id ) {
	if ( empty( $data['presence-editor-ping']['post_id'] ) ) {
		return $response;
	}

	$post_id = absint( $data['presence-editor-ping']['post_id'] );
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
		array(
			'action' => 'editing',
			'screen' => $screen_id,
		),
		$user_id
	);

	return $response;
}
