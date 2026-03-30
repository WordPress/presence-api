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
 *
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

		if ( ! WP_CLI\Utils\get_flag_value( $assoc_args, 'yes', false ) ) {
			WP_CLI::confirm( __( 'This will delete all presence data. Continue?', 'presence-api' ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->query( "DELETE FROM {$wpdb->presence}" );

		/* translators: %d: Number of deleted entries. */
		WP_CLI::success( sprintf( __( '%d entries deleted.', 'presence-api' ), $deleted ) );
	}

	/**
	 * Seeds N demo users with real presence entries.
	 *
	 * Creates WordPress users and writes presence entries to the
	 * wp_presence table. All data is real — no mocking. Entries expire
	 * after 60 seconds unless --keep-alive is used.
	 *
	 * ## OPTIONS
	 *
	 * [<count>]
	 * : Number of users to seed. Default 10.
	 *
	 * [--keep-alive]
	 * : Refresh presence entries every 30 seconds until interrupted (Ctrl+C).
	 *
	 * [--interval=<seconds>]
	 * : Refresh interval in seconds when using --keep-alive. Default 30.
	 *
	 * [--cleanup]
	 * : Remove all demo users and their presence entries, then exit.
	 *
	 * ## EXAMPLES
	 *
	 *     wp presence demo
	 *     wp presence demo 100
	 *     wp presence demo 1000 --keep-alive
	 *     wp presence demo --cleanup
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function demo( $args, $assoc_args ) {
		require_once WP_PRESENCE_PLUGIN_DIR . 'tests/e2e/demo-seeder.php';

		if ( WP_CLI\Utils\get_flag_value( $assoc_args, 'cleanup', false ) ) {
			wp_presence_demo_cleanup();
			return;
		}

		$count    = min( isset( $args[0] ) ? absint( $args[0] ) : 10, 10000 );
		$user_ids = wp_presence_demo_seed( $count );

		if ( WP_CLI\Utils\get_flag_value( $assoc_args, 'keep-alive', false ) ) {
			$interval = (int) WP_CLI\Utils\get_flag_value( $assoc_args, 'interval', 30 );
			/* translators: %d: Refresh interval in seconds. */
			WP_CLI::log( sprintf( __( 'Refreshing presence every %ds. Press Ctrl+C to stop.', 'presence-api' ), $interval ) );

			while ( true ) {
				sleep( $interval );
				wp_presence_demo_refresh( $user_ids );
			}
		} else {
			WP_CLI::log( __( 'Entries will expire in 60 seconds. Use --keep-alive to refresh automatically.', 'presence-api' ) );
		}
	}
}
