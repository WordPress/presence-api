<?php
/**
 * Presence Scenes uninstall handler.
 *
 * @package Presence_Scenes
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Needs only core, so actors are deleted even while Presence API is inactive.
require_once __DIR__ . '/scenes.php';

foreach ( is_multisite() ? get_sites(
	array(
		'fields' => 'ids',
		'number' => 0,
	)
) : array( 0 ) as $site_id ) {
	if ( $site_id ) {
		switch_to_blog( $site_id );
	}

	wp_presence_scene_delete_users( wp_presence_scene_actors( true ) );
	delete_option( 'wp_presence_scene' );
	delete_option( 'wp_presence_scene_runs' );
	delete_option( 'wp_presence_scene.lock' );
	wp_clear_scheduled_hook( 'wp_presence_scene_sweep' );

	if ( $site_id ) {
		restore_current_blog();
	}
}
