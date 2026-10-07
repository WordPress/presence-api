<?php
/**
 * Disables a synced pattern in the block editor while someone else is editing it, built on `wp.presence.usePresenceUsers()`.
 *
 * @package Presence_API
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueues the synced pattern lock for someone who can edit synced patterns.
 *
 * @since 0.17.0
 */
function wp_presence_enqueue_synced_pattern_notice() {
	$asset_file = WP_PRESENCE_PLUGIN_DIR . 'build/synced-patterns.asset.php';
	$post_type  = get_post_type_object( 'wp_block' );

	if ( ! $post_type || ! post_type_supports( 'wp_block', 'presence' ) || ! current_user_can( $post_type->cap->edit_posts ) || ! wp_script_is( 'wp-presence', 'registered' ) || ! file_exists( $asset_file ) ) {
		return;
	}

	$asset = require $asset_file;

	wp_enqueue_script(
		'wp-presence-synced-patterns',
		WP_PRESENCE_PLUGIN_URL . 'build/synced-patterns.js',
		array_merge( $asset['dependencies'], array( 'wp-presence' ) ),
		$asset['version'],
		true
	);
	wp_set_script_translations( 'wp-presence-synced-patterns', 'presence-api' );
}
