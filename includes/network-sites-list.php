<?php
/**
 * Network Sites list: "Online" column.
 *
 * @package Presence_API
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds an "Online" column to the Network Admin Sites list table.
 *
 * @param array $columns Existing column headers.
 * @return array Column headers with "Online" added.
 */
function wp_presence_register_network_sites_column( $columns ) {
	if ( ! current_user_can( wp_presence_network_capability() ) ) {
		return $columns;
	}

	$columns['presence_online'] = __( 'Online', 'presence-api' );

	return $columns;
}

/**
 * Renders the "Online" column for a single row of the Sites list table.
 *
 * Rendered once per page load, the same as every other column on this table
 * (e.g. "Last Updated"), rather than kept live via Heartbeat: the table isn't
 * ours to own the markup or lifecycle of, and a snapshot as of page load
 * matches how the rest of the table already behaves.
 *
 * Called once per row, and asks for that row's site only. The underlying
 * snapshot is read once for the request; what each row adds is resolving the
 * handful of people whose avatars it is about to draw.
 *
 * @param string $column_name Column being rendered.
 * @param int    $blog_id     The site ID for the current row.
 */
function wp_presence_render_network_sites_column( $column_name, $blog_id ) {
	if ( 'presence_online' !== $column_name ) {
		return;
	}

	$summary = wp_presence_get_network_summary(
		array(
			'blog_id'        => (int) $blog_id,
			'users_per_site' => WP_PRESENCE_NETWORK_AVATARS,
		)
	);

	if ( ! $summary['sites'] ) {
		echo '&#8212;';
		return;
	}

	$site = $summary['sites'][0];

	echo wp_kses_post( wp_presence_render_avatar_stack( $site['users'], WP_PRESENCE_NETWORK_AVATARS ) );
	echo ' ' . (int) $site['user_count'];
}
