<?php
/**
 * Seeds the admin presence room for the Who's Online overflow spec.
 *
 * Populating the room through the UI would need one logged-in browser per
 * user, so the rows go in directly:
 *
 *   wp eval-file .../whos-online-overflow-seeder.php <count>
 *   wp eval-file .../whos-online-overflow-seeder.php drop-oldest
 *   wp eval-file .../whos-online-overflow-seeder.php clean
 *
 * @package Presence_API
 */

global $wpdb;

$room = wp_presence_admin_room();
$mode = isset( $args[0] ) ? $args[0] : 'clean';

if ( 'drop-oldest' === $mode ) {
	$entries = wp_get_presence( $room );
	$oldest  = end( $entries );

	wp_remove_presence( $room, $oldest->client_id );

	echo $oldest->client_id . " removed\n";

	return;
}

foreach ( wp_get_presence( $room ) as $entry ) {
	wp_remove_presence( $room, $entry->client_id );
}

if ( 'clean' === $mode ) {
	$stale = get_users(
		array(
			'search'         => 'presence_overflow_*',
			'search_columns' => array( 'user_login' ),
			'fields'         => 'ID',
		)
	);

	foreach ( $stale as $user_id ) {
		wp_delete_user( $user_id );
	}

	echo count( $stale ) . " removed\n";

	return;
}

for ( $i = 1; $i <= (int) $mode; $i++ ) {
	$login   = 'presence_overflow_' . $i;
	$user_id = username_exists( $login );

	if ( ! $user_id ) {
		$user_id = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_pass'    => wp_generate_password(),
				'user_email'   => $login . '@example.com',
				'display_name' => 'Overflow ' . $i,
				'role'         => 'editor',
			)
		);
	}

	wp_set_presence( $room, 'user-' . $user_id, array( 'screen' => 'dashboard' ), $user_id );

	// wp_get_presence() orders by date_gmt, so stagger the rows to make
	// "oldest" deterministic. Well inside the 150 second TTL at any count
	// this spec uses.
	$wpdb->update(
		$wpdb->presence,
		array( 'date_gmt' => gmdate( 'Y-m-d H:i:s', time() - $i ) ),
		array(
			'room'      => $room,
			'client_id' => 'user-' . $user_id,
		),
		array( '%s' ),
		array( '%s', '%s' )
	);
}

echo count( wp_get_presence( $room ) ) . " online\n";
