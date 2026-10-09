<?php
/**
 * Network Sites list: "Online" column, plus the aggregation notice both
 * network list tables share.
 *
 * @package Presence_API
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds an "Online" column to the Network Admin Sites list table.
 *
 * @since 0.2.0
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
 * Enqueues the avatar-stack stylesheet on the Sites list.
 *
 * No other presence asset loads on this screen, so nothing else pulls it in.
 *
 * @since 0.2.0
 *
 * @param string $hook_suffix The current admin page.
 */
function wp_presence_enqueue_network_sites_assets( $hook_suffix ) {
	if ( 'sites.php' !== $hook_suffix ) {
		return;
	}

	if ( ! current_user_can( wp_presence_network_capability() ) ) {
		return;
	}

	wp_presence_enqueue_avatar_stack_style();
}

/**
 * Warns above either network list table when the network does not aggregate
 * presence.
 *
 * The column reads the same em dash either way, so the reason is said once
 * above the table rather than repeated down every row of it. Both tables carry
 * that column and both are wrong in the same way without this, so they share
 * one notice rather than each wording the same thing.
 *
 * @since 0.3.0
 */
function wp_presence_network_aggregation_notice() {
	$screen = get_current_screen();

	if ( ! $screen || ! in_array( $screen->id, array( 'sites-network', 'users-network' ), true ) ) {
		return;
	}

	if ( ! current_user_can( wp_presence_network_capability() ) ) {
		return;
	}

	if ( wp_presence_network_aggregation_enabled() ) {
		return;
	}

	echo '<div class="notice notice-warning"><p>';
	echo esc_html__( 'Presence is not aggregated across this network, so the Online column has no data.', 'presence-api' );
	echo '</p></div>';
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
 * Gates on the capability again rather than trusting the registration above:
 * core calls this for whatever columns the screen ended up with, and ours is
 * not the only thing that can put a name in that list.
 *
 * @since 0.2.0
 *
 * @param string $column_name Column being rendered.
 * @param int    $blog_id     The site ID for the current row.
 */
function wp_presence_render_network_sites_column( $column_name, $blog_id ) {
	if ( 'presence_online' !== $column_name ) {
		return;
	}

	if ( ! current_user_can( wp_presence_network_capability() ) ) {
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

/**
 * Adds the hooks for the Network Admin screens.
 *
 * @access private
 *
 * @since 0.18.0
 */
function wp_presence_register_network_admin_hooks() {
	add_filter( 'wpmu_blogs_columns', 'wp_presence_register_network_sites_column' );
	add_action( 'manage_sites_custom_column', 'wp_presence_render_network_sites_column', 10, 2 );
	add_action( 'admin_enqueue_scripts', 'wp_presence_enqueue_network_sites_assets' );
	add_action( 'network_admin_notices', 'wp_presence_network_aggregation_notice' );

	add_filter( 'views_users-network', 'wp_presence_network_users_views' );
	add_filter( 'users_list_table_query_args', 'wp_presence_filter_network_online_users' );
	add_filter( 'wpmu_users_columns', 'wp_presence_register_network_users_column' );
	add_filter( 'manage_users-network_custom_column', 'wp_presence_render_network_users_column', 10, 3 );

	add_action( 'wp_network_dashboard_setup', array( 'WP_Presence_Network_Widget_Whos_Online', 'register' ) );
	add_filter( 'heartbeat_received', array( 'WP_Presence_Network_Widget_Whos_Online', 'heartbeat_received' ), 10, 3 );
}
