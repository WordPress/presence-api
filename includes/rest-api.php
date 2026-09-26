<?php
/**
 * REST API: route registration for the presence controllers.
 *
 * @package Presence_API
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the presence REST routes.
 */
function wp_presence_register_rest_routes() {
	$controller = new WP_REST_Presence_Controller();
	$controller->register_routes();

	if ( is_multisite() ) {
		$network_controller = new WP_REST_Presence_Network_Controller();
		$network_controller->register_routes();
	}
}
