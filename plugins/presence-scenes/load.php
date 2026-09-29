<?php
/**
 * Plugin Name: Presence Scenes
 * Description: Plays real users through probable situations from WP-CLI, using only the Presence API's public functions.
 * Version: 0.1.0
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

		add_action( 'wp_presence_scene_sweep', 'wp_presence_scene_sweep' );
		add_filter( 'wp_authenticate_user', 'wp_presence_scene_authenticate' );
	}
);

register_deactivation_hook(
	__FILE__,
	function () {
		if ( function_exists( 'wp_presence_scene_sweep' ) ) {
			wp_presence_scene_sweep( true );
		}
	}
);
