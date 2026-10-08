<?php
/**
 * Tests for the actions the presence write functions fire.
 *
 * @package Presence_API
 *
 * @group presence
 *
 * @covers ::wp_set_presence
 * @covers ::wp_presence_write_row
 * @covers ::wp_remove_presence
 * @covers ::wp_remove_user_presence
 */
class WP_Test_Presence_Write_Actions extends WP_Presence_UnitTestCase {

	private static $editor_id;

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$editor_id = $factory->user->create( array( 'role' => 'editor' ) );
	}

	/**
	 * Listens on an action and records every call.
	 *
	 * @param string $hook The action to listen on.
	 * @param int    $args How many arguments the action passes.
	 * @return MockAction The listener.
	 */
	private function listen( $hook, $args ) {
		$listener = new MockAction();
		add_action( $hook, array( $listener, 'action' ), 10, $args );

		return $listener;
	}

	/**
	 * Moves a row's timestamp into the past, as time passing would.
	 *
	 * @param string $room      The room identifier.
	 * @param string $client_id The client identifier.
	 * @param int    $seconds   How far back to move it.
	 */
	private function backdate( $room, $client_id, $seconds ) {
		global $wpdb;

		$wpdb->update(
			$wpdb->presence,
			array( 'date_gmt' => gmdate( 'Y-m-d H:i:s', time() - $seconds ) ),
			array(
				'room'      => $room,
				'client_id' => $client_id,
			),
			array( '%s' ),
			array( '%s', '%s' )
		);
	}

	public function test_set_presence_fires_once_with_what_was_written() {
		$listener = $this->listen( 'set_presence', 4 );

		wp_set_presence( 'test/room', 'client-1', array( 'action' => 'editing' ), array( 'user_id' => self::$editor_id ) );

		$this->assertSame( 1, $listener->get_call_count() );
		$this->assertSame(
			array( 'test/room', 'client-1', array( 'action' => 'editing' ), self::$editor_id ),
			$listener->get_args()[0]
		);
	}

	public function test_set_presence_fires_when_the_state_changes() {
		wp_set_presence( 'test/room', 'client-1', array( 'action' => 'editing' ), array( 'user_id' => self::$editor_id ) );
		$this->backdate( 'test/room', 'client-1', 5 );

		$listener = $this->listen( 'set_presence', 4 );

		wp_set_presence( 'test/room', 'client-1', array( 'action' => 'idle' ), array( 'user_id' => self::$editor_id ) );

		$this->assertSame( 1, $listener->get_call_count() );
		$this->assertSame( array( 'action' => 'idle' ), $listener->get_args()[0][2] );
	}

	/**
	 * @covers ::wp_presence_refresh_cutoff
	 */
	public function test_set_presence_stays_quiet_for_a_skipped_unchanged_write() {
		if ( $GLOBALS['wpdb'] instanceof WP_SQLite_DB ) {
			$this->markTestSkipped( 'The SQLite driver reports an unchanged upsert as one affected row, where MySQL reports none.' );
		}

		wp_set_presence( 'test/room', 'client-1', array( 'action' => 'editing' ), array( 'user_id' => self::$editor_id ) );
		$this->backdate( 'test/room', 'client-1', 5 );

		$listener = $this->listen( 'set_presence', 4 );

		$result = wp_set_presence( 'test/room', 'client-1', array( 'action' => 'editing' ), array( 'user_id' => self::$editor_id ) );

		$this->assertTrue( $result, 'A skipped write still reports success.' );
		$this->assertSame( 0, $listener->get_call_count(), 'Nothing changed, so there is nothing to react to.' );
	}

	public function test_set_presence_stays_quiet_when_the_write_is_rejected() {
		$listener = $this->listen( 'set_presence', 4 );

		$this->assertFalse( wp_set_presence(
			'test/room',
			'client-1',
			array(),
			array(
				'user_id'  => self::$editor_id,
				'date_gmt' => 'not a date',
			)
		) );
		$this->assertFalse( wp_set_presence(
			'test/room',
			'client-1',
			array(),
			array(
				'user_id'    => self::$editor_id,
				'expires_in' => 0,
			)
		) );

		add_filter( 'wp_presence_recording_enabled', '__return_false' );
		$this->assertFalse( wp_set_presence( 'test/room', 'client-1', array(), array( 'user_id' => self::$editor_id ) ) );

		$this->assertSame( 0, $listener->get_call_count() );
	}

	/**
	 * The post lock and the collaboration count are the plugin's own rows, in
	 * reserved client IDs, so they are not presence anyone set or removed.
	 *
	 * @covers ::wp_presence_is_reserved_client_id
	 */
	public function test_reserved_rows_fire_nothing() {
		$set     = $this->listen( 'set_presence', 4 );
		$removed = $this->listen( 'removed_presence', 2 );

		$lock = wp_presence_post_lock_client_id();

		$this->assertTrue( wp_set_presence( 'postType/post:1', $lock, array(), array( 'user_id' => self::$editor_id ) ) );
		wp_presence_store_collaboration_state( 'postType/post:1', 2, null );
		$this->assertTrue( wp_remove_presence( 'postType/post:1', $lock ) );
		wp_remove_presence( 'postType/post:1', wp_presence_collaboration_state_client_id() );

		$this->assertSame( 0, $set->get_call_count() );
		$this->assertSame( 0, $removed->get_call_count() );
	}

	/**
	 * @covers ::wp_presence_exchange
	 */
	public function test_exchange_fires_set_presence_once() {
		$listener = $this->listen( 'set_presence', 4 );

		wp_presence_exchange( 'postType/post:1', 'gse-1', array( 'cursor' => 1 ), array( 'user_id' => self::$editor_id ) );

		$this->assertSame( 1, $listener->get_call_count() );
	}

	public function test_removed_presence_fires_once_with_the_room_and_client() {
		wp_set_presence( 'test/room', 'client-1', array(), array( 'user_id' => self::$editor_id ) );

		$listener = $this->listen( 'removed_presence', 2 );

		wp_remove_presence( 'test/room', 'client-1' );

		$this->assertSame( 1, $listener->get_call_count() );
		$this->assertSame( array( 'test/room', 'client-1' ), $listener->get_args()[0] );
	}

	public function test_removed_presence_stays_quiet_when_there_was_no_row() {
		$listener = $this->listen( 'removed_presence', 2 );

		$this->assertTrue( wp_remove_presence( 'test/room', 'nobody' ) );
		$this->assertSame( 0, $listener->get_call_count() );
	}

	/**
	 * @covers ::wp_presence_leave
	 */
	public function test_leave_fires_removed_presence_once() {
		wp_set_presence( 'postType/post:1', 'gse-1', array(), array( 'user_id' => self::$editor_id ) );

		$listener = $this->listen( 'removed_presence', 2 );

		wp_presence_leave( 'postType/post:1', 'gse-1' );

		$this->assertSame( 1, $listener->get_call_count() );
	}

	public function test_removed_user_presence_fires_once_however_many_rooms() {
		wp_set_presence( 'test/room', 'client-1', array(), array( 'user_id' => self::$editor_id ) );
		wp_set_presence( 'postType/post:1', 'client-1', array(), array( 'user_id' => self::$editor_id ) );

		$listener = $this->listen( 'removed_user_presence', 1 );
		$per_row  = $this->listen( 'removed_presence', 2 );

		wp_remove_user_presence( self::$editor_id );

		$this->assertSame( 1, $listener->get_call_count() );
		$this->assertSame( array( self::$editor_id ), $listener->get_args()[0] );
		$this->assertSame( 0, $per_row->get_call_count(), 'One action for the user, none per row.' );
	}

	public function test_removed_user_presence_stays_quiet_when_the_user_had_no_rows() {
		$listener = $this->listen( 'removed_user_presence', 1 );

		$this->assertTrue( wp_remove_user_presence( self::$editor_id ) );
		$this->assertSame( 0, $listener->get_call_count() );
	}
}
