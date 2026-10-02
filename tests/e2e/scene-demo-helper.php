<?php
/**
 * Plays a Presence Scene on the viewer's Heartbeat.
 *
 * Loaded as a must-use plugin by the Playground scenes blueprint, which has
 * no WP-CLI loop to drive a run. This file ships only with that blueprint; it
 * is not part of either plugin.
 *
 * @package Presence_API_Demo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Starts the scene named in `?presence_scene=`, then drops the argument so a reload does not start it again.
 */
function presence_demo_start_scene() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only the blueprint's landing page sets it.
	if ( ! isset( $_GET['presence_scene'] ) || ! function_exists( 'wp_presence_scene_start' ) || ! current_user_can( 'manage_options' ) ) {
		return;
	}

	wp_presence_scene_start( sanitize_key( wp_unslash( $_GET['presence_scene'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	wp_safe_redirect( remove_query_arg( 'presence_scene' ) );
	exit;
}
add_action( 'admin_init', 'presence_demo_start_scene' );

/**
 * Plays the steps that are due, as `wp presence scene run` does once a second, and strikes the scene after its last one.
 *
 * @param array $response Heartbeat response.
 * @return array The response.
 */
function presence_demo_play_scene( $response ) {
	if ( ! function_exists( 'wp_presence_scene_play' ) || ! is_array( get_option( 'wp_presence_scene' ) ) ) {
		return $response;
	}

	wp_presence_scene_locked(
		function ( $run ) {
			if ( ! is_array( $run ) ) {
				return;
			}
			$run = wp_presence_scene_play( $run );
			if ( count( $run['done'] ) === count( $run['scene']['steps'] ) ) {
				wp_presence_scene_strike( $run );
			}
		}
	);

	return $response;
}
add_filter( 'heartbeat_received', 'presence_demo_play_scene', 5 );
