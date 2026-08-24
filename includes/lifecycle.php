<?php
/**
 * Lifecycle hooks: sets/removes presence on login, logout, and user removal.
 *
 * @package Presence_API
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sets presence when a user logs in.
 *
 * @param string  $user_login Username.
 * @param WP_User $user       User object.
 */
function wp_presence_on_login( $user_login, $user ) {
	if ( ! user_can( $user, 'edit_posts' ) ) {
		return;
	}

	wp_set_presence(
		wp_presence_admin_room(),
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
 * @param int $user_id The ID of the user who just logged out.
 */
function wp_presence_on_logout( $user_id ) {
	if ( $user_id && user_can( $user_id, 'edit_posts' ) ) {
		wp_remove_user_presence( $user_id );
	}
}

/**
 * Removes a user's presence when their account is deleted or they are
 * removed from a site.
 *
 * Hooked to 'deleted_user' and 'remove_user_from_blog', both of which fire
 * with the site already switched to the one the user is leaving. No
 * capability gate, unlike login/logout: their role may already be gone.
 *
 * @param int $user_id The ID of the user being deleted or removed.
 */
function wp_presence_on_user_removed( $user_id ) {
	wp_remove_user_presence( $user_id );
}
