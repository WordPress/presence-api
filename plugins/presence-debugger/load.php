<?php
/**
 * Plugin Name: Presence Debugger
 * Description: The WP_DEBUG-only developer tools for the Presence API: the admin-bar debugger and the ?presence-db=1 table viewer. A separate plugin so releases of Presence API ship without them.
 * Version: 0.1.0
 * Requires at least: 7.0
 * Requires PHP: 7.4
 * Requires Plugins: presence-api
 * Author: WordPress Core Team
 * Author URI: https://make.wordpress.org/core/
 * Text Domain: presence-api
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * The tools keep the `presence-api` text domain they had when they lived in the
 * Presence API plugin: their strings only ever show under WP_DEBUG and were
 * deliberately kept out of the translation template, so moving them must not add
 * a new domain for translators to fill.
 *
 * @package Presence_API
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Developer tools only. A normal site never loads them, exactly as when they
// were guarded by this same WP_DEBUG check inside Presence API itself.
if ( ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
	return;
}

add_action(
	'plugins_loaded',
	function () {
		// Needs Presence API's functions and the WP_PRESENCE_VERSION the assets
		// are versioned with; bail quietly if it is not active yet.
		if ( ! function_exists( 'wp_get_presence' ) ) {
			return;
		}

		require_once __DIR__ . '/debugger-admin-bar.php';
		add_action( 'admin_bar_menu', 'wp_presence_debugger_admin_bar_node', 79 );
		add_action( 'admin_enqueue_scripts', 'wp_presence_debugger_admin_bar_assets' );
		add_action( 'wp_enqueue_scripts', 'wp_presence_debugger_admin_bar_assets' );
		add_filter( 'heartbeat_received', 'wp_presence_debugger_heartbeat_received', 13, 2 );

		require_once __DIR__ . '/db-viewer.php';
	}
);
