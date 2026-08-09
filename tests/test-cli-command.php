<?php
/**
 * Tests for the WP-CLI command (includes/cli/class-wp-presence-cli-command.php).
 *
 * presence-api.php only requires the command class when the WP_CLI constant is
 * defined, which it never is under PHPUnit, so both the class and the WP-CLI
 * runtime it depends on are pulled in explicitly here.
 *
 * @package Presence_API
 *
 * @group presence
 * @group cli
 */

require_once __DIR__ . '/stubs/wp-cli.php';
require_once dirname( __DIR__ ) . '/includes/cli/class-wp-presence-cli-command.php';

class WP_Test_Presence_CLI_Command extends WP_UnitTestCase {

	/**
	 * The command under test.
	 *
	 * @var WP_Presence_CLI_Command
	 */
	private $command;

	/**
	 * A real user, for the paths that look one up.
	 *
	 * @var int
	 */
	private static $user_id;

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$user_id = $factory->user->create( array( 'role' => 'editor' ) );
	}

	public function set_up() {
		parent::set_up();
		WP_CLI::reset();
		$this->command = new WP_Presence_CLI_Command();
	}

	public function tear_down() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "TRUNCATE TABLE {$wpdb->presence}" );
		parent::tear_down();
	}

	/**
	 * Runs a command expected to end in WP_CLI::error() and asserts the message.
	 *
	 * @param callable $run      Invokes the subcommand.
	 * @param string   $expected The expected error message.
	 */
	private function assert_halts_with( callable $run, $expected ) {
		try {
			$run();
		} catch ( WP_Presence_CLI_Halt $e ) {
			$this->assertSame( $expected, $e->getMessage() );
			$this->assertSame( array( $expected ), WP_CLI::messages( 'error' ) );
			return;
		}

		$this->fail( 'Expected WP_CLI::error() to halt the command.' );
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
	 * With no --user the command runs as user 0, which is the state a real CLI
	 * invocation starts in.
	 *
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
	 * wp_set_presence() returns false when presence storage is unavailable,
	 * which wp_presence_has_table() determines from the version option alone.
	 *
	 * The option is filtered rather than deleted. tear_down() truncates, and
	 * TRUNCATE is DDL, so it implicitly commits the transaction the test case
	 * wraps each test in — a deleted option would survive into every test that
	 * follows instead of rolling back.
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
}
