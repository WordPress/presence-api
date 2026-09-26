<?php
/**
 * Plugin-only activation, provisioning, and admin screen defaults.
 *
 * @package Presence_API
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates the presence table and schedules cleanup for the current site.
 *
 * @access private
 */
function wp_presence_provision_site() {
	wp_maybe_create_presence_table();
	wp_presence_schedule_cleanup();
}

/**
 * Handles plugin activation.
 *
 * Follows how core provisions per-site tables: they are created up front, at
 * activation for sites that already exist and at site creation for sites added
 * later. Nothing creates schema from a front-end request.
 *
 * Large networks are skipped, matching core's own guard against iterating every
 * site in one request. Those sites are provisioned the first time an admin
 * screen loads, and presence reads and writes are a no-op until then.
 *
 * @param bool $network_wide Whether the plugin is being activated for the network.
 */
function wp_presence_activate( $network_wide = false ) {
	if ( $network_wide && is_multisite() ) {
		wp_maybe_create_presence_network_summary_table();

		if ( ! wp_is_large_network() ) {
			foreach ( wp_presence_get_network_site_ids() as $site_id ) {
				switch_to_blog( $site_id );
				wp_presence_provision_site();
				restore_current_blog();
			}

			return;
		}
	}

	wp_presence_provision_site();
}

/**
 * Provisions a site created after the plugin was network activated.
 *
 * Core hooks its own wp_initialize_site() onto this action at priority 10 to
 * create the site's tables, so this runs after it. The action fires from
 * wp_insert_site() outside of any blog switch, hence the switch here.
 *
 * @param WP_Site $site The site that was just created.
 */
function wp_presence_on_initialize_site( $site ) {
	$network_plugins = get_site_option( 'active_sitewide_plugins', array() );

	// A site-by-site activation says nothing about this new site, so leave it alone.
	if ( ! isset( $network_plugins[ plugin_basename( dirname( __DIR__ ) . '/presence-api.php' ) ] ) ) {
		return;
	}

	switch_to_blog( $site->id );
	wp_presence_provision_site();
	restore_current_blog();
}

/**
 * Returns every site ID on the current network.
 *
 * @access private
 * @return int[] Site IDs.
 */
function wp_presence_get_network_site_ids() {
	$sites = get_sites(
		array(
			'fields'                 => 'ids',
			'number'                 => 0,
			'update_site_meta_cache' => false,
		)
	);

	return is_array( $sites ) ? $sites : array();
}

/**
 * Returns the default dashboard widget order when the user has no stored preference.
 *
 * @param array|false $result Stored meta value, or false if not set.
 * @return array|false Original value, or a default order with presence widgets first.
 */
function wp_presence_default_widget_order( $result ) {
	if ( $result ) {
		return $result;
	}
	return array(
		'normal' => 'presence_whos_online,presence_active_posts,dashboard_right_now,dashboard_activity',
		'side'   => 'dashboard_quick_press,dashboard_primary',
	);
}

/**
 * Cleans up on plugin deactivation.
 *
 * Cron events are stored per site, so a network deactivation has to clear each
 * one or every site keeps rescheduling an event with no callback behind it.
 *
 * @param bool $network_wide Whether the plugin is being deactivated for the network.
 */
function wp_presence_deactivate( $network_wide = false ) {
	if ( $network_wide && is_multisite() && ! wp_is_large_network() ) {
		foreach ( wp_presence_get_network_site_ids() as $site_id ) {
			switch_to_blog( $site_id );
			wp_clear_scheduled_hook( 'wp_delete_expired_presence_data' );
			restore_current_blog();
		}

		return;
	}

	wp_clear_scheduled_hook( 'wp_delete_expired_presence_data' );
}

/**
 * Adds action links to the plugin list table.
 *
 * The online users link points at the Users list filtered to the users who are
 * currently online. The plugin has no settings screen of its own, so the
 * settings link points at Settings > General, where the recording switch lives.
 *
 * @param string[] $links Existing plugin action links.
 * @return string[] Action links with the plugin's own links prepended.
 */
function wp_presence_plugin_action_links( $links ) {
	$online_users_link = sprintf(
		'<a href="%1$s">%2$s</a>',
		esc_url( admin_url( 'users.php?presence_status=online' ) ),
		esc_html__( 'View Online Users', 'presence-api' )
	);

	$settings_link = sprintf(
		'<a href="%1$s">%2$s</a>',
		esc_url( admin_url( 'options-general.php' ) ),
		esc_html__( 'Settings', 'presence-api' )
	);

	array_unshift( $links, $online_users_link, $settings_link );

	return $links;
}

/**
 * Adds action links to the Network Admin plugin list table.
 *
 * A network-activated install lists the plugin on the Network Admin Plugins
 * screen, which fires its own filter, so the single-site links never reach it.
 * The online users link points at the network Users list filtered to the users
 * who are currently online. That filter only applies when the request carries
 * the presence_online_filter nonce, the same way the network Users view builds
 * its link, so the URL is nonced here too. The settings link points at Network
 * Settings.
 *
 * @param string[] $links Existing network plugin action links.
 * @return string[] Action links with the plugin's own links prepended.
 */
function wp_presence_network_plugin_action_links( $links ) {
	$our_links = array();

	// Same gate as the Online view on the network Users screen this link
	// opens; without the capability that view is not there to land on.
	if ( current_user_can( wp_presence_network_capability() ) ) {
		$our_links[] = sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( wp_nonce_url( network_admin_url( 'users.php?presence_status=online' ), 'presence_online_filter' ) ),
			esc_html__( 'View Online Users', 'presence-api' )
		);
	}

	$our_links[] = sprintf(
		'<a href="%1$s">%2$s</a>',
		esc_url( network_admin_url( 'settings.php' ) ),
		esc_html__( 'Settings', 'presence-api' )
	);

	array_unshift( $links, ...$our_links );

	return $links;
}
