<?php
/**
 * Tests for the network subcommand of the WP-CLI command.
 *
 * @package Presence_API
 *
 * @group presence
 * @group cli
 * @group ms-required
 *
 * @covers WP_Presence_CLI_Command::network
 */

require_once __DIR__ . '/stubs/wp-cli.php';
require_once dirname( __DIR__ ) . '/includes/cli/class-wp-presence-cli-command.php';

class WP_Test_Presence_CLI_Network_Command extends WP_Presence_Network_UnitTestCase {

	private $command;

	private static $editor_id;

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$editor_id = $factory->user->create( array( 'role' => 'editor' ) );
	}

	public function set_up() {
		parent::set_up();
		WP_CLI::reset();
		$this->command = new WP_Presence_CLI_Command();
	}

	private function assert_errors_with( callable $run, $expected ) {
		try {
			$run();
		} catch ( WP_Presence_CLI_Halt $e ) {
			$this->assertSame( $expected, $e->getMessage() );
			return;
		}

		$this->fail( 'Expected WP_CLI::error() to halt the command.' );
	}

	private function last_table() {
		return end( WP_CLI::$formatted );
	}

	public function test_it_reports_every_site_with_someone_online() {
		$busy  = $this->create_blog();
		$quiet = $this->create_blog();

		$this->set_network_summary_row( $busy, self::factory()->user->create_many( 2 ) );
		$this->set_network_summary_row( $quiet, array( self::$editor_id ) );

		$this->command->network( array(), array() );

		$this->assertContains( 'Sites online: 2', WP_CLI::messages( 'log' ) );
		$this->assertContains( 'Users online: 3', WP_CLI::messages( 'log' ) );

		$table = $this->last_table();

		$this->assertSame( array( 'blog_id', 'url', 'user_count', 'users' ), $table['fields'] );
		$this->assertSame( array( $busy, $quiet ), wp_list_pluck( $table['items'], 'blog_id' ), 'Busiest site first.' );
		$this->assertSame( 2, $table['items'][0]['user_count'] );
	}

	public function test_it_names_the_users_online_on_each_site() {
		$blog_id = $this->create_blog();

		$this->set_network_summary_row( $blog_id, array( self::$editor_id ) );

		$this->command->network( array(), array() );

		$this->assertSame(
			get_userdata( self::$editor_id )->display_name,
			$this->last_table()['items'][0]['users']
		);
	}

	public function test_a_quiet_network_reports_zero_rather_than_a_table() {
		$this->command->network( array(), array() );

		$this->assertSame(
			array( 'Sites online: 0', 'Users online: 0', 'No presence data.' ),
			WP_CLI::messages( 'log' )
		);
		$this->assertSame( array(), WP_CLI::$formatted );
	}

	/**
	 * Zero sites and zero users is the answer a network that has stopped
	 * aggregating cannot give, so it says what it does not know instead.
	 */
	public function test_a_network_that_does_not_aggregate_says_so_instead_of_reporting_zero() {
		$this->set_network_summary_row( $this->create_blog(), array( self::$editor_id ) );

		add_filter( 'wp_presence_network_aggregation_enabled', '__return_false' );
		wp_presence_flush_network_summary_cache();

		$this->command->network( array(), array() );

		$this->assertSame( array(), WP_CLI::messages( 'log' ) );
		$this->assertStringContainsString( 'not aggregated across this network', WP_CLI::messages( 'warning' )[0] );
	}

	/**
	 * A script reading the JSON gets the flag rather than the warning, which
	 * only ever reaches STDERR.
	 */
	public function test_json_carries_the_aggregation_state() {
		add_filter( 'wp_presence_network_aggregation_enabled', '__return_false' );
		wp_presence_flush_network_summary_cache();

		$this->command->network( array(), array( 'format' => 'json' ) );

		$this->assertFalse( json_decode( WP_CLI::messages( 'log' )[0], true )['aggregating'] );
	}

	public function test_json_emits_the_whole_summary() {
		$blog_id = $this->create_blog();

		$this->set_network_summary_row( $blog_id, array( self::$editor_id ) );

		$this->command->network( array(), array( 'format' => 'json' ) );

		$decoded = json_decode( WP_CLI::messages( 'log' )[0], true );

		$this->assertSame( 1, $decoded['total_sites_online'] );
		$this->assertSame( 1, $decoded['total_users_online'] );
		$this->assertSame( $blog_id, $decoded['sites'][0]['blog_id'] );
		$this->assertSame( self::$editor_id, $decoded['sites'][0]['users'][0]['user_id'] );
	}

	public function test_site_narrows_to_one_site_while_the_totals_stay_network_wide() {
		$wanted = $this->create_blog();
		$other  = $this->create_blog();

		$this->set_network_summary_row( $wanted, array( self::$editor_id ) );
		$this->set_network_summary_row( $other, self::factory()->user->create_many( 2 ) );

		$this->command->network( array(), array( 'site' => $wanted ) );

		$this->assertSame( array( $wanted ), wp_list_pluck( $this->last_table()['items'], 'blog_id' ) );
		$this->assertContains( 'Sites online: 2', WP_CLI::messages( 'log' ) );
	}

	// Without this a typo'd ID reads as a quiet site rather than a bad flag.
	public function test_site_rejects_an_id_no_site_answers_to() {
		$this->assert_errors_with(
			fn() => $this->command->network( array(), array( 'site' => 999999 ) ),
			'Invalid site ID.'
		);
	}

	public function test_the_caps_bound_what_is_reported() {
		$busy  = $this->create_blog();
		$quiet = $this->create_blog();

		$this->set_network_summary_row( $busy, self::factory()->user->create_many( 3 ) );
		$this->set_network_summary_row( $quiet, array( self::$editor_id ) );

		$this->command->network(
			array(),
			array(
				'sites'          => 1,
				'users-per-site' => 1,
			)
		);

		$items = $this->last_table()['items'];

		$this->assertCount( 1, $items );
		$this->assertSame( 3, $items[0]['user_count'], 'A capped list reported its own length as the headcount.' );
		$this->assertSame( 1, substr_count( $items[0]['users'], ',' ) + 1 );
	}

	public function test_running_as_a_user_without_the_capability_is_refused() {
		wp_set_current_user( self::$editor_id );

		$this->assert_errors_with(
			fn() => $this->command->network( array(), array() ),
			'Sorry, you are not allowed to view network presence.'
		);
	}

	public function test_running_as_a_network_admin_is_allowed() {
		$this->become_network_admin();

		$this->command->network( array(), array() );

		$this->assertSame( array(), WP_CLI::messages( 'error' ) );
	}

	public function test_running_without_a_user_is_allowed() {
		$this->assertSame( 0, get_current_user_id() );

		$this->command->network( array(), array() );

		$this->assertSame( array(), WP_CLI::messages( 'error' ) );
	}

	/**
	 * The network switch writes a site option, so a site that allows recording
	 * still stops. Strictest wins, as with the filters.
	 *
	 * @covers WP_Presence_CLI_Command::recording
	 */
	public function test_recording_set_network_stops_a_site_that_allows_recording() {
		update_option( 'wp_presence_recording', '1' );

		$this->command->recording( array( 'set', 'off' ), array( 'network' => true ) );

		$this->assertFalse( (bool) get_site_option( 'wp_presence_network_recording' ) );
		$this->assertFalse( wp_presence_recording_enabled() );
	}
}
