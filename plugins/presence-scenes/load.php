<?php
/**
 * Plugin Name: Presence Scenes
 * Description: Plays real users through probable situations from WP-CLI, using only the Presence API's public functions.
 * Version: 0.1.1
 * Requires at least: 7.0
 * Requires PHP: 7.4
 * Requires Plugins: presence-api
 * Author: WordPress Core Team
 * Author URI: https://make.wordpress.org/core/
 * Text Domain: presence-scenes
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package Presence_Scenes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'plugins_loaded',
	function () {
		if ( ! function_exists( 'wp_get_presence' ) ) {
			return;
		}

		require_once __DIR__ . '/scenes.php';
		require_once __DIR__ . '/class-wp-presence-scene-actor.php';
		require_once __DIR__ . '/debugger.php';

		add_action( 'wp_presence_scene_sweep', 'wp_presence_scene_sweep' );
		add_filter( 'wp_authenticate_user', 'wp_presence_scene_authenticate' );

		add_filter( 'wp_presence_debugger_indicators', 'wp_presence_scene_debugger_indicators' );
		add_action( 'wp_presence_debugger_menu', 'wp_presence_scene_debugger_menu' );
		foreach ( array( 'admin_enqueue_scripts', 'wp_enqueue_scripts' ) as $hook ) {
			add_action( $hook, 'wp_presence_scene_debugger_assets', (int) has_action( $hook, 'wp_presence_debugger_admin_bar_assets' ) + 1 );
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once __DIR__ . '/class-wp-presence-scene-cli-command.php';
			/** Plays the bundled scenes, which have no other way in. */
			WP_CLI::add_command( 'presence scene', 'WP_Presence_Scene_CLI_Command' );
		}
	}
);

register_deactivation_hook(
	__FILE__,
	function ( $network_wide ) {
		if ( ! function_exists( 'wp_presence_scene_sweep' ) ) {
			return;
		}

		$site_ids = $network_wide ? get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		) : array( 0 );
		foreach ( $site_ids as $site_id ) {
			if ( $site_id ) {
				switch_to_blog( $site_id );
			}

			wp_presence_scene_sweep( true );

			if ( $site_id ) {
				restore_current_blog();
			}
		}
	}
);
