<?php
/**
 * WP-CLI commands for the Presence API.
 *
 * @package Presence_API
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages presence entries.
 */
class WP_Presence_CLI_Command extends WP_CLI_Command {

	/**
	 * Sets a presence entry in a room.
	 *
	 * Entry expires via normal TTL cleanup (60s).
	 *
	 * ## OPTIONS
	 *
	 * <room>
	 * : The room identifier.
	 *
	 * [<client_id>]
	 * : The client identifier. Defaults to cli-{user_id}.
	 *
	 * [--data=<json>]
	 * : JSON-encoded data to attach to the presence entry.
	 *
	 * [--user=<id>]
	 * : The user ID. Defaults to the current CLI user (0).
	 *
	 * ## EXAMPLES
	 *
	 *     wp presence set admin/online
	 *     wp presence set admin/online cli-1 --user=1
	 *     wp presence set postType/post:42 lock-5 --user=5 --data='{"action":"editing"}'
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function set( $args, $assoc_args ) {
		$room    = $args[0];
		$user_id = (int) WP_CLI\Utils\get_flag_value( $assoc_args, 'user', 0 );

		if ( ! wp_presence_recording_enabled() ) {
			WP_CLI::error( __( 'Presence is not recorded on this site.', 'presence-api' ) );
		}

		if ( $user_id && ! get_user_by( 'id', $user_id ) ) {
			WP_CLI::error( __( 'User not found.', 'presence-api' ) );
		}

		$client_id = isset( $args[1] ) ? $args[1] : 'cli-' . $user_id;

		$data = array();
		if ( ! empty( $assoc_args['data'] ) ) {
			$decoded = json_decode( $assoc_args['data'], true );
			if ( null === $decoded ) {
				WP_CLI::error( __( 'Invalid JSON in --data.', 'presence-api' ) );
			}
			$data = $decoded;
		}

		$result = wp_set_presence( $room, $client_id, $data, $user_id );

		if ( $result ) {
			/* translators: 1: Room identifier, 2: Client identifier. */
			WP_CLI::success( sprintf( __( 'Presence set in room "%1$s" for client "%2$s".', 'presence-api' ), $room, $client_id ) );
		} else {
			WP_CLI::error( __( 'Failed to set presence.', 'presence-api' ) );
		}
	}

	/**
	 * Lists presence entries in a room.
	 *
	 * @subcommand list
	 *
	 * ## OPTIONS
	 *
	 * <room>
	 * : The room identifier.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp presence list admin/online
	 *     wp presence list postType/post:42 --format=json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function list_( $args, $assoc_args ) {
		if ( empty( $args[0] ) ) {
			WP_CLI::error( __( 'Please specify a room. Usage: wp presence list <room>', 'presence-api' ) );
		}

		$room    = $args[0];
		$entries = wp_get_presence( $room );
		$format  = WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );

		if ( empty( $entries ) ) {
			/* translators: %s: Room identifier. */
			WP_CLI::log( sprintf( __( 'No presence entries in room "%s".', 'presence-api' ), $room ) );
			return;
		}

		$items = array();
		foreach ( $entries as $entry ) {
			$items[] = array(
				'room'      => $entry->room,
				'client_id' => $entry->client_id,
				'user_id'   => $entry->user_id,
				'data'      => wp_json_encode( $entry->data ),
				'date_gmt'  => $entry->date_gmt,
			);
		}

		WP_CLI\Utils\format_items( $format, $items, array( 'room', 'client_id', 'user_id', 'data', 'date_gmt' ) );
	}

	/**
	 * Shows a site-wide presence summary.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp presence summary
	 *     wp presence summary --format=json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function summary( $args, $assoc_args ) {
		$summary = wp_get_presence_summary();
		$format  = WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );

		if ( 'json' === $format ) {
			WP_CLI::log( wp_json_encode( $summary ) );
			return;
		}

		/* translators: %d: Total number of presence entries. */
		WP_CLI::log( sprintf( __( 'Total entries: %d', 'presence-api' ), $summary['total_entries'] ) );
		/* translators: %d: Total number of distinct users. */
		WP_CLI::log( sprintf( __( 'Total users:   %d', 'presence-api' ), $summary['total_users'] ) );

		if ( empty( $summary['by_prefix'] ) ) {
			WP_CLI::log( __( 'No presence data.', 'presence-api' ) );
			return;
		}

		$items = array();
		foreach ( $summary['by_prefix'] as $prefix => $data ) {
			$items[] = array(
				'prefix'  => $prefix,
				'entries' => $data['entries'],
				'users'   => $data['users'],
			);
		}

		WP_CLI::log( '' );
		WP_CLI\Utils\format_items( $format, $items, array( 'prefix', 'entries', 'users' ) );
	}

	/**
	 * Shows a network-wide presence summary.
	 *
	 * Multisite only.
	 *
	 * ## OPTIONS
	 *
	 * [--site=<blog_id>]
	 * : Report this site only. The totals stay network-wide.
	 *
	 * [--sites=<number>]
	 * : Maximum sites to report, busiest first. Defaults to every site.
	 *
	 * [--users-per-site=<number>]
	 * : Maximum users to name per site. Defaults to every user.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp presence network
	 *     wp presence network --sites=5 --users-per-site=4
	 *     wp presence network --site=3
	 *     wp presence network --format=json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function network( $args, $assoc_args ) {
		if ( ! is_multisite() ) {
			WP_CLI::error( __( 'This is not a multisite installation.', 'presence-api' ) );
		}

		// Only once --user names someone: shell access already outranks the
		// capability, but running as a user must not show more than they could see.
		if ( get_current_user_id() && ! current_user_can( wp_presence_network_capability() ) ) {
			WP_CLI::error( __( 'Sorry, you are not allowed to view network presence.', 'presence-api' ) );
		}

		$blog_id = (int) WP_CLI\Utils\get_flag_value( $assoc_args, 'site', 0 );

		if ( $blog_id && ! get_site( $blog_id ) ) {
			WP_CLI::error( __( 'Invalid site ID.', 'presence-api' ) );
		}

		$summary = wp_presence_get_network_summary(
			array(
				'blog_id'        => $blog_id,
				'sites'          => (int) WP_CLI\Utils\get_flag_value( $assoc_args, 'sites', 0 ),
				'users_per_site' => (int) WP_CLI\Utils\get_flag_value( $assoc_args, 'users-per-site', 0 ),
			)
		);

		$format = WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );

		if ( 'json' === $format ) {
			WP_CLI::log( wp_json_encode( $summary ) );
			return;
		}

		// Reported instead of the totals, not alongside them: zero sites and
		// zero users is the answer this network cannot give.
		if ( ! $summary['aggregating'] ) {
			WP_CLI::warning( __( 'Presence is not aggregated across this network, so who is online cannot be reported.', 'presence-api' ) );
			return;
		}

		/* translators: %d: Number of sites with someone online. */
		WP_CLI::log( sprintf( __( 'Sites online: %d', 'presence-api' ), $summary['total_sites_online'] ) );
		/* translators: %d: Number of users online, summed per site. */
		WP_CLI::log( sprintf( __( 'Users online: %d', 'presence-api' ), $summary['total_users_online'] ) );

		if ( empty( $summary['sites'] ) ) {
			WP_CLI::log( __( 'No presence data.', 'presence-api' ) );
			return;
		}

		$items = array();
		foreach ( $summary['sites'] as $site ) {
			$items[] = array(
				'blog_id'    => $site['blog_id'],
				'url'        => $site['url'],
				'user_count' => $site['user_count'],
				'users'      => implode( ', ', wp_list_pluck( $site['users'], 'display_name' ) ),
			);
		}

		WP_CLI::log( '' );
		WP_CLI\Utils\format_items( $format, $items, array( 'blog_id', 'url', 'user_count', 'users' ) );
	}

	/**
	 * Gets or sets whether presence is recorded.
	 *
	 * Reports the option rather than the effective state, so a `get` still shows
	 * what the switch is set to on a site where a filter overrides it.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : Whether to read or write the switch.
	 * ---
	 * options:
	 *   - get
	 *   - set
	 * ---
	 *
	 * [<state>]
	 * : The state to store. Required for set.
	 * ---
	 * options:
	 *   - on
	 *   - off
	 * ---
	 *
	 * [--network]
	 * : Act on the network-wide switch instead. Multisite only.
	 *
	 * ## EXAMPLES
	 *
	 *     wp presence recording get
	 *     wp presence recording set off
	 *     wp presence recording get --network
	 *     wp presence recording set off --network
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function recording( $args, $assoc_args ) {
		$network = (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'network', false );

		if ( $network && ! is_multisite() ) {
			WP_CLI::error( __( 'This is not a multisite installation.', 'presence-api' ) );
		}

		$option  = $network ? 'wp_presence_network_recording' : 'wp_presence_recording';
		$enabled = $network
			? (bool) get_site_option( $option, true )
			: (bool) get_option( $option, true );

		if ( 'get' === $args[0] ) {
			WP_CLI::log( $enabled ? 'on' : 'off' );
			return;
		}

		if ( ! isset( $args[1] ) ) {
			WP_CLI::error( __( 'Specify on or off.', 'presence-api' ) );
		}

		$target = 'on' === $args[1];

		// Stored as '1' or '0'. A boolean false is indistinguishable from an
		// absent option, so update_option() would discard it as unchanged.
		$stored = $target ? '1' : '0';

		if ( $network ) {
			update_site_option( $option, $stored );
		} else {
			update_option( $option, $stored );
		}

		if ( $target ) {
			WP_CLI::success( __( 'Presence recording is on.', 'presence-api' ) );
			return;
		}

		WP_CLI::success( __( 'Presence recording is off. Existing entries expire on their own.', 'presence-api' ) );
	}

	/**
	 * Deletes all presence entries from the table.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp presence cleanup
	 *     wp presence cleanup --yes
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function cleanup( $args, $assoc_args ) {
		global $wpdb;

		if ( ! wp_presence_has_table() ) {
			/* translators: %d: Number of deleted entries. */
			WP_CLI::success( sprintf( __( '%d entries deleted.', 'presence-api' ), 0 ) );
			return;
		}

		if ( ! WP_CLI\Utils\get_flag_value( $assoc_args, 'yes', false ) ) {
			WP_CLI::confirm( __( 'This will delete all presence data. Continue?', 'presence-api' ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->query( "DELETE FROM {$wpdb->presence}" );

		/* translators: %d: Number of deleted entries. */
		WP_CLI::success( sprintf( __( '%d entries deleted.', 'presence-api' ), $deleted ) );
	}
}
