<?php
/**
 * Tests for the Presence API core functions.
 *
 * @package Presence_API
 *
 * @group presence
 *
 * Helpers reached through the public API functions below.
 *
 * @covers ::wp_presence_parse_room
 * @covers ::wp_presence_admin_room
 */
class WP_Test_Presence_Functions extends WP_Presence_UnitTestCase {

	private static $editor_id;
	private static $subscriber_id;

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$editor_id     = $factory->user->create( array( 'role' => 'editor' ) );
		self::$subscriber_id = $factory->user->create( array( 'role' => 'subscriber' ) );
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
		wp_set_presence( 'test/room', 'client-2', array(), self::$editor_id );

		// Manually backdate one entry to simulate expiration.
		$wpdb->update(
			$wpdb->presence,
			array( 'date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 120 ) ),
			array( 'client_id' => 'client-1' ),
			array( '%s' ),
			array( '%s' )
		);

		$entries = wp_get_presence( 'test/room', 60 );

		$this->assertCount( 1, $entries, 'Only the current entry should survive the cutoff.' );
		$this->assertSame( 'client-2', $entries[0]->client_id );
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
		wp_set_presence( 'test/room', 'client-2', array(), self::$editor_id );

		wp_remove_presence( 'test/room', 'client-1' );

		$entries = wp_get_presence( 'test/room' );

		$this->assertCount( 1, $entries, 'Only the named client should be removed.' );
		$this->assertSame( 'client-2', $entries[0]->client_id );
	}

	/**
	 * @covers ::wp_remove_presence
	 */
	public function test_remove_nonexistent_returns_true() {
		$result = wp_remove_presence( 'test/room', 'nonexistent' );

		$this->assertTrue( $result, 'wp_remove_presence should return true even when no row exists.' );
	}

	/**
	 * @covers ::wp_remove_presence
	 * @covers ::wp_presence_admin_room_changed
	 */
	public function test_remove_nonexistent_admin_presence_does_not_announce_a_change() {
		$fired = 0;
		add_action(
			'wp_presence_admin_room_changed',
			function () use ( &$fired ) {
				++$fired;
			}
		);

		$result = wp_remove_presence( wp_presence_admin_room(), 'nonexistent' );

		$this->assertTrue( $result, 'A no-op removal should still report success.' );
		$this->assertSame( 0, $fired, 'A no-op removal must not announce a change.' );
	}

	/**
	 * @covers ::wp_remove_user_presence
	 */
	public function test_remove_user_presence_clears_all_rooms() {
		wp_set_presence( 'admin/online', 'user-' . self::$editor_id, array(), self::$editor_id );
		wp_set_presence( 'postType/post:1', 'lock-' . self::$editor_id, array(), self::$editor_id );
		wp_set_presence( 'admin/online', 'user-' . self::$subscriber_id, array(), self::$subscriber_id );

		wp_remove_user_presence( self::$editor_id );

		$this->assertCount( 0, $this->presence_for_user( self::$editor_id ) );
		$this->assertCount( 1, $this->presence_for_user( self::$subscriber_id ), 'Another user\'s entries should be left alone.' );
	}

	/**
	 * @covers ::wp_remove_user_presence
	 * @covers ::wp_presence_admin_room_changed
	 */
	public function test_remove_user_presence_without_rows_does_not_announce_a_change() {
		$fired = 0;
		add_action(
			'wp_presence_admin_room_changed',
			function () use ( &$fired ) {
				++$fired;
			}
		);

		$result = wp_remove_user_presence( self::$editor_id );

		$this->assertTrue( $result, 'A no-op removal should still report success.' );
		$this->assertSame( 0, $fired, 'A no-op removal must not announce a change.' );
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
	 * @covers ::wp_can_access_presence_room
	 */
	public function test_wp_can_access_presence_room_checks_post_type_capabilities() {
		$author_1 = self::factory()->user->create( array( 'role' => 'author' ) );
		$author_2 = self::factory()->user->create( array( 'role' => 'author' ) );

		$post_id = self::factory()->post->create( array(
			'post_author' => $author_1,
			'post_status' => 'draft',
		) );

		$room = 'postType/post:' . $post_id;

		$this->assertTrue( wp_can_access_presence_room( $room, $author_1 ) );
		$this->assertFalse( wp_can_access_presence_room( $room, $author_2 ) );
		$this->assertTrue( wp_can_access_presence_room( $room, self::$editor_id ) );
	}

	/**
	 * @covers ::wp_delete_expired_presence_data
	 */
	public function test_cleanup_removes_expired_entries() {
		global $wpdb;

		wp_set_presence( 'test/room', 'old-client', array(), self::$editor_id );
		wp_set_presence( 'test/room', 'new-client', array(), self::$editor_id );

		// Backdate one entry past the cutoff the cleanup reads.
		$wpdb->update(
			$wpdb->presence,
			array( 'date_gmt' => gmdate( 'Y-m-d H:i:s', time() - WP_PRESENCE_DEFAULT_TTL - MINUTE_IN_SECONDS ) ),
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
	 * A single invocation deletes at most batch_size * max_passes rows and
	 * leaves the remainder for the next scheduled run.
	 *
	 * @covers ::wp_delete_expired_presence_data
	 */
	public function test_cleanup_is_bounded_per_invocation() {
		global $wpdb;

		// Seed five expired entries.
		for ( $i = 1; $i <= 5; $i++ ) {
			wp_set_presence( 'test/room', "old-{$i}", array(), self::$editor_id );
		}
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->presence} SET date_gmt = %s",
				gmdate( 'Y-m-d H:i:s', time() - WP_PRESENCE_DEFAULT_TTL - MINUTE_IN_SECONDS )
			)
		);

		// Two rows per pass, two passes per run: one run clears at most four.
		$two = static function () {
			return 2;
		};
		add_filter( 'wp_presence_cleanup_batch_size', $two );
		add_filter( 'wp_presence_cleanup_max_passes', $two );

		wp_delete_expired_presence_data();
		$this->assertSame(
			1,
			(int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->presence}" ),
			'One invocation should delete batch_size * max_passes (4) rows and leave the rest.'
		);

		// The next scheduled run clears the remainder.
		wp_delete_expired_presence_data();
		$this->assertSame(
			0,
			(int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->presence}" )
		);

		remove_filter( 'wp_presence_cleanup_batch_size', $two );
		remove_filter( 'wp_presence_cleanup_max_passes', $two );
	}

	/**
	 * Fresh entries are never removed, even with a batch size of one.
	 *
	 * @covers ::wp_delete_expired_presence_data
	 */
	public function test_cleanup_leaves_fresh_entries_untouched() {
		global $wpdb;

		wp_set_presence( 'test/room', 'fresh-1', array(), self::$editor_id );
		wp_set_presence( 'test/room', 'fresh-2', array(), self::$editor_id );

		$one = static function () {
			return 1;
		};
		add_filter( 'wp_presence_cleanup_batch_size', $one );

		wp_delete_expired_presence_data();

		$this->assertSame(
			2,
			(int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->presence}" )
		);

		remove_filter( 'wp_presence_cleanup_batch_size', $one );
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
		wp_set_presence( 'postType/post:1', 'client-1', array(), self::$editor_id );

		$entries = wp_get_presence_by_room_prefix( 'nonexistent/' );

		$this->assertCount( 0, $entries, 'A room under a different prefix should not match.' );
	}

	/**
	 * @covers ::wp_get_presence_by_room_prefix
	 */
	public function test_get_presence_by_room_prefix_filters_expired() {
		global $wpdb;

		wp_set_presence( 'postType/post:1', 'client-1', array(), self::$editor_id );
		wp_set_presence( 'postType/post:2', 'client-2', array(), self::$editor_id );

		$wpdb->update(
			$wpdb->presence,
			array( 'date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 120 ) ),
			array( 'client_id' => 'client-1' ),
			array( '%s' ),
			array( '%s' )
		);

		$entries = wp_get_presence_by_room_prefix( 'postType/', 60 );

		$this->assertCount( 1, $entries, 'Only the current entry should survive the cutoff.' );
		$this->assertSame( 'client-2', $entries[0]->client_id );
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
	 * Query *count* stays flat either way here: the pre-fix version issued a
	 * single SELECT regardless of row count, so comparing num_queries before
	 * and after adding rows can't tell the two implementations apart. What
	 * actually changed is that every query now aggregates in SQL instead of
	 * returning one row per entry, so assert on the query shape instead.
	 *
	 * @covers ::wp_get_presence_summary
	 */
	public function test_get_presence_summary_aggregates_in_sql() {
		global $wpdb;

		wp_set_presence( 'admin/online', 'client-1', array(), self::$editor_id );
		for ( $i = 2; $i <= 20; $i++ ) {
			wp_set_presence( 'postType/post:1', 'client-' . $i, array(), self::$editor_id );
		}

		$queries = array();
		$capture = function ( $query ) use ( &$queries ) {
			$queries[] = $query;
			return $query;
		};

		add_filter( 'query', $capture );
		wp_get_presence_summary();
		remove_filter( 'query', $capture );

		$presence_queries = array_values(
			array_filter(
				$queries,
				function ( $query ) use ( $wpdb ) {
					return false !== strpos( $query, $wpdb->presence );
				}
			)
		);

		$this->assertNotEmpty( $presence_queries, 'Expected at least one query against the presence table.' );

		foreach ( $presence_queries as $query ) {
			$this->assertMatchesRegularExpression(
				'/GROUP BY|COUNT\(/',
				$query,
				"Every presence summary query should aggregate in SQL rather than return one row per entry: {$query}"
			);
		}
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
	public function test_get_active_rooms_orders_by_entry_count_and_deduplicates_users() {
		$editor2_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		wp_set_presence( 'admin/online', 'client-1', array(), self::$editor_id );
		wp_set_presence( 'postType/post:1', 'client-2', array(), self::$editor_id );
		wp_set_presence( 'postType/post:1', 'client-3', array(), self::$editor_id );
		wp_set_presence( 'postType/post:1', 'client-4', array(), $editor2_id );

		$rooms = wp_get_active_rooms();

		$this->assertCount( 2, $rooms );
		$this->assertSame( 'postType/post:1', $rooms[0]['room'] );
		$this->assertSame( 2, $rooms[0]['user_count'] );
		$this->assertSame( 'admin/online', $rooms[1]['room'] );
	}

	/**
	 * @covers ::wp_get_active_rooms
	 */
	public function test_get_active_rooms_empty() {
		$rooms = wp_get_active_rooms();

		$this->assertSame( array(), $rooms );
	}

	/**
	 * @covers ::wp_get_active_rooms
	 */
	public function test_get_active_rooms_omits_deleted_user() {
		$temp_user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_presence( 'admin/online', 'client-temp', array(), $temp_user_id );
		wp_set_presence( 'admin/online', 'client-live', array(), self::$editor_id );

		if ( is_multisite() ) {
			require_once ABSPATH . 'wp-admin/includes/ms.php';
			wpmu_delete_user( $temp_user_id );
		} else {
			wp_delete_user( $temp_user_id );
		}

		$rooms = wp_get_active_rooms();

		$this->assertCount( 1, $rooms );
		$this->assertSame( 1, $rooms[0]['user_count'] );
		$this->assertSame( self::$editor_id, $rooms[0]['users'][0]['user_id'] );
	}

	/**
	 * @covers ::wp_presence_hydrate_room_users
	 */
	public function test_hydrate_room_users() {
		$user_1 = self::factory()->user->create( array( 'role' => 'editor' ) );
		$user_2 = self::factory()->user->create( array( 'role' => 'editor' ) );

		wp_set_presence( 'admin/online', 'client-1', array(), $user_1 );
		wp_set_presence( 'postType/post:1', 'client-2', array(), $user_2 );

		// Get rooms without hydration.
		$rooms = wp_get_active_rooms( WP_PRESENCE_DEFAULT_TTL, false );

		$this->assertCount( 2, $rooms );
		$this->assertArrayNotHasKey( 'users', $rooms[0] );

		// Hydrate just the first room.
		$hydrated = wp_presence_hydrate_room_users( array( $rooms[0] ) );

		$this->assertCount( 1, $hydrated );
		$this->assertArrayHasKey( 'users', $hydrated[0] );
		$this->assertCount( 1, $hydrated[0]['users'] );
		$this->assertSame( $user_1, $hydrated[0]['users'][0]['user_id'] );
	}

	/**
	 * @covers ::wp_get_active_rooms
	 */
	public function test_get_active_rooms_without_hydration() {
		wp_set_presence( 'admin/online', 'client-1', array(), self::$editor_id );

		$rooms = wp_get_active_rooms( WP_PRESENCE_DEFAULT_TTL, false );

		$this->assertCount( 1, $rooms );
		$this->assertArrayHasKey( 'room', $rooms[0] );
		$this->assertArrayHasKey( 'user_count', $rooms[0] );
		$this->assertArrayNotHasKey( 'users', $rooms[0] );
		$this->assertSame( 1, $rooms[0]['user_count'] );
	}

	/**
	 * @covers ::wp_presence_get_timeout
	 */
	public function test_ttl_filter() {
		add_filter( 'wp_presence_default_ttl', fn() => WP_PRESENCE_DEFAULT_TTL * 2 );

		wp_set_presence( 'test/room', 'client-1', array(), self::$editor_id );

		// Beyond the default cutoff, within the filtered one.
		global $wpdb;
		$wpdb->update(
			$wpdb->presence,
			array( 'date_gmt' => gmdate( 'Y-m-d H:i:s', time() - WP_PRESENCE_DEFAULT_TTL - 30 ) ),
			array( 'room' => 'test/room', 'client_id' => 'client-1' )
		);

		$entries = wp_get_presence( 'test/room' );
		$this->assertCount( 1, $entries, 'Entry should be visible with the filtered TTL.' );

		remove_all_filters( 'wp_presence_default_ttl' );

		$entries = wp_get_presence( 'test/room' );
		$this->assertCount( 0, $entries, 'Entry should be expired with the default TTL.' );
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
			(int) get_option( 'wp_presence_db_version' ),
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
			(int) get_option( 'wp_presence_db_version' ),
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

	/**
	 * Everything that mirrors who's online hangs off this action, so an admin
	 * room write has to announce itself. Presence writes land on every tick from
	 * every open tab and most are for other rooms, so the announcement is
	 * confined to the one room that matters.
	 *
	 * @covers ::wp_set_presence
	 * @covers ::wp_presence_admin_room_changed
	 */
	public function test_only_an_admin_room_write_announces_a_change() {
		$fired = 0;
		add_action(
			'wp_presence_admin_room_changed',
			function () use ( &$fired ) {
				++$fired;
			}
		);

		wp_set_presence( 'test/room', 'client-1', array(), self::$editor_id );
		$this->assertSame( 0, $fired, 'A write to another room must stay quiet.' );

		wp_set_presence( wp_presence_admin_room(), 'client-1', array(), self::$editor_id );
		$this->assertSame( 1, $fired );
	}

	/**
	 * The pagehide delete removes a single client from the admin room, and it
	 * has to announce the change too, or a user who closed their tab stays on
	 * screen elsewhere until their entry ages out.
	 *
	 * @covers ::wp_remove_presence
	 * @covers ::wp_presence_admin_room_changed
	 */
	public function test_only_an_admin_room_removal_announces_a_change() {
		wp_set_presence( 'test/room', 'client-1', array(), self::$editor_id );
		wp_set_presence( wp_presence_admin_room(), 'client-1', array(), self::$editor_id );

		$fired = 0;
		add_action(
			'wp_presence_admin_room_changed',
			function () use ( &$fired ) {
				++$fired;
			}
		);

		wp_remove_presence( 'test/room', 'client-1' );
		$this->assertSame( 0, $fired, 'A removal from another room must stay quiet.' );

		wp_remove_presence( wp_presence_admin_room(), 'client-1' );
		$this->assertSame( 1, $fired );
	}

	/**
	 * The avatars overlap, so the first user has to paint on top of the ones
	 * after them rather than under.
	 *
	 * @covers ::wp_presence_render_avatar_stack
	 */
	public function test_avatar_stack_paints_the_first_user_on_top() {
		$html = wp_presence_render_avatar_stack(
			array(
				array(
					'avatar_url'   => 'https://example.com/ana.png',
					'display_name' => 'Ana & Co',
				),
				array(
					'avatar_url'   => 'https://example.com/bo.png',
					'display_name' => 'Bo',
				),
			)
		);

		$this->assertSame( 2, substr_count( $html, '<img ' ) );
		$this->assertLessThan(
			strpos( $html, 'z-index:1' ),
			strpos( $html, 'z-index:2' ),
			'The first avatar in the list needs the highest z-index.'
		);
		$this->assertStringContainsString( 'alt="Ana &amp; Co"', $html );
		$this->assertStringContainsString( 'width="20" height="20"', $html );
	}

	/**
	 * The stack stands in for a crowd rather than showing all of it, so a busy
	 * room renders the same handful of avatars as a quiet one.
	 *
	 * @covers ::wp_presence_render_avatar_stack
	 */
	public function test_avatar_stack_stops_at_the_maximum() {
		$users = array_fill(
			0,
			10,
			array(
				'avatar_url'   => 'https://example.com/ana.png',
				'display_name' => 'Ana',
			)
		);

		$this->assertSame( 4, substr_count( wp_presence_render_avatar_stack( $users ), '<img ' ) );
		$this->assertSame( 2, substr_count( wp_presence_render_avatar_stack( $users, 2 ), '<img ' ) );
	}

	/**
	 * get_avatar_url() returns false with the Show Avatars setting off, and the
	 * Heartbeat render skips those users. A src-less <img> here would paint a
	 * broken avatar until the first tick swept it away.
	 *
	 * @covers ::wp_presence_render_avatar_stack
	 */
	public function test_avatar_stack_skips_a_user_with_no_avatar() {
		$html = wp_presence_render_avatar_stack(
			array(
				array(
					'avatar_url'   => '',
					'display_name' => 'Ana',
				),
				array(
					'avatar_url'   => 'https://example.com/bo.png',
					'display_name' => 'Bo',
				),
				array(
					'avatar_url'   => false,
					'display_name' => 'Cyd',
				),
			)
		);

		$this->assertSame( 1, substr_count( $html, '<img ' ) );
		$this->assertStringNotContainsString( 'Ana', $html );
		$this->assertStringContainsString( 'z-index:1', $html, 'The z-index counts the avatars drawn, not the users passed in.' );
	}

	/**
	 * Asserted at the table rather than only on the return value: a switch that
	 * reports false while the row still lands is the failure that matters.
	 *
	 * @covers ::wp_presence_recording_enabled
	 * @covers ::wp_set_presence
	 */
	public function test_switching_recording_off_writes_nothing() {
		add_filter( 'wp_presence_recording_enabled', '__return_false' );

		$result = wp_set_presence( 'test/room', 'client-1', array(), self::$editor_id );

		$this->assertFalse( $result );
		$this->assertSame( array(), wp_get_presence( 'test/room' ) );
	}

	/**
	 * @covers ::wp_presence_recording_enabled
	 */
	public function test_the_network_can_switch_off_a_site_that_allows_recording() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires multisite.' );
		}

		add_filter( 'wp_presence_recording_enabled', '__return_true' );
		add_filter( 'wp_presence_network_recording_enabled', '__return_false' );

		$this->assertFalse( wp_presence_recording_enabled() );
	}

	/**
	 * The other half of the same rule, and the one a network-level filter could
	 * quietly undo if it were consulted unconditionally.
	 *
	 * @covers ::wp_presence_recording_enabled
	 */
	public function test_a_site_can_switch_off_what_the_network_allows() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires multisite.' );
		}

		add_filter( 'wp_presence_recording_enabled', '__return_false' );
		add_filter( 'wp_presence_network_recording_enabled', '__return_true' );

		$this->assertFalse( wp_presence_recording_enabled() );
	}
}
