<?php
/**
 * Tests for the Presence API core functions.
 *
 * @package Presence_API
 *
 * @group presence
 */
class WP_Test_Presence_Functions extends WP_UnitTestCase {

	private static $editor_id;
	private static $subscriber_id;

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$editor_id     = $factory->user->create( array( 'role' => 'editor' ) );
		self::$subscriber_id = $factory->user->create( array( 'role' => 'subscriber' ) );
	}

	public function tear_down() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "TRUNCATE TABLE {$wpdb->presence}" );
		parent::tear_down();
	}

	/**
	 * @covers ::wp_set_presence
	 */
	public function test_set_presence() {
		$result = wp_set_presence( 'test/room', 'client-1', array( 'action' => 'typing' ), self::$editor_id );

		$this->assertTrue( $result );
	}

	/**
	 * @covers ::wp_get_presence
	 */
	public function test_get_presence_returns_entries() {
		wp_set_presence( 'test/room', 'client-1', array( 'action' => 'typing' ), self::$editor_id );

		$entries = wp_get_presence( 'test/room' );

		$this->assertCount( 1, $entries );
		$this->assertSame( 'client-1', $entries[0]->client_id );
		$this->assertSame( 'typing', $entries[0]->data['action'] );
	}

	/**
	 * @covers ::wp_get_presence
	 */
	public function test_get_presence_filters_by_room() {
		wp_set_presence( 'room/a', 'client-1', array(), self::$editor_id );
		wp_set_presence( 'room/b', 'client-2', array(), self::$editor_id );

		$entries = wp_get_presence( 'room/a' );

		$this->assertCount( 1, $entries );
		$this->assertSame( 'client-1', $entries[0]->client_id );
	}

	/**
	 * @covers ::wp_get_presence
	 */
	public function test_get_presence_filters_expired_entries() {
		global $wpdb;

		wp_set_presence( 'test/room', 'client-1', array(), self::$editor_id );

		// Manually backdate the entry to simulate expiration.
		$wpdb->update(
			$wpdb->presence,
			array( 'date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 120 ) ),
			array( 'client_id' => 'client-1' ),
			array( '%s' ),
			array( '%s' )
		);

		$entries = wp_get_presence( 'test/room', 60 );

		$this->assertCount( 0, $entries );
	}

	/**
	 * @covers ::wp_set_presence
	 */
	public function test_set_presence_upserts() {
		wp_set_presence( 'test/room', 'client-1', array( 'v' => 1 ), self::$editor_id );
		wp_set_presence( 'test/room', 'client-1', array( 'v' => 2 ), self::$editor_id );

		$entries = wp_get_presence( 'test/room' );

		$this->assertCount( 1, $entries );
		$this->assertSame( 2, $entries[0]->data['v'] );
	}

	/**
	 * @covers ::wp_remove_presence
	 */
	public function test_remove_presence() {
		wp_set_presence( 'test/room', 'client-1', array(), self::$editor_id );
		wp_remove_presence( 'test/room', 'client-1' );

		$entries = wp_get_presence( 'test/room' );

		$this->assertCount( 0, $entries );
	}

	/**
	 * @covers ::wp_remove_presence
	 */
	public function test_remove_nonexistent_returns_true() {
		$result = wp_remove_presence( 'test/room', 'nonexistent' );

		$this->assertTrue( $result, 'wp_remove_presence should return true even when no row exists.' );
	}

	/**
	 * @covers ::wp_remove_user_presence
	 */
	public function test_remove_user_presence_clears_all_rooms() {
		wp_set_presence( 'admin/online', 'user-' . self::$editor_id, array(), self::$editor_id );
		wp_set_presence( 'postType/post:1', 'lock-' . self::$editor_id, array(), self::$editor_id );

		wp_remove_user_presence( self::$editor_id );

		$this->assertCount( 0, wp_get_user_presence( self::$editor_id ) );
	}

	/**
	 * @covers ::wp_get_user_presence
	 */
	public function test_get_user_presence_across_rooms() {
		wp_set_presence( 'room/a', 'user-' . self::$editor_id, array(), self::$editor_id );
		wp_set_presence( 'room/b', 'lock-' . self::$editor_id, array(), self::$editor_id );

		$entries = wp_get_user_presence( self::$editor_id );

		$this->assertCount( 2, $entries );
	}

	/**
	 * @covers ::wp_can_access_presence_room
	 */
	public function test_editor_can_access_room() {
		$this->assertTrue( wp_can_access_presence_room( 'test/room', self::$editor_id ) );
	}

	/**
	 * @covers ::wp_can_access_presence_room
	 */
	public function test_subscriber_cannot_access_room() {
		$this->assertFalse( wp_can_access_presence_room( 'test/room', self::$subscriber_id ) );
	}

	/**
	 * @covers ::wp_can_access_presence_room
	 */
	public function test_logged_out_user_cannot_access_room() {
		$this->assertFalse( wp_can_access_presence_room( 'test/room', 0 ) );
	}

	/**
	 * @covers ::wp_delete_expired_presence_data
	 */
	public function test_cleanup_removes_expired_entries() {
		global $wpdb;

		wp_set_presence( 'test/room', 'old-client', array(), self::$editor_id );
		wp_set_presence( 'test/room', 'new-client', array(), self::$editor_id );

		// Backdate one entry.
		$wpdb->update(
			$wpdb->presence,
			array( 'date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 120 ) ),
			array( 'client_id' => 'old-client' ),
			array( '%s' ),
			array( '%s' )
		);

		wp_delete_expired_presence_data();

		$entries = wp_get_presence( 'test/room', 300 );

		$this->assertCount( 1, $entries );
		$this->assertSame( 'new-client', $entries[0]->client_id );
	}

	/**
	 * @covers ::wp_set_presence
	 */
	public function test_multiple_clients_in_room() {
		wp_set_presence( 'test/room', 'client-1', array( 'user' => 'Alice' ), self::$editor_id );
		wp_set_presence( 'test/room', 'client-2', array( 'user' => 'Bob' ), self::$editor_id );

		$entries = wp_get_presence( 'test/room' );

		$this->assertCount( 2, $entries );
	}

	/**
	 * @covers ::wp_get_presence_by_room_prefix
	 */
	public function test_get_presence_by_room_prefix() {
		wp_set_presence( 'postType/post:1', 'client-1', array(), self::$editor_id );
		wp_set_presence( 'postType/post:2', 'client-2', array(), self::$editor_id );
		wp_set_presence( 'admin/online', 'client-3', array(), self::$editor_id );

		$entries = wp_get_presence_by_room_prefix( 'postType/' );

		$this->assertCount( 2, $entries );
	}

	/**
	 * @covers ::wp_get_presence_by_room_prefix
	 */
	public function test_get_presence_by_room_prefix_empty() {
		$entries = wp_get_presence_by_room_prefix( 'nonexistent/' );

		$this->assertCount( 0, $entries );
	}

	/**
	 * @covers ::wp_get_presence_by_room_prefix
	 */
	public function test_get_presence_by_room_prefix_filters_expired() {
		global $wpdb;

		wp_set_presence( 'postType/post:1', 'client-1', array(), self::$editor_id );

		$wpdb->update(
			$wpdb->presence,
			array( 'date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 120 ) ),
			array( 'client_id' => 'client-1' ),
			array( '%s' ),
			array( '%s' )
		);

		$entries = wp_get_presence_by_room_prefix( 'postType/', 60 );

		$this->assertCount( 0, $entries );
	}

	/**
	 * @covers ::wp_get_presence_summary
	 */
	public function test_get_presence_summary() {
		$editor2_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		wp_set_presence( 'admin/online', 'user-' . self::$editor_id, array(), self::$editor_id );
		wp_set_presence( 'admin/online', 'user-' . $editor2_id, array(), $editor2_id );
		wp_set_presence( 'postType/post:1', 'lock-' . self::$editor_id, array(), self::$editor_id );
		wp_set_presence( 'postType/post:2', 'lock-' . $editor2_id, array(), $editor2_id );
		wp_set_presence( 'postType/page:3', 'lock-extra', array(), self::$editor_id );

		$summary = wp_get_presence_summary();

		$this->assertSame( 5, $summary['total_entries'] );
		$this->assertSame( 2, $summary['total_users'] );
		$this->assertArrayHasKey( 'admin', $summary['by_prefix'] );
		$this->assertArrayHasKey( 'postType', $summary['by_prefix'] );
		$this->assertSame( 2, $summary['by_prefix']['admin']['entries'] );
		$this->assertSame( 2, $summary['by_prefix']['admin']['users'] );
		$this->assertSame( 3, $summary['by_prefix']['postType']['entries'] );
		$this->assertSame( 2, $summary['by_prefix']['postType']['users'] );
	}

	/**
	 * @covers ::wp_get_presence_summary
	 */
	public function test_get_presence_summary_empty() {
		$summary = wp_get_presence_summary();

		$this->assertSame( 0, $summary['total_entries'] );
		$this->assertSame( 0, $summary['total_users'] );
		$this->assertEmpty( $summary['by_prefix'] );
	}

	/**
	 * @covers ::wp_presence_post_room
	 */
	public function test_presence_post_room() {
		$post_id = self::factory()->post->create( array( 'post_type' => 'page' ) );

		$this->assertSame( 'postType/page:' . $post_id, wp_presence_post_room( $post_id ) );
	}

	/**
	 * @covers ::wp_presence_post_room
	 */
	public function test_presence_post_room_invalid_post() {
		$this->assertFalse( wp_presence_post_room( 999999 ) );
	}

	/**
	 * @covers ::wp_presence_post_room
	 */
	public function test_presence_post_room_unsupported_post_type() {
		register_post_type( 'no_presence', array( 'public' => true ) );
		$post_id = self::factory()->post->create( array( 'post_type' => 'no_presence' ) );

		$this->assertFalse( wp_presence_post_room( $post_id ) );

		unregister_post_type( 'no_presence' );
	}

	/**
	 * @covers ::wp_get_active_rooms
	 */
	public function test_get_active_rooms() {
		wp_set_presence( 'admin/online', 'client-1', array(), self::$editor_id );
		wp_set_presence( 'postType/post:1', 'client-2', array(), self::$editor_id );

		$rooms = wp_get_active_rooms();

		$this->assertCount( 2, $rooms );
		$this->assertSame( 'admin/online', $rooms[0]['room'] );
		$this->assertSame( 1, $rooms[0]['user_count'] );
		$this->assertArrayHasKey( 'users', $rooms[0] );
	}

	/**
	 * @covers ::wp_get_active_rooms
	 */
	public function test_get_active_rooms_empty() {
		$rooms = wp_get_active_rooms();

		$this->assertSame( array(), $rooms );
	}

	/**
	 * @covers ::wp_presence_get_timeout
	 */
	public function test_ttl_filter() {
		add_filter( 'wp_presence_default_ttl', function () {
			return 120;
		} );

		wp_set_presence( 'test/room', 'client-1', array(), self::$editor_id );

		// Backdate entry to 90 seconds ago — beyond default 60s but within filtered 120s.
		global $wpdb;
		$wpdb->update(
			$wpdb->presence,
			array( 'date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 90 ) ),
			array( 'room' => 'test/room', 'client_id' => 'client-1' )
		);

		$entries = wp_get_presence( 'test/room' );
		$this->assertCount( 1, $entries, 'Entry should be visible with filtered 120s TTL.' );

		remove_all_filters( 'wp_presence_default_ttl' );

		$entries = wp_get_presence( 'test/room' );
		$this->assertCount( 0, $entries, 'Entry should be expired with default 60s TTL.' );
	}

	/**
	 * @covers ::wp_maybe_create_presence_table
	 */
	public function test_schema_migration_on_version_bump() {
		global $wpdb;

		// Simulate an outdated version to trigger a schema upgrade.
		update_option( 'wp_presence_db_version', '0.0' );

		wp_maybe_create_presence_table();

		// After migration, the version should match the current constant.
		$this->assertSame(
			WP_PRESENCE_DB_VERSION,
			get_option( 'wp_presence_db_version' ),
			'Database version option should be updated after migration.'
		);

		// Verify the table still exists and is functional.
		wp_set_presence( 'migration/test', 'client-1', array( 'screen' => 'dashboard' ), self::$editor_id );
		$entries = wp_get_presence( 'migration/test' );
		$this->assertCount( 1, $entries, 'Table should be functional after schema migration.' );

		// Verify the room_date index exists (the one we changed from room(20) to room(40)).
		$indexes = $wpdb->get_results( "SHOW INDEX FROM {$wpdb->presence} WHERE Key_name = 'room_date'" );
		$this->assertNotEmpty( $indexes, 'room_date index should exist after migration.' );
	}

	/**
	 * @covers ::wp_maybe_create_presence_table
	 */
	public function test_schema_migration_skipped_when_current() {
		// Ensure version is current.
		update_option( 'wp_presence_db_version', WP_PRESENCE_DB_VERSION );

		// This should return early without calling dbDelta().
		wp_maybe_create_presence_table();

		$this->assertSame(
			WP_PRESENCE_DB_VERSION,
			get_option( 'wp_presence_db_version' ),
			'Database version should remain unchanged when already current.'
		);
	}

	/**
	 * @covers ::wp_set_presence
	 */
	public function test_set_presence_empty_room() {
		$result = wp_set_presence( '', 'client-1', array(), self::$editor_id );

		// Empty string is a valid varchar value; it should succeed at the DB level.
		$this->assertTrue( $result );

		$entries = wp_get_presence( '' );
		$this->assertCount( 1, $entries );
	}

	/**
	 * @covers ::wp_set_presence
	 */
	public function test_set_presence_long_room_name() {
		$long_room = str_repeat( 'x', 300 );
		$result    = wp_set_presence( $long_room, 'client-1', array(), self::$editor_id );

		// MySQL silently truncates to varchar(191); the insert succeeds.
		$this->assertTrue( $result );
	}

	/**
	 * @covers ::wp_set_presence
	 * @covers ::wp_get_presence
	 */
	public function test_set_presence_preserves_complex_data() {
		$data = array(
			'nested' => array( 'array' => true ),
			'count'  => 42,
		);

		wp_set_presence( 'test/complex', 'client-1', $data, self::$editor_id );
		$entries = wp_get_presence( 'test/complex' );

		$this->assertCount( 1, $entries );
		$this->assertSame( $data, $entries[0]->data );
	}
}
