<?php
/**
 * Presence API: the avatar stack shared by the presence UI.
 *
 * @package Presence_API
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Returns the Gravatar size to request for an avatar displayed at a given size.
 *
 * @access private
 *
 * @since 0.5.0
 *
 * @param int $display_size The avatar's displayed size in pixels.
 * @return int The size to request, for a sharp image on a 2x display.
 */
function wp_presence_get_avatar_fetch_size( $display_size ) {
	return (int) $display_size * 2;
}

/**
 * Enqueues the shared avatar-stack stylesheet.
 *
 * @access private
 */
function wp_presence_enqueue_avatar_stack_style() {
	wp_enqueue_style(
		'wp-presence-avatar-stack',
		WP_PRESENCE_PLUGIN_URL . 'assets/css/avatar-stack.css',
		array(),
		WP_PRESENCE_VERSION
	);
}

/**
 * Enqueues the shared avatar-stack script.
 *
 * Only the two widgets that repaint over Heartbeat need it; a stack rendered
 * once per page load is served by the PHP renderer alone.
 *
 * @access private
 */
function wp_presence_enqueue_avatar_stack_script() {
	wp_enqueue_script(
		'wp-presence-avatar-stack',
		WP_PRESENCE_PLUGIN_URL . 'assets/js/avatar-stack.js',
		array(),
		WP_PRESENCE_VERSION,
		true
	);
}

/**
 * Renders a small avatar stack for a list of users.
 *
 * Shared across every surface that shows an overlapping avatar stack (the
 * dashboard widget's overflow indicator, the network Sites list column, the
 * network dashboard widget) so they all render the stack identically, and
 * mirrored by wpPresenceBuildAvatarStack() in assets/js/avatar-stack.js for
 * the widgets that repaint over Heartbeat.
 *
 * assets/css/avatar-stack.css sizes the avatars; the attributes below only
 * reserve the space until it loads.
 *
 * @access private
 * @param array $users Users, each with 'avatar_url' and 'display_name'.
 * @param int   $max   Optional. Maximum avatars to show. Default 4.
 * @return string HTML markup.
 */
function wp_presence_render_avatar_stack( $users, $max = 4 ) {
	$shown = array();

	// get_avatar_url() returns false with the Show Avatars setting off.
	foreach ( array_slice( $users, 0, $max ) as $user ) {
		if ( ! empty( $user['avatar_url'] ) ) {
			$shown[] = $user;
		}
	}

	$html = '<span class="presence-avatar-stack">';

	foreach ( $shown as $index => $user ) {
		$z     = count( $shown ) - $index;
		$html .= '<img src="' . esc_url( $user['avatar_url'] ) . '" width="20" height="20" style="z-index:' . (int) $z . '" alt="' . esc_attr( $user['display_name'] ) . '" />';
	}

	$html .= '</span>';

	return $html;
}
