<?php
/**
 * Lifecycle hooks: sets/removes presence on login and logout.
 *
 * @package Presence_API
 * @since 7.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sets presence when a user logs in.
 *
 * @since 7.1.0
 *
 * @param string  $user_login Username.
 * @param WP_User $user       User object.
 */
function wp_presence_on_login( $user_login, $user ) {
	if ( ! user_can( $user, 'edit_posts' ) ) {
		return;
	}

	wp_set_presence(
		'admin/online',
		'user-' . $user->ID,
		array(
			'screen' => 'login',
		),
		$user->ID
	);
}

/**
 * Removes all presence entries when a user logs out.
 *
 * @since 7.1.0
 */
function wp_presence_on_logout() {
	$user_id = get_current_user_id();

	if ( $user_id && user_can( $user_id, 'edit_posts' ) ) {
		wp_remove_user_presence( $user_id );
	}
}
