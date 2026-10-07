<?php
/**
 * Block editor "Editors" panel, built on `wp.presence.usePresenceUsers()`.
 *
 * @package Presence_API
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueues the Editors panel for a post whose room the current user can read.
 *
 * @since 0.17.0
 */
function wp_presence_enqueue_editors_panel() {
	$room       = wp_presence_post_room( get_post() );
	$asset_file = WP_PRESENCE_PLUGIN_DIR . 'build/editors-panel.asset.php';

	if ( ! $room || ! wp_can_access_presence_room( $room ) || ! wp_script_is( 'wp-presence', 'registered' ) || ! file_exists( $asset_file ) ) {
		return;
	}

	$asset = require $asset_file;

	wp_enqueue_script(
		'wp-presence-editors-panel',
		WP_PRESENCE_PLUGIN_URL . 'build/editors-panel.js',
		array_merge( $asset['dependencies'], array( 'wp-presence' ) ),
		$asset['version'],
		true
	);
	wp_add_inline_script(
		'wp-presence-editors-panel',
		sprintf( 'window.wpPresenceEditorsPanel = %s;', wp_json_encode( array( 'room' => $room ), JSON_HEX_TAG | JSON_UNESCAPED_SLASHES ) ),
		'before'
	);
	wp_set_script_translations( 'wp-presence-editors-panel', 'presence-api' );
}
