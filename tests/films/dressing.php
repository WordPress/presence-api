<?php
/**
 * Dressing tests/films/record.mjs installs while it films: realistic names, initials avatars, agents marked by user meta, and a record of removed rows for its checks.
 */

const PRESENCE_FILMS_NAMES = array( 1 => 'Maya Chen', 2 => 'Theo Okafor', 3 => 'Priya Raman' );

add_filter(
	'wp_pre_insert_user_data',
	function ( $data, $update, $user_id, $userdata ) {
		if ( preg_match( '/^actor(\d+)run/', $userdata['user_login'] ?? '', $m ) && isset( PRESENCE_FILMS_NAMES[ (int) $m[1] ] ) ) {
			$data['display_name'] = PRESENCE_FILMS_NAMES[ (int) $m[1] ];
		}
		return $data;
	},
	10,
	4
);

add_filter(
	'pre_get_avatar_data',
	function ( $args, $id_or_email ) {
		$user = $id_or_email instanceof WP_User ? $id_or_email : ( is_numeric( $id_or_email ) ? get_userdata( (int) $id_or_email ) : ( is_string( $id_or_email ) ? get_user_by( 'email', $id_or_email ) : ( isset( $id_or_email->user_id ) ? get_userdata( (int) $id_or_email->user_id ) : false ) ) );
		if ( ! $user ) {
			return $args;
		}
		$palette = array(
			array( '#3858e9', '#7b90ff' ),
			array( '#e65054', '#ff9a8b' ),
			array( '#008a20', '#4ab866' ),
			array( '#9b51e0', '#c89bff' ),
			array( '#d97706', '#fbbf24' ),
			array( '#0e7490', '#22d3ee' ),
		);
		$words    = preg_split( '/\s+/', trim( $user->display_name ) );
		$initials = strtoupper( mb_substr( $words[0], 0, 1 ) . ( count( $words ) > 1 ? mb_substr( end( $words ), 0, 1 ) : '' ) );
		$c        = $palette[ crc32( $user->display_name ) % count( $palette ) ];
		$svg      = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="' . $c[1] . '"/><stop offset="1" stop-color="' . $c[0] . '"/></linearGradient></defs><rect width="64" height="64" fill="url(#g)"/><text x="32" y="41" text-anchor="middle" font-family="-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif" font-size="24" font-weight="600" fill="#fff">' . esc_html( $initials ) . '</text></svg>';
		$file = 'avatar-' . md5( $svg ) . '.svg';
		$dir  = '/var/www/html/presence-api/artifacts/films/avatars/';
		if ( ! file_exists( $dir . $file ) ) {
			wp_mkdir_p( $dir );
			file_put_contents( $dir . $file, $svg );
		}
		$args['url']          = home_url( '/presence-api/artifacts/films/avatars/' . $file );
		$args['found_avatar'] = true;
		return $args;
	},
	10,
	2
);

add_filter(
	'wp_presence_is_agent_user',
	function ( $is_agent, $user_id ) {
		return $is_agent || (bool) get_user_meta( $user_id, '_presence_films_agent', true );
	},
	10,
	2
);

add_action(
	'removed_presence',
	function ( $room, $client_id ) {
		$removed   = get_option( 'presence_films_removed', array() );
		$removed[] = "$room $client_id";
		update_option( 'presence_films_removed', $removed, false );
	},
	10,
	2
);
