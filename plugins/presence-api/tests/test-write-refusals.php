<?php
/**
 * Tests for the WP_Error a refused presence write returns when asked.
 *
 * @package Presence_API
 *
 * @group presence
 */
class WP_Test_Presence_Write_Refusals extends WP_Presence_UnitTestCase {

	/**
	 * Editor who writes the rows.
	 *
	 * @var int
	 */
	protected static $editor_id;

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$editor_id = $factory->user->create( array( 'role' => 'editor' ) );
	}

	/**
	 * Callers that check for false keep working, since a WP_Error is truthy.
	 *
	 * @covers ::wp_set_presence
	 */
	public function test_a_refused_write_returns_false_without_the_argument() {
		add_filter( 'wp_presence_recording_enabled', '__return_false' );

		$this->assertFalse( wp_set_presence( 'room/off', 'client-1', array(), array( 'user_id' => self::$editor_id ) ) );
	}

	/**
	 * Recording switched off is a site's choice, not a fault, so it has its own code.
	 *
	 * @covers ::wp_set_presence
	 */
	public function test_recording_off_is_reported() {
		add_filter( 'wp_presence_recording_enabled', '__return_false' );

		$this->assertRefusal( 'presence_recording_disabled', wp_set_presence( 'room/off', 'client-1', array(), $this->args() ) );
	}

	/**
	 * A site that has not created the table yet is told so.
	 *
	 * @covers ::wp_set_presence
	 */
	public function test_a_missing_table_is_reported() {
		add_filter( 'option_wp_presence_db_version', '__return_zero' );

		$this->assertRefusal( 'presence_missing_table', wp_set_presence( 'room/none', 'client-1', array(), $this->args() ) );
	}

	/**
	 * A date that is not on the calendar names the argument at fault.
	 *
	 * @covers ::wp_set_presence
	 */
	public function test_an_invalid_date_gmt_is_reported() {
		$result = wp_set_presence( 'room/date', 'client-1', array(), $this->args( array( 'date_gmt' => '2026-02-30 00:00:00' ) ) );

		$this->assertRefusal( 'presence_invalid_date_gmt', $result );
	}

	/**
	 * A window under a second names the argument at fault.
	 *
	 * @covers ::wp_set_presence
	 */
	public function test_an_unusable_expires_in_is_reported() {
		$this->assertRefusal( 'presence_invalid_expires_in', wp_set_presence( 'room/expiry', 'client-1', array(), $this->args( array( 'expires_in' => 0 ) ) ) );
	}

	/**
	 * A failed query is the one refusal that is a fault rather than a choice.
	 *
	 * @covers ::wp_set_presence
	 */
	public function test_a_failed_write_is_reported() {
		global $wpdb;

		$fail_insert = static function ( $query ) use ( $wpdb ) {
			if ( 0 === stripos( ltrim( $query ), 'INSERT' ) && false !== strpos( $query, $wpdb->presence ) ) {
				return 'INVALID SQL SYNTAX';
			}
			return $query;
		};

		add_filter( 'query', $fail_insert );
		$suppress = $wpdb->suppress_errors();

		try {
			$result = wp_set_presence( 'room/fail', 'client-1', array(), $this->args() );
		} finally {
			remove_filter( 'query', $fail_insert );
			$wpdb->suppress_errors( $suppress );
		}

		$this->assertRefusal( 'presence_write_failed', $result );
	}

	/**
	 * A write that goes through still returns true with the argument set.
	 *
	 * @covers ::wp_set_presence
	 */
	public function test_a_successful_write_returns_true() {
		$this->assertTrue( wp_set_presence( 'room/ok', 'client-1', array(), $this->args() ) );
		$this->assertCount( 1, wp_get_presence( 'room/ok' ) );
	}

	/**
	 * The query-string form of $args turns the argument on too.
	 *
	 * @covers ::wp_set_presence
	 */
	public function test_the_argument_works_as_a_query_string() {
		add_filter( 'wp_presence_recording_enabled', '__return_false' );

		$this->assertRefusal( 'presence_recording_disabled', wp_set_presence( 'room/off', 'client-1', array(), 'wp_error=1' ) );
	}

	/**
	 * The exchange hands back the write's error instead of a room the write never reached.
	 *
	 * @covers ::wp_presence_exchange
	 */
	public function test_the_exchange_returns_the_write_error() {
		add_filter( 'wp_presence_recording_enabled', '__return_false' );

		$this->assertRefusal( 'presence_recording_disabled', wp_presence_exchange( 'room/off', 'client-1', array(), $this->args() ) );
		$this->assertSame( array(), wp_presence_exchange( 'room/off', 'client-1', array(), array( 'user_id' => self::$editor_id ) ), 'Without the argument the room is read as before.' );
	}

	/**
	 * Arguments for a write as the editor, with the WP_Error argument on.
	 *
	 * @param array $extra Arguments to add.
	 * @return array The arguments.
	 */
	private function args( $extra = array() ) {
		return array_merge(
			array(
				'user_id'  => self::$editor_id,
				'wp_error' => true,
			),
			$extra
		);
	}

	/**
	 * Asserts a result is a WP_Error with the given code.
	 *
	 * @param string $code   The expected error code.
	 * @param mixed  $result The value wp_set_presence() or wp_presence_exchange() returned.
	 */
	private function assertRefusal( $code, $result ) {
		$this->assertWPError( $result );
		$this->assertSame( $code, $result->get_error_code() );
	}
}
