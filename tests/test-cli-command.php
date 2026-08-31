<?php
/**
 * Tests for the WP-CLI command (includes/cli/class-wp-presence-cli-command.php).
 *
 * @package Presence_API
 *
 * @group presence
 * @group cli
 */

require_once __DIR__ . '/stubs/wp-cli.php';
require_once dirname( __DIR__ ) . '/includes/cli/class-wp-presence-cli-command.php';

class WP_Test_Presence_CLI_Command extends WP_Presence_UnitTestCase {

	private $command;

	private static $user_id;

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$user_id = $factory->user->create( array( 'role' => 'editor' ) );
	}

	public function set_up() {
		parent::set_up();
		WP_CLI::reset();
		$this->command = new WP_Presence_CLI_Command();
	}

	private function assert_halts_with( callable $run, $expected, $type = 'error' ) {
		try {
			$run();
		} catch ( WP_Presence_CLI_Halt $e ) {
			$this->assertSame( $expected, $e->getMessage() );
			$this->assertSame( array( $expected ), WP_CLI::messages( $type ) );
			return;
		}

		$this->fail( 'Expected WP_CLI::error() to halt the command.' );
	}

	private function presence_row_count() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->presence}" );
	}

	/**
	 * @covers WP_Presence_CLI_Command::set
	 */
	public function test_set_stores_the_entry_and_reports_success() {
		$this->command->set( array( 'admin/online', 'cli-7' ), array( 'user' => self::$user_id ) );

		$entries = wp_get_presence( 'admin/online' );

		$this->assertCount( 1, $entries );
		$this->assertSame( 'cli-7', $entries[0]->client_id );
		$this->assertSame( self::$user_id, (int) $entries[0]->user_id );
		$this->assertSame(
			array( sprintf( 'Presence set in room "%1$s" for client "%2$s".', 'admin/online', 'cli-7' ) ),
			WP_CLI::messages( 'success' )
		);
	}

	/**
	 * @covers WP_Presence_CLI_Command::set
	 */
	public function test_set_defaults_the_client_id_to_the_cli_user() {
		$this->command->set( array( 'admin/online' ), array( 'user' => self::$user_id ) );

		$entries = wp_get_presence( 'admin/online' );

		$this->assertSame( 'cli-' . self::$user_id, $entries[0]->client_id );
	}

	/**
	 * @covers WP_Presence_CLI_Command::set
	 */
	public function test_set_defaults_to_user_zero() {
		$this->command->set( array( 'admin/online' ), array() );

		$entries = wp_get_presence( 'admin/online' );

		$this->assertSame( 'cli-0', $entries[0]->client_id );
		$this->assertSame( 0, (int) $entries[0]->user_id );
	}

	/**
	 * @covers WP_Presence_CLI_Command::set
	 */
	public function test_set_attaches_decoded_data() {
		$this->command->set(
			array( 'postType/post:42', 'lock-5' ),
			array(
				'user' => self::$user_id,
				'data' => '{"action":"editing"}',
			)
		);

		$entries = wp_get_presence( 'postType/post:42' );

		$this->assertSame( array( 'action' => 'editing' ), $entries[0]->data );
	}

	/**
	 * @covers WP_Presence_CLI_Command::set
	 */
	public function test_set_reports_recording_being_switched_off() {
		add_filter( 'wp_presence_recording_enabled', '__return_false' );

		$this->assert_halts_with(
			function () {
				$this->command->set( array( 'admin/online' ), array( 'user' => self::$user_id ) );
			},
			'Presence is not recorded on this site.'
		);
	}

	/**
	 * @covers WP_Presence_CLI_Command::set
	 */
	public function test_set_rejects_an_unknown_user() {
		$unknown = self::$user_id + 1000;

		$this->assert_halts_with(
			function () use ( $unknown ) {
				$this->command->set( array( 'admin/online' ), array( 'user' => $unknown ) );
			},
			'User not found.'
		);

		$this->assertSame( array(), wp_get_presence( 'admin/online' ), 'Nothing should be written when the user check fails.' );
	}

	/**
	 * @covers WP_Presence_CLI_Command::set
	 */
	public function test_set_rejects_invalid_json_in_data() {
		$this->assert_halts_with(
			function () {
				$this->command->set(
					array( 'admin/online' ),
					array(
						'user' => self::$user_id,
						'data' => '{not json}',
					)
				);
			},
			'Invalid JSON in --data.'
		);

		$this->assertSame( array(), wp_get_presence( 'admin/online' ), 'Nothing should be written when the payload is rejected.' );
	}

	/**
	 * Filtered rather than deleted: the base class truncates in tear_down(), and
	 * TRUNCATE implicitly commits, so a deleted option would not roll back.
	 *
	 * @covers WP_Presence_CLI_Command::set
	 */
	public function test_set_reports_a_write_failure() {
		add_filter( 'option_wp_presence_db_version', '__return_zero' );

		$this->assert_halts_with(
			function () {
				$this->command->set( array( 'admin/online' ), array( 'user' => self::$user_id ) );
			},
			'Failed to set presence.'
		);

		$this->assertSame( array(), WP_CLI::messages( 'success' ) );
	}

	/**
	 * @covers WP_Presence_CLI_Command::list_
	 */
	public function test_list_requires_a_room() {
		$this->assert_halts_with(
			function () {
				$this->command->list_( array(), array() );
			},
			'Please specify a room. Usage: wp presence list <room>'
		);

		$this->assertSame( array(), WP_CLI::$formatted, 'Nothing should be rendered without a room.' );
	}

	/**
	 * @covers WP_Presence_CLI_Command::list_
	 */
	public function test_list_reports_an_empty_room() {
		$this->command->list_( array( 'admin/online' ), array() );

		$this->assertSame( array( 'No presence entries in room "admin/online".' ), WP_CLI::messages( 'log' ) );
		$this->assertSame( array(), WP_CLI::$formatted, 'An empty room should not reach the formatter.' );
	}

	/**
	 * @covers WP_Presence_CLI_Command::list_
	 */
	public function test_list_renders_the_entries_in_a_room() {
		wp_set_presence( 'admin/online', 'cli-1', array( 'action' => 'editing' ), self::$user_id );
		wp_set_presence( 'admin/online', 'cli-2', array(), 0 );
		wp_set_presence( 'postType/post:42', 'other', array(), 0 );

		$this->command->list_( array( 'admin/online' ), array() );

		$this->assertCount( 1, WP_CLI::$formatted );
		$rendered = WP_CLI::$formatted[0];

		$this->assertSame( 'table', $rendered['format'], 'table is the documented default.' );
		$this->assertSame( array( 'room', 'client_id', 'user_id', 'data', 'date_gmt' ), $rendered['fields'] );
		$this->assertCount( 2, $rendered['items'], 'Only the requested room should be listed.' );

		$by_client = array_column( $rendered['items'], null, 'client_id' );

		$this->assertSame( 'admin/online', $by_client['cli-1']['room'] );
		$this->assertSame( self::$user_id, (int) $by_client['cli-1']['user_id'] );
		$this->assertSame( '{"action":"editing"}', $by_client['cli-1']['data'], 'Data is re-encoded for display.' );
		$this->assertNotEmpty( $by_client['cli-1']['date_gmt'] );
		$this->assertSame( '[]', $by_client['cli-2']['data'], 'An empty state round-trips as an empty JSON array.' );
	}

	/**
	 * @covers WP_Presence_CLI_Command::list_
	 */
	public function test_list_passes_the_requested_format_through() {
		wp_set_presence( 'admin/online', 'cli-1', array(), 0 );

		$this->command->list_( array( 'admin/online' ), array( 'format' => 'json' ) );

		$this->assertSame( 'json', WP_CLI::$formatted[0]['format'] );
	}

	/**
	 * @covers WP_Presence_CLI_Command::summary
	 */
	public function test_summary_reports_totals_and_prefixes() {
		wp_set_presence( 'admin/online', 'cli-1', array(), self::$user_id );
		wp_set_presence( 'admin/dashboard', 'cli-2', array(), self::$user_id );
		wp_set_presence( 'postType/post:42', 'cli-3', array(), 0 );

		$this->command->summary( array(), array() );

		$logs = WP_CLI::messages( 'log' );

		$this->assertContains( 'Total entries: 3', $logs );
		$this->assertContains( 'Total users:   2', $logs, 'The same user in two rooms counts once.' );

		$this->assertCount( 1, WP_CLI::$formatted );
		$rendered = WP_CLI::$formatted[0];

		$this->assertSame( 'table', $rendered['format'] );
		$this->assertSame( array( 'prefix', 'entries', 'users' ), $rendered['fields'] );

		$by_prefix = array_column( $rendered['items'], null, 'prefix' );

		$this->assertSame( 2, $by_prefix['admin']['entries'] );
		$this->assertSame( 1, $by_prefix['admin']['users'] );
		$this->assertSame( 1, $by_prefix['postType']['entries'] );
	}

	/**
	 * @covers WP_Presence_CLI_Command::summary
	 */
	public function test_summary_reports_no_data() {
		$this->command->summary( array(), array() );

		$logs = WP_CLI::messages( 'log' );

		$this->assertContains( 'Total entries: 0', $logs );
		$this->assertContains( 'No presence data.', $logs );
		$this->assertSame( array(), WP_CLI::$formatted, 'There is no prefix table to render.' );
	}

	/**
	 * @covers WP_Presence_CLI_Command::summary
	 */
	public function test_summary_prints_json_in_one_document() {
		wp_set_presence( 'admin/online', 'cli-1', array(), self::$user_id );

		$this->command->summary( array(), array( 'format' => 'json' ) );

		$logs = WP_CLI::messages( 'log' );

		$this->assertCount( 1, $logs );
		$this->assertSame( wp_get_presence_summary(), json_decode( $logs[0], true ) );
		$this->assertSame( array(), WP_CLI::$formatted );
	}

	/**
	 * @covers WP_Presence_CLI_Command::cleanup
	 */
	public function test_cleanup_deletes_every_entry_regardless_of_age() {
		global $wpdb;

		wp_set_presence( 'admin/online', 'cli-1', array(), self::$user_id );
		wp_set_presence( 'postType/post:42', 'cli-2', array(), 0 );

		$wpdb->insert(
			$wpdb->presence,
			array(
				'room'      => 'admin/stale',
				'client_id' => 'expired',
				'user_id'   => 0,
				'data'      => '[]',
				'date_gmt'  => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ),
			)
		);

		$this->assertSame( 3, $this->presence_row_count(), 'Guard: the fixture should be in place.' );

		$this->command->cleanup( array(), array( 'yes' => true ) );

		$this->assertSame( 0, $this->presence_row_count() );
		$this->assertSame( array( '3 entries deleted.' ), WP_CLI::messages( 'success' ) );
	}

	/**
	 * @covers WP_Presence_CLI_Command::cleanup
	 */
	public function test_cleanup_reports_zero_without_a_table() {
		add_filter( 'option_wp_presence_db_version', '__return_zero' );

		$this->command->cleanup( array(), array() );

		$this->assertSame( array( '0 entries deleted.' ), WP_CLI::messages( 'success' ) );
		$this->assertSame( array(), WP_CLI::messages( 'confirm' ) );
	}

	/**
	 * @covers WP_Presence_CLI_Command::cleanup
	 */
	public function test_cleanup_skips_the_prompt_with_the_yes_flag() {
		$this->command->cleanup( array(), array( 'yes' => true ) );

		$this->assertSame( array(), WP_CLI::messages( 'confirm' ) );
	}

	/**
	 * @covers WP_Presence_CLI_Command::cleanup
	 */
	public function test_cleanup_prompts_without_the_yes_flag() {
		wp_set_presence( 'admin/online', 'cli-1', array(), self::$user_id );

		$this->command->cleanup( array(), array() );

		$this->assertSame( array( 'This will delete all presence data. Continue?' ), WP_CLI::messages( 'confirm' ) );
		$this->assertSame( 0, $this->presence_row_count(), 'Confirming should carry on into the delete.' );
	}

	/**
	 * @covers WP_Presence_CLI_Command::cleanup
	 */
	public function test_cleanup_leaves_the_table_untouched_when_the_prompt_is_declined() {
		wp_set_presence( 'admin/online', 'cli-1', array(), self::$user_id );

		WP_CLI::$confirm_declines = true;

		$this->assert_halts_with(
			function () {
				$this->command->cleanup( array(), array() );
			},
			'This will delete all presence data. Continue?',
			'confirm'
		);

		$this->assertSame( 1, $this->presence_row_count(), 'Declining the prompt should leave the table untouched.' );
	}

	/**
	 * @covers WP_Presence_CLI_Command::cleanup
	 */
	public function test_cleanup_reports_zero_on_an_empty_table() {
		$this->command->cleanup( array(), array( 'yes' => true ) );

		$this->assertSame( array( '0 entries deleted.' ), WP_CLI::messages( 'success' ) );
	}

	/**
	 * The summary table behind it only exists on a network, and the functions it
	 * reads are not loaded here at all.
	 *
	 * @covers WP_Presence_CLI_Command::network
	 */
	public function test_network_refuses_to_run_on_a_single_site() {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'Requires single site.' );
		}

		$this->assert_halts_with(
			function () {
				$this->command->network( array(), array() );
			},
			'This is not a multisite installation.'
		);
	}
}
