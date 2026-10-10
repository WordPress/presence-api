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

/**
 * Stands in for the Agent Users plugin's wpai_is_agent_user(), which
 * wp_presence_is_agent_user_via_wpai() defers to when it is loaded.
 *
 * @param int|null $user_id The user ID to report as an agent, or null to
 *                          report nobody as an agent.
 */
function wp_presence_test_wpai_is_agent_user_stub( $user_id ) {
	global $wp_presence_test_wpai_agent_id;
	$wp_presence_test_wpai_agent_id = $user_id;
}

if ( ! function_exists( 'wpai_is_agent_user' ) ) {
	/**
	 * Test stub for the Agent Users plugin's wpai_is_agent_user().
	 *
	 * @param int $user_id The user ID.
	 * @return bool Whether wp_presence_test_wpai_is_agent_user_stub() named this user.
	 */
	function wpai_is_agent_user( $user_id ) {
		global $wp_presence_test_wpai_agent_id;
		return null !== $wp_presence_test_wpai_agent_id && $user_id === $wp_presence_test_wpai_agent_id;
	}
}

class WP_Test_Presence_Functions extends WP_Presence_UnitTestCase {

	private static $editor_id;
	private static $subscriber_id;

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$editor_id     = $factory->user->create( array( 'role' => 'editor' ) );
		self::$subscriber_id = $factory->user->create( array( 'role' => 'subscriber' ) );
	}

	/**
	 * @covers ::wp_set_presence
	 * @covers ::wp_presence_write_row
	 */
	public function test_set_presence() {
		$result = wp_set_presence( 'test/room', 'client-1', array( 'action' => 'typing' ), array( 'user_id' => self::$editor_id ) );

		$this->assertTrue( $result );
	}

	/**
	 * @covers ::wp_get_presence
	 * @covers ::wp_presence_room_rows
	 * @covers ::wp_presence_client_rows
	 * @covers ::wp_presence_is_reserved_client_id
	 */
	public function test_get_presence_returns_entries() {
		wp_set_presence( 'test/room', 'client-1', array( 'action' => 'typing' ), array( 'user_id' => self::$editor_id ) );

		$entries = wp_get_presence( 'test/room' );

		$this->assertCount( 1, $entries );
		$this->assertSame( 'client-1', $entries[0]->client_id );
		$this->assertSame( 'typing', $entries[0]->data['action'] );
	}

	/**
	 * @covers ::wp_get_presence
	 */
	public function test_get_presence_filters_by_room() {
		wp_set_presence( 'room/a', 'client-1', array(), array( 'user_id' => self::$editor_id ) );
		wp_set_presence( 'room/b', 'client-2', array(), array( 'user_id' => self::$editor_id ) );

		$entries = wp_get_presence( 'room/a' );

		$this->assertCount( 1, $entries );
		$this->assertSame( 'client-1', $entries[0]->client_id );
	}

	/**
	 * @covers ::wp_get_presence
	 * @covers ::wp_presence_room_rows
	 */
	public function test_get_presence_filters_by_client_prefix() {
		wp_set_presence( 'postType/post:1', 'editor-' . self::$editor_id, array(), array( 'user_id' => self::$editor_id ) );
		wp_set_presence( 'postType/post:1', 'gse-a', array(), array( 'user_id' => self::$editor_id ) );
		wp_set_presence( 'postType/post:1', 'gse-b', array(), array( 'user_id' => self::$editor_id ) );
		wp_set_presence( 'postType/post:1', wp_presence_collaboration_state_client_id(), array( 'count' => 2 ) );
		wp_set_presence( 'postType/post:2', 'gse-c', array(), array( 'user_id' => self::$editor_id ) );

		$client_ids = wp_list_pluck(
			wp_get_presence(
				'postType/post:1',
				array(
					'timeout'       => WP_PRESENCE_DEFAULT_TTL,
					'client_prefix' => 'gse-',
				)
			),
			'client_id'
		);
		sort( $client_ids );

		$this->assertSame( array( 'gse-a', 'gse-b' ), $client_ids );
	}

	/**
	 * @covers ::wp_presence_cached_rows
	 */
	public function test_get_presence_reads_the_table_once_per_request() {
		global $wpdb;

		wp_set_presence( 'test/room', 'client-1', array(), array( 'user_id' => self::$editor_id ) );

		// The cache key carries the current second, so a pair of reads either side of a tick is repeated rather than counted.
		do {
			$second = time();
			wp_get_presence( 'test/room' );

			$before = $wpdb->num_queries;
			wp_get_presence( 'test/room' );
		} while ( time() !== $second );

		$this->assertSame( $before, $wpdb->num_queries );
	}

	/**
	 * @covers ::wp_get_presence
	 * @covers ::wp_presence_room_rows
	 */
	public function test_get_presence_without_client_prefix_returns_every_client() {
		wp_set_presence( 'postType/post:1', 'editor-' . self::$editor_id, array(), array( 'user_id' => self::$editor_id ) );
		wp_set_presence( 'postType/post:1', 'gse-a', array(), array( 'user_id' => self::$editor_id ) );
		wp_set_presence( 'postType/post:1', wp_presence_collaboration_state_client_id(), array( 'count' => 2 ) );

		$this->assertCount( 2, wp_get_presence( 'postType/post:1', array( 'timeout' => WP_PRESENCE_DEFAULT_TTL ) ) );
	}

	/**
	 * @covers ::wp_get_presence
	 * @covers ::wp_presence_room_rows
	 */
	public function test_get_presence_with_reserved_client_prefix_returns_nothing() {
		wp_set_presence( 'postType/post:1', 'editor-' . self::$editor_id, array(), array( 'user_id' => self::$editor_id ) );
		wp_set_presence( 'postType/post:1', wp_presence_collaboration_state_client_id(), array( 'count' => 2 ) );

		$this->assertSame(
			array(),
			wp_get_presence(
				'postType/post:1',
				array(
					'timeout'       => WP_PRESENCE_DEFAULT_TTL,
					'client_prefix' => WP_PRESENCE_RESERVED_PREFIX,
				)
			)
		);
	}

	/**
	 * @covers ::wp_get_presence
	 * @covers ::wp_presence_room_rows
	 */
	public function test_get_presence_client_prefix_matches_like_wildcards_literally() {
		wp_set_presence( 'test/room', 'a_b-1', array(), array( 'user_id' => self::$editor_id ) );
		wp_set_presence( 'test/room', 'axb-1', array(), array( 'user_id' => self::$editor_id ) );
		wp_set_presence( 'test/room', 'a%b-1', array(), array( 'user_id' => self::$editor_id ) );

		$underscore = wp_get_presence(
			'test/room',
			array(
				'timeout'       => WP_PRESENCE_DEFAULT_TTL,
				'client_prefix' => 'a_b-',
			)
		);
		$percent    = wp_get_presence(
			'test/room',
			array(
				'timeout'       => WP_PRESENCE_DEFAULT_TTL,
				'client_prefix' => 'a%',
			)
		);

		$this->assertSame( array( 'a_b-1' ), wp_list_pluck( $underscore, 'client_id' ) );
		$this->assertSame( array( 'a%b-1' ), wp_list_pluck( $percent, 'client_id' ) );
	}

	/**
	 * @covers ::wp_get_presence
	 * @covers ::wp_presence_room_rows
	 */
	public function test_get_presence_filters_expired_entries() {
		global $wpdb;

		wp_set_presence( 'test/room', 'client-1', array(), array( 'user_id' => self::$editor_id ) );
		wp_set_presence( 'test/room', 'client-2', array(), array( 'user_id' => self::$editor_id ) );

		// Manually backdate one entry to simulate expiration.
		$wpdb->update(
			$wpdb->presence,
			array(
				'date_gmt'    => gmdate( 'Y-m-d H:i:s', time() - 120 ),
				'expires_gmt' => gmdate( 'Y-m-d H:i:s', time() - 120 + 60 ),
			),
			array( 'client_id' => 'client-1' ),
			array( '%s', '%s' ),
			array( '%s' )
		);

		$entries = wp_get_presence( 'test/room', array( 'timeout' => 60 ) );

		$this->assertCount( 1, $entries, 'Only the current entry should survive the cutoff.' );
		$this->assertSame( 'client-2', $entries[0]->client_id );
	}

	/**
	 * The `$args` array, its query string form and the positional form from before 0.17.0 write and read the same rows.
	 *
	 * @covers ::wp_set_presence
	 * @covers ::wp_get_presence
	 * @covers ::wp_presence_exchange
	 * @covers ::wp_presence_leave
	 * @covers ::wp_presence_positional_args
	 */
	public function test_args_query_string_and_positional_forms_match() {
		$this->setExpectedDeprecated( 'wp_set_presence' );
		$this->setExpectedDeprecated( 'wp_get_presence' );
		$this->setExpectedDeprecated( 'wp_presence_exchange' );
		$this->setExpectedDeprecated( 'wp_presence_leave' );

		$user_id = self::factory()->user->create();
		$past    = gmdate( 'Y-m-d H:i:s', time() - 10 );

		wp_set_presence(
			'admin/args',
			'gse-1',
			array( 'a' => 1 ),
			array(
				'user_id'  => $user_id,
				'date_gmt' => $past,
			)
		);
		wp_set_presence( 'admin/positional', 'gse-1', array( 'a' => 1 ), $user_id, $past );
		wp_set_presence( 'admin/positional', 'editor-1', array(), $user_id );
		$fired = array();
		add_action(
			'set_presence',
			static function ( $room, $client_id, $state, $id ) use ( &$fired ) {
				$fired[] = $id;
			},
			10,
			4
		);
		wp_set_presence( 'admin/args', 'editor-1', array(), "user_id={$user_id}" );
		$this->assertSame( array( $user_id ), $fired, 'A query string still hands the action an integer user ID.' );

		foreach ( array( 'admin/args', 'admin/positional' ) as $room ) {
			$rows = wp_get_presence( $room, array( 'client_prefix' => 'gse-' ) );
			$this->assertCount( 1, $rows, $room );
			$this->assertSame( $user_id, (int) $rows[0]->user_id, $room );
			$this->assertSame( $past, $rows[0]->date_gmt, $room );
			$this->assertCount( 2, wp_get_presence( $room ), $room );
		}

		$this->assertEquals( wp_get_presence( 'admin/args', 'client_prefix=gse-' ), wp_get_presence( 'admin/args', null, 'gse-' ) );
		$this->assertEquals( wp_get_presence( 'admin/args', array( 'timeout' => 60 ) ), wp_get_presence( 'admin/args', 60 ) );

		$this->assertCount(
			1,
			wp_presence_exchange(
				'admin/args',
				'gse-2',
				array(),
				array(
					'user_id'       => $user_id,
					'client_prefix' => 'editor-',
				)
			)
		);
		$this->assertCount( 1, wp_presence_exchange( 'admin/positional', 'gse-2', array(), $user_id, null, 'editor-' ) );
		foreach (
			array(
				wp_presence_leave( 'admin/args', 'editor-1', array( 'client_prefix' => 'gse-' ) ),
				wp_presence_leave( 'admin/positional', 'editor-1', null, 'gse-' ),
			) as $left
		) {
			$this->assertContains( 'gse-2', wp_list_pluck( $left, 'client_id' ) );
			$this->assertNotContains( 'editor-1', wp_list_pluck( $left, 'client_id' ) );
		}
	}

	/**
	 * A float window from before 0.17.0 is still a window, not a query string that parses to nothing.
	 *
	 * @covers ::wp_get_presence
	 * @covers ::wp_presence_positional_args
	 */
	public function test_a_positional_float_window_still_bounds_the_read() {
		$this->setExpectedDeprecated( 'wp_get_presence' );

		wp_set_presence( 'admin/float', 'gse-1', array(), array( 'date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 45 ) ) );

		$this->assertCount( 1, wp_get_presence( 'admin/float', 60 ) );
		$this->assertCount( 0, wp_get_presence( 'admin/float', 30.5 ), 'A row 45 seconds old is outside a 30.5-second window.' );
	}

	/**
	 * A stray argument after `$args` cannot turn the array into a user ID and write the row as someone else.
	 *
	 * @covers ::wp_presence_exchange
	 * @covers ::wp_presence_positional_args
	 */
	public function test_an_argument_after_args_leaves_the_args_alone() {
		$user_id = self::factory()->user->create();

		wp_presence_exchange( 'admin/half', 'gse-1', array(), array( 'user_id' => $user_id ), 30 );

		$this->assertSame( $user_id, (int) wp_get_presence( 'admin/half' )[0]->user_id );
	}

	/**
	 * @covers ::wp_set_presence
	 */
	public function test_set_presence_upserts() {
		wp_set_presence( 'test/room', 'client-1', array( 'v' => 1 ), array( 'user_id' => self::$editor_id ) );
		wp_set_presence( 'test/room', 'client-1', array( 'v' => 2 ), array( 'user_id' => self::$editor_id ) );

		$entries = wp_get_presence( 'test/room' );

		$this->assertCount( 1, $entries );
		$this->assertSame( 2, $entries[0]->data['v'] );
	}

	/**
	 * No explicit timestamp still stamps the row with now, unchanged from
	 * before the $date_gmt parameter existed.
	 *
	 * @covers ::wp_set_presence
	 */
	public function test_set_presence_defaults_date_gmt_to_now() {
		$before = gmdate( 'Y-m-d H:i:s' );
		wp_set_presence( 'test/room', 'client-1', array(), array( 'user_id' => self::$editor_id ) );
		$after = gmdate( 'Y-m-d H:i:s' );

		$entries = wp_get_presence( 'test/room' );

		$this->assertGreaterThanOrEqual( $before, $entries[0]->date_gmt );
		$this->assertLessThanOrEqual( $after, $entries[0]->date_gmt );
	}

	/**
	 * A caller relaying awareness on behalf of another client can preserve
	 * that client's own timestamp instead of stamping it with the relay's
	 * clock.
	 *
	 * @covers ::wp_set_presence
	 */
	public function test_set_presence_accepts_an_explicit_past_timestamp() {
		$past = gmdate( 'Y-m-d H:i:s', time() - 60 );

		wp_set_presence(
			'test/room',
			'client-1',
			array(),
			array(
				'user_id'  => self::$editor_id,
				'date_gmt' => $past,
			)
		);

		$entries = wp_get_presence( 'test/room' );

		$this->assertSame( $past, $entries[0]->date_gmt );
	}

	/**
	 * A future timestamp would let a caller pin a row past the TTL
	 * indefinitely, so it is clamped to now instead of trusted as given.
	 *
	 * @covers ::wp_set_presence
	 */
	public function test_set_presence_clamps_a_future_timestamp_to_now() {
		$future = gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS );

		$before = gmdate( 'Y-m-d H:i:s' );
		wp_set_presence(
			'test/room',
			'client-1',
			array(),
			array(
				'user_id'  => self::$editor_id,
				'date_gmt' => $future,
			)
		);
		$after = gmdate( 'Y-m-d H:i:s' );

		$entries = wp_get_presence( 'test/room' );

		$this->assertGreaterThanOrEqual( $before, $entries[0]->date_gmt );
		$this->assertLessThanOrEqual( $after, $entries[0]->date_gmt );
	}

	/**
	 * A string that isn't even shaped like 'Y-m-d H:i:s' must not reach the
	 * database.
	 *
	 * @covers ::wp_set_presence
	 * @covers ::wp_presence_is_valid_date_gmt
	 */
	public function test_set_presence_rejects_a_malformed_date_gmt() {
		$result = wp_set_presence(
			'test/room',
			'client-1',
			array(),
			array(
				'user_id'  => self::$editor_id,
				'date_gmt' => 'not-a-date',
			)
		);

		$this->assertFalse( $result );
		$this->assertCount( 0, wp_get_presence( 'test/room' ) );
	}

	/**
	 * Well-formed but impossible (there is no February 30) is what
	 * wp_checkdate() catches that the shape check alone cannot.
	 *
	 * @covers ::wp_set_presence
	 * @covers ::wp_presence_is_valid_date_gmt
	 */
	public function test_set_presence_rejects_an_impossible_calendar_date() {
		$result = wp_set_presence(
			'test/room',
			'client-1',
			array(),
			array(
				'user_id'  => self::$editor_id,
				'date_gmt' => '2026-02-30 00:00:00',
			)
		);

		$this->assertFalse( $result );
		$this->assertCount( 0, wp_get_presence( 'test/room' ) );
	}

	/**
	 * @covers ::wp_remove_presence
	 */
	public function test_remove_presence() {
		wp_set_presence( 'test/room', 'client-1', array(), array( 'user_id' => self::$editor_id ) );
		wp_set_presence( 'test/room', 'client-2', array(), array( 'user_id' => self::$editor_id ) );

		wp_remove_presence( 'test/room', 'client-1' );

		$entries = wp_get_presence( 'test/room' );

		$this->assertCount( 1, $entries, 'Only the named client should be removed.' );
		$this->assertSame( 'client-2', $entries[0]->client_id );
	}

	/**
	 * Fills a post room with rows from every kind of writer that shares one.
	 */
	private function seed_shared_room() {
		wp_set_presence( 'postType/post:1', 'editor-' . self::$editor_id, array(), array( 'user_id' => self::$editor_id ) );
		wp_set_presence( 'postType/post:1', 'gse-1', array( 'cursor' => 1 ), array( 'user_id' => self::$editor_id ) );
		wp_set_presence( 'postType/post:1', wp_presence_collaboration_state_client_id(), array( 'count' => 2 ) );
	}

	/**
	 * @covers ::wp_presence_exchange
	 */
	public function test_exchange_writes_and_returns_only_the_prefix_asked_for() {
		$this->seed_shared_room();

		$entries = wp_presence_exchange(
			'postType/post:1',
			'gse-2',
			array( 'cursor' => 2 ),
			array(
				'user_id'       => self::$editor_id,
				'timeout'       => WP_PRESENCE_DEFAULT_TTL,
				'client_prefix' => 'gse-',
			)
		);

		$client_ids = wp_list_pluck( $entries, 'client_id' );
		sort( $client_ids );

		$this->assertSame( array( 'gse-1', 'gse-2' ), $client_ids, 'The caller\'s own write is in the set, and nobody else\'s rows are.' );
	}

	/**
	 * @covers ::wp_presence_exchange
	 */
	public function test_exchange_costs_the_write_and_one_read() {
		$this->seed_shared_room();

		$queries = $this->count_presence_queries(
			function () {
				wp_presence_exchange(
					'postType/post:1',
					'gse-1',
					array( 'cursor' => 3 ),
					array(
						'user_id'       => self::$editor_id,
						'timeout'       => WP_PRESENCE_DEFAULT_TTL,
						'client_prefix' => 'gse-',
					)
				);
			}
		);

		$this->assertSame( 2, $queries );
	}

	/**
	 * @covers ::wp_presence_leave
	 */
	public function test_leave_removes_and_returns_only_the_prefix_asked_for() {
		$this->seed_shared_room();
		wp_set_presence( 'postType/post:1', 'gse-2', array(), array( 'user_id' => self::$editor_id ) );

		$entries = wp_presence_leave(
			'postType/post:1',
			'gse-2',
			array(
				'timeout'       => WP_PRESENCE_DEFAULT_TTL,
				'client_prefix' => 'gse-',
			)
		);

		$this->assertSame( array( 'gse-1' ), wp_list_pluck( $entries, 'client_id' ) );
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
		wp_set_presence( 'admin/online', 'user-' . self::$editor_id, array(), array( 'user_id' => self::$editor_id ) );
		wp_set_presence( 'postType/post:1', 'lock-' . self::$editor_id, array(), array( 'user_id' => self::$editor_id ) );
		wp_set_presence( 'admin/online', 'user-' . self::$subscriber_id, array(), array( 'user_id' => self::$subscriber_id ) );
		wp_get_presence( 'admin/online' );

		wp_remove_user_presence( self::$editor_id );

		$this->assertCount( 0, $this->presence_for_user( self::$editor_id ) );
		$this->assertCount( 1, $this->presence_for_user( self::$subscriber_id ), 'Another user\'s entries should be left alone.' );
		$this->assertCount( 1, wp_get_presence( 'admin/online' ), 'The next read should not reuse rows from before the removal.' );
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
	public function test_a_role_that_edits_only_a_custom_post_type_can_access_room() {
		register_post_type(
			'book',
			array(
				'show_ui'         => true,
				'capability_type' => 'book',
				'map_meta_cap'    => true,
			)
		);
		$user = self::factory()->user->create_and_get( array( 'role' => 'subscriber' ) );
		$user->add_cap( 'edit_books' );

		$can_access = wp_can_access_presence_room( 'test/room', $user->ID );
		unregister_post_type( 'book' );

		$this->assertTrue( $can_access );
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

		$post_id = self::factory()->post->create(
			array(
				'post_author' => $author_1,
				'post_status' => 'draft',
			)
		);

		$room = 'postType/post:' . $post_id;

		$this->assertTrue( wp_can_access_presence_room( $room, $author_1 ) );
		$this->assertFalse( wp_can_access_presence_room( $room, $author_2 ) );
		$this->assertTrue( wp_can_access_presence_room( $room, self::$editor_id ) );
		$this->assertFalse( wp_can_access_presence_room( 'postType/page:' . $post_id, self::$editor_id ), 'The room must name the post\'s own type.' );
	}

	/**
	 * @covers ::wp_delete_expired_presence_data
	 */
	public function test_cleanup_removes_expired_entries() {
		global $wpdb;

		wp_set_presence( 'test/room', 'old-client', array(), array( 'user_id' => self::$editor_id ) );
		wp_set_presence( 'test/room', 'new-client', array(), array( 'user_id' => self::$editor_id ) );

		// Backdate one entry past the cutoff the cleanup reads.
		$wpdb->update(
			$wpdb->presence,
			array(
				'date_gmt'    => gmdate( 'Y-m-d H:i:s', time() - WP_PRESENCE_DEFAULT_TTL - MINUTE_IN_SECONDS ),
				'expires_gmt' => gmdate( 'Y-m-d H:i:s', time() - MINUTE_IN_SECONDS ),
			),
			array( 'client_id' => 'old-client' ),
			array( '%s', '%s' ),
			array( '%s' )
		);

		wp_delete_expired_presence_data();

		$entries = wp_get_presence( 'test/room', array( 'timeout' => 300 ) );

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
			wp_set_presence( 'test/room', "old-{$i}", array(), array( 'user_id' => self::$editor_id ) );
		}
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->presence} SET date_gmt = %s, expires_gmt = %s WHERE client_id NOT LIKE %s",
				gmdate( 'Y-m-d H:i:s', time() - WP_PRESENCE_DEFAULT_TTL - MINUTE_IN_SECONDS ),
				gmdate( 'Y-m-d H:i:s', time() - MINUTE_IN_SECONDS ),
				wp_presence_reserved_client_id_pattern()
			)
		);

		// Two rows per pass, two passes per run: one run clears at most four.
		$two = static function () {
			return 2;
		};
		add_filter( 'wp_presence_cleanup_batch_size', $two );
		add_filter( 'wp_presence_cleanup_max_passes', $two );

		wp_delete_expired_presence_data();
		$expected_remaining = 1 + ( wp_using_ext_object_cache() ? 0 : 1 );
		$this->assertSame(
			$expected_remaining,
			(int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->presence}" ),
			'One invocation should delete batch_size * max_passes (4) rows and leave the rest.'
		);

		// The next scheduled run clears the remainder.
		wp_delete_expired_presence_data();
		$this->assertSame(
			wp_using_ext_object_cache() ? 0 : 1,
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

		wp_set_presence( 'test/room', 'fresh-1', array(), array( 'user_id' => self::$editor_id ) );
		wp_set_presence( 'test/room', 'fresh-2', array(), array( 'user_id' => self::$editor_id ) );

		$one = static function () {
			return 1;
		};
		add_filter( 'wp_presence_cleanup_batch_size', $one );

		wp_delete_expired_presence_data();

		$expected = 2 + ( wp_using_ext_object_cache() ? 0 : 1 );
		$this->assertSame(
			$expected,
			(int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->presence}" )
		);

		remove_filter( 'wp_presence_cleanup_batch_size', $one );
	}

	/**
	 * @covers ::wp_set_presence
	 */
	public function test_multiple_clients_in_room() {
		wp_set_presence( 'test/room', 'client-1', array( 'user' => 'Alice' ), array( 'user_id' => self::$editor_id ) );
		wp_set_presence( 'test/room', 'client-2', array( 'user' => 'Bob' ), array( 'user_id' => self::$editor_id ) );

		$entries = wp_get_presence( 'test/room' );

		$this->assertCount( 2, $entries );
	}

	/**
	 * @covers ::wp_get_presence_by_room_prefix
	 */
	public function test_get_presence_by_room_prefix() {
		wp_set_presence( 'postType/post:1', 'client-1', array(), array( 'user_id' => self::$editor_id ) );
		wp_set_presence( 'postType/post:2', 'client-2', array(), array( 'user_id' => self::$editor_id ) );
		wp_set_presence( 'admin/online', 'client-3', array(), array( 'user_id' => self::$editor_id ) );

		$entries = wp_get_presence_by_room_prefix( 'postType/' );

		$this->assertCount( 2, $entries );
	}

	/**
	 * The post list, the admin bar and the Active Posts widget all draw their
	 * avatar stacks from this one read, so a reserved row surfacing here is a
	 * phantom participant on three screens at once.
	 *
	 * @covers ::wp_get_presence_by_room_prefix
	 * @covers ::wp_presence_reserved_client_id_pattern
	 */
	public function test_get_presence_by_room_prefix_leaves_out_reserved_rows() {
		wp_set_presence( 'postType/post:1', 'editor-1', array(), array( 'user_id' => self::$editor_id ) );
		wp_set_presence( 'postType/post:1', wp_presence_collaboration_state_client_id(), array( 'count' => 2 ) );

		$entries = wp_get_presence_by_room_prefix( 'postType/' );

		$this->assertCount( 1, $entries );
		$this->assertSame( 'editor-1', $entries[0]->client_id );
	}

	/**
	 * @covers ::wp_get_presence_by_room_prefix
	 */
	public function test_get_presence_by_room_prefix_empty() {
		wp_set_presence( 'postType/post:1', 'client-1', array(), array( 'user_id' => self::$editor_id ) );

		$entries = wp_get_presence_by_room_prefix( 'nonexistent/' );

		$this->assertCount( 0, $entries, 'A room under a different prefix should not match.' );
	}

	/**
	 * @covers ::wp_get_presence_by_room_prefix
	 */
	public function test_get_presence_by_room_prefix_filters_expired() {
		global $wpdb;

		wp_set_presence( 'postType/post:1', 'client-1', array(), array( 'user_id' => self::$editor_id ) );
		wp_set_presence( 'postType/post:2', 'client-2', array(), array( 'user_id' => self::$editor_id ) );

		$wpdb->update(
			$wpdb->presence,
			array(
				'date_gmt'    => gmdate( 'Y-m-d H:i:s', time() - 120 ),
				'expires_gmt' => gmdate( 'Y-m-d H:i:s', time() - 120 + 60 ),
			),
			array( 'client_id' => 'client-1' ),
			array( '%s', '%s' ),
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

		wp_set_presence( 'admin/online', 'user-' . self::$editor_id, array(), array( 'user_id' => self::$editor_id ) );
		wp_set_presence( 'admin/online', 'user-' . $editor2_id, array(), array( 'user_id' => $editor2_id ) );
		wp_set_presence( 'postType/post:1', 'lock-' . self::$editor_id, array(), array( 'user_id' => self::$editor_id ) );
		wp_set_presence( 'postType/post:2', 'lock-' . $editor2_id, array(), array( 'user_id' => $editor2_id ) );
		wp_set_presence( 'postType/page:3', 'lock-extra', array(), array( 'user_id' => self::$editor_id ) );

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

		wp_set_presence( 'admin/online', 'client-1', array(), array( 'user_id' => self::$editor_id ) );
		for ( $i = 2; $i <= 20; $i++ ) {
			wp_set_presence( 'postType/post:1', 'client-' . $i, array(), array( 'user_id' => self::$editor_id ) );
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
		register_post_type(
			'no_presence',
			array(
				'public'   => true,
				'supports' => array( 'title' ),
			)
		);
		$post_id = self::factory()->post->create( array( 'post_type' => 'no_presence' ) );

		$this->assertFalse( wp_presence_post_room( $post_id ) );

		unregister_post_type( 'no_presence' );
	}

	/**
	 * @covers ::wp_get_active_rooms
	 */
	public function test_get_active_rooms() {
		wp_set_presence( 'admin/online', 'client-1', array(), array( 'user_id' => self::$editor_id ) );
		wp_set_presence( 'postType/post:1', 'client-2', array(), array( 'user_id' => self::$editor_id ) );

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

		wp_set_presence( 'admin/online', 'client-1', array(), array( 'user_id' => self::$editor_id ) );
		wp_set_presence( 'postType/post:1', 'client-2', array(), array( 'user_id' => self::$editor_id ) );
		wp_set_presence( 'postType/post:1', 'client-3', array(), array( 'user_id' => self::$editor_id ) );
		wp_set_presence( 'postType/post:1', 'client-4', array(), array( 'user_id' => $editor2_id ) );

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
		wp_set_presence( 'admin/online', 'client-temp', array(), array( 'user_id' => $temp_user_id ) );
		wp_set_presence( 'admin/online', 'client-live', array(), array( 'user_id' => self::$editor_id ) );

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
	 * @covers ::wp_get_active_rooms
	 */
	public function test_get_active_rooms_hydrates_only_users_within_the_timeout() {
		wp_set_presence( 'admin/online', 'client-idle', array(), array( 'user_id' => self::$subscriber_id ) );
		wp_set_presence( 'admin/online', 'client-live', array(), array( 'user_id' => self::$editor_id ) );
		$this->backdate( 'admin/online', 'client-idle', 2 * MINUTE_IN_SECONDS );

		$rooms = wp_get_active_rooms( MINUTE_IN_SECONDS );

		$this->assertSame( array( self::$editor_id ), wp_list_pluck( $rooms[0]['users'], 'user_id' ) );
	}

	/**
	 * @covers ::wp_presence_hydrate_room_users
	 */
	public function test_hydrate_room_users() {
		$user_1 = self::factory()->user->create( array( 'role' => 'editor' ) );
		$user_2 = self::factory()->user->create( array( 'role' => 'editor' ) );

		wp_set_presence( 'admin/online', 'client-1', array(), array( 'user_id' => $user_1 ) );
		wp_set_presence( 'postType/post:1', 'client-2', array(), array( 'user_id' => $user_2 ) );

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
		wp_set_presence( 'admin/online', 'client-1', array(), array( 'user_id' => self::$editor_id ) );

		$rooms = wp_get_active_rooms( WP_PRESENCE_DEFAULT_TTL, false );

		$this->assertCount( 1, $rooms );
		$this->assertArrayHasKey( 'room', $rooms[0] );
		$this->assertArrayHasKey( 'user_count', $rooms[0] );
		$this->assertArrayNotHasKey( 'users', $rooms[0] );
		$this->assertSame( 1, $rooms[0]['user_count'] );
	}

	/**
	 * Hydration drops the reserved row on its own, since user 0 resolves to
	 * nobody, so the unhydrated count is the one that has to be filtered.
	 *
	 * @covers ::wp_get_active_rooms
	 * @covers ::wp_presence_reserved_client_id_pattern
	 */
	public function test_get_active_rooms_does_not_count_a_reserved_row() {
		wp_set_presence( 'postType/post:1', 'editor-1', array(), array( 'user_id' => self::$editor_id ) );
		wp_set_presence( 'postType/post:1', wp_presence_collaboration_state_client_id(), array( 'count' => 2 ) );

		$rooms = wp_get_active_rooms( WP_PRESENCE_DEFAULT_TTL, false );

		$this->assertCount( 1, $rooms );
		$this->assertSame( 1, $rooms[0]['user_count'] );
	}

	/**
	 * @covers ::wp_presence_get_timeout
	 */
	public function test_ttl_filter() {
		global $wpdb;

		add_filter( 'wp_presence_default_ttl', fn() => WP_PRESENCE_DEFAULT_TTL * 2 );

		wp_set_presence( 'test/room', 'client-1', array(), array( 'user_id' => self::$editor_id ) );

		$row    = $wpdb->get_row( "SELECT date_gmt, expires_gmt FROM {$wpdb->presence} WHERE client_id = 'client-1'" );
		$expiry = $row->expires_gmt;

		$this->assertSame(
			WP_PRESENCE_DEFAULT_TTL * 2,
			strtotime( $expiry . ' UTC' ) - strtotime( $row->date_gmt . ' UTC' ),
			'The filter sets the window a row is written with.'
		);

		// The window belongs to the row: removing the filter leaves it alone.
		remove_all_filters( 'wp_presence_default_ttl' );

		$this->assertCount(
			1,
			wp_get_presence( 'test/room' ),
			'A row already written keeps the window it was written under.'
		);
		$this->assertSame(
			$expiry,
			$wpdb->get_var( "SELECT expires_gmt FROM {$wpdb->presence} WHERE client_id = 'client-1'" ),
			'Reads do not re-age a row against the current filter.'
		);
	}

	/**
	 * @covers ::wp_presence_get_timeout
	 */
	public function test_an_explicit_timeout_ignores_the_ttl_filter() {
		wp_set_presence( 'test/room', 'client-1', array(), array( 'user_id' => self::$editor_id ) );
		$this->backdate( 'test/room', 'client-1', 60 );

		add_filter( 'wp_presence_default_ttl', fn() => 600 );
		$this->assertCount( 0, wp_get_presence( 'test/room', array( 'timeout' => 30 ) ), 'A wider site TTL should not widen a 30 second window.' );

		remove_all_filters( 'wp_presence_default_ttl' );
		add_filter( 'wp_presence_default_ttl', fn() => 10 );
		$this->assertCount( 1, wp_get_presence( 'test/room', array( 'timeout' => 120 ) ), 'A narrower site TTL should not narrow a 120 second window.' );

		remove_all_filters( 'wp_presence_default_ttl' );
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
		wp_set_presence( 'migration/test', 'client-1', array( 'screen' => 'dashboard' ), array( 'user_id' => self::$editor_id ) );
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
		$result = wp_set_presence( '', 'client-1', array(), array( 'user_id' => self::$editor_id ) );

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
		$result    = wp_set_presence( $long_room, 'client-1', array(), array( 'user_id' => self::$editor_id ) );

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

		wp_set_presence( 'test/complex', 'client-1', $data, array( 'user_id' => self::$editor_id ) );
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
	 * @covers ::wp_presence_write_row
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

		wp_set_presence( 'test/room', 'client-1', array(), array( 'user_id' => self::$editor_id ) );
		$this->assertSame( 0, $fired, 'A write to another room must stay quiet.' );

		wp_set_presence( wp_presence_admin_room(), 'client-1', array(), array( 'user_id' => self::$editor_id ) );
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
		wp_set_presence( 'test/room', 'client-1', array(), array( 'user_id' => self::$editor_id ) );
		wp_set_presence( wp_presence_admin_room(), 'client-1', array(), array( 'user_id' => self::$editor_id ) );

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
	 * Avatars are fetched at twice their displayed size so they stay sharp on a 2x display.
	 *
	 * @covers ::wp_presence_get_avatar_fetch_size
	 */
	public function test_avatars_are_fetched_at_twice_their_displayed_size() {
		$this->assertSame( 40, wp_presence_get_avatar_fetch_size( 20 ) );
	}

	/**
	 * @covers ::wp_presence_register_post_type_support
	 * @covers ::wp_presence_add_post_type_support
	 */
	public function test_every_post_type_edited_in_the_admin_supports_presence() {
		$GLOBALS['_wp_presence_post_types_seen'] = array();
		remove_post_type_support( 'post', 'presence' );
		remove_post_type_support( 'page', 'presence' );

		wp_presence_register_post_type_support();
		register_post_type( 'book', array( 'show_ui' => true ) );
		register_post_type(
			'no_editor',
			array(
				'show_ui'  => true,
				'supports' => array( 'title' ),
			)
		);

		$this->assertTrue( post_type_supports( 'post', 'presence' ) );
		$this->assertTrue( post_type_supports( 'page', 'presence' ) );
		$this->assertTrue( post_type_supports( 'book', 'presence' ), 'A type registered after init is covered too.' );
		$this->assertFalse( post_type_supports( 'no_editor', 'presence' ) );
		$this->assertFalse( post_type_supports( 'attachment', 'presence' ) );

		unregister_post_type( 'book' );
		unregister_post_type( 'no_editor' );
	}

	/**
	 * @covers ::wp_presence_add_post_type_support
	 * @covers ::wp_presence_forget_post_type
	 */
	public function test_an_opt_out_survives_the_type_being_registered_again() {
		register_post_type( 'book', array( 'show_ui' => true ) );
		remove_post_type_support( 'book', 'presence' );

		// Core does this to its own types on every locale switch.
		register_post_type( 'book', array( 'show_ui' => true ) );
		$this->assertFalse( post_type_supports( 'book', 'presence' ) );

		unregister_post_type( 'book' );
		register_post_type( 'book', array( 'show_ui' => true ) );
		$this->assertTrue( post_type_supports( 'book', 'presence' ), 'A type unregistered in between starts afresh.' );

		unregister_post_type( 'book' );
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
	 * The documented default, and the one the privacy note in the readme states.
	 *
	 * @covers ::wp_presence_recording_enabled
	 */
	public function test_recording_is_on_with_no_filters_added() {
		$this->assertTrue( wp_presence_recording_enabled() );
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

		$result = wp_set_presence( 'test/room', 'client-1', array(), array( 'user_id' => self::$editor_id ) );

		$this->assertFalse( $result );
		$this->assertSame( array(), wp_get_presence( 'test/room' ) );
	}

	/**
	 * A reserved row is bookkeeping, such as a post lock, which a site keeps
	 * whether or not it records who is where.
	 *
	 * @covers ::wp_set_presence
	 */
	public function test_a_reserved_row_is_written_with_recording_off() {
		add_filter( 'wp_presence_recording_enabled', '__return_false' );

		$result = wp_set_presence( 'test/room', '_bookkeeping', array( 'count' => 1 ) );

		$this->assertTrue( $result );
		$this->assertCount( 1, wp_presence_room_rows( 'test/room', null, '_bookkeeping' ) );
		$this->assertSame( array(), wp_get_presence( 'test/room' ), 'A reserved row is still not a participant.' );
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

	/**
	 * The switch an owner reaches without writing PHP.
	 *
	 * @covers ::wp_presence_recording_enabled
	 */
	public function test_the_stored_option_switches_recording_off() {
		update_option( 'wp_presence_recording', '0' );

		$this->assertFalse( wp_presence_recording_enabled() );
	}

	/**
	 * The option is the filter's default, not a second opinion, so code still
	 * has the last word over whatever the checkbox says.
	 *
	 * @covers ::wp_presence_recording_enabled
	 */
	public function test_a_filter_overrides_the_stored_option() {
		update_option( 'wp_presence_recording', '0' );
		add_filter( 'wp_presence_recording_enabled', '__return_true' );

		$this->assertTrue( wp_presence_recording_enabled() );
	}

	/**
	 * @covers ::wp_presence_recording_enabled
	 */
	public function test_the_network_option_switches_off_a_site_that_allows_recording() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires multisite.' );
		}

		update_option( 'wp_presence_recording', '1' );
		update_site_option( 'wp_presence_network_recording', '0' );

		$this->assertFalse( wp_presence_recording_enabled() );
	}

	/**
	 * @covers ::wp_presence_is_available
	 */
	public function test_presence_is_available_with_a_table_and_recording_on() {
		$this->assertTrue( wp_presence_is_available() );
	}

	/**
	 * @covers ::wp_presence_is_available
	 */
	public function test_presence_is_unavailable_with_recording_off() {
		add_filter( 'wp_presence_recording_enabled', '__return_false' );

		$this->assertFalse( wp_presence_is_available() );
	}

	/**
	 * The case a function_exists() sniff misses: the plugin is loaded, but the
	 * site was never provisioned. Filtered rather than deleted, because the
	 * base class truncates in tear_down() and TRUNCATE implicitly commits.
	 *
	 * @covers ::wp_presence_is_available
	 */
	public function test_presence_is_unavailable_without_a_table() {
		add_filter( 'option_wp_presence_db_version', '__return_zero' );

		$this->assertFalse( wp_presence_is_available() );
	}

	/**
	 * A writer that knows when its clients leave, such as one relaying a
	 * socket's lifetime, keeps a row present without re-stamping it.
	 *
	 * @covers ::wp_set_presence
	 * @covers ::wp_get_presence
	 */
	public function test_a_row_written_with_its_own_window_outlives_the_site_ttl() {
		$this->assertTrue(
			wp_set_presence(
				'test/room',
				'relay-1',
				array(),
				array(
					'user_id'    => self::$editor_id,
					'expires_in' => HOUR_IN_SECONDS,
				)
			)
		);

		// Last heard from well over an ordinary TTL ago, and never re-stamped.
		global $wpdb;
		$wpdb->update(
			$wpdb->presence,
			array( 'date_gmt' => gmdate( 'Y-m-d H:i:s', time() - ( WP_PRESENCE_DEFAULT_TTL * 4 ) ) ),
			array( 'client_id' => 'relay-1' ),
			array( '%s' ),
			array( '%s' )
		);

		$this->assertCount(
			1,
			wp_get_presence( 'test/room' ),
			'The row is present until its own expiry, with no writes in between.'
		);

		wp_delete_expired_presence_data();

		$this->assertCount( 1, wp_get_presence( 'test/room' ), 'Cleanup leaves a row that has not expired.' );
	}

	/**
	 * The departure is still the signal: removal takes effect at once.
	 *
	 * @covers ::wp_remove_presence
	 */
	public function test_removal_is_immediate_whatever_the_window() {
		wp_set_presence(
			'test/room',
			'relay-1',
			array(),
			array(
				'user_id'    => self::$editor_id,
				'expires_in' => HOUR_IN_SECONDS,
			)
		);
		wp_remove_presence( 'test/room', 'relay-1' );

		$this->assertCount( 0, wp_get_presence( 'test/room' ) );
	}

	/**
	 * A window that passes is the backstop for a departure that never arrived.
	 *
	 * @covers ::wp_get_presence
	 * @covers ::wp_delete_expired_presence_data
	 */
	public function test_a_row_expires_on_its_own_window_even_when_recently_stamped() {
		global $wpdb;

		wp_set_presence(
			'test/room',
			'relay-1',
			array(),
			array(
				'user_id'    => self::$editor_id,
				'expires_in' => HOUR_IN_SECONDS,
			)
		);

		// The expiry passes while the timestamp stays recent: the guarantee is
		// the window, not how lately the writer was heard from.
		$wpdb->update(
			$wpdb->presence,
			array( 'expires_gmt' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ),
			array( 'client_id' => 'relay-1' ),
			array( '%s' ),
			array( '%s' )
		);

		$this->assertCount( 0, wp_get_presence( 'test/room' ), 'A passed expiry ends the row.' );

		wp_delete_expired_presence_data();

		$this->assertSame(
			'0',
			$wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->presence} WHERE client_id = 'relay-1'" ),
			'Cleanup collects it.'
		);
	}

	/**
	 * A writer that names no window gets the site TTL, filter included, which
	 * is what every heartbeat-backed writer wants.
	 *
	 * @covers ::wp_presence_expiry_for
	 */
	public function test_a_row_without_a_window_expires_on_the_site_ttl() {
		add_filter( 'wp_presence_default_ttl', fn() => 300 );

		wp_set_presence( 'test/room', 'client-1', array(), array( 'user_id' => self::$editor_id ) );

		$this->assertSame(
			300,
			strtotime( $this->stored_expires_gmt( 'test/room', 'client-1' ) . ' UTC' )
				- strtotime( $this->stored_date_gmt( 'test/room', 'client-1' ) . ' UTC' ),
			'The window is the filtered TTL, measured from the row timestamp.'
		);
	}

	/**
	 * @covers ::wp_presence_expiry_for
	 * @covers ::wp_presence_max_expires_in
	 */
	public function test_the_window_is_capped() {
		add_filter( 'wp_presence_max_expires_in', fn() => MINUTE_IN_SECONDS );

		wp_set_presence(
			'test/room',
			'relay-1',
			array(),
			array(
				'user_id'    => self::$editor_id,
				'expires_in' => WEEK_IN_SECONDS,
			)
		);

		global $wpdb;
		$date_gmt = $wpdb->get_var( "SELECT date_gmt FROM {$wpdb->presence} WHERE client_id = 'relay-1'" );

		$this->assertSame(
			MINUTE_IN_SECONDS,
			strtotime( $this->stored_expires_gmt( 'test/room', 'relay-1' ) . ' UTC' ) - strtotime( $date_gmt . ' UTC' ),
			'A window beyond the ceiling is trimmed to it.'
		);
	}

	/**
	 * @covers ::wp_set_presence
	 */
	public function test_an_unusable_window_is_refused() {
		$this->assertFalse(
			wp_set_presence(
				'test/room',
				'relay-1',
				array(),
				array(
					'user_id'    => self::$editor_id,
					'expires_in' => 0,
				)
			)
		);
		$this->assertFalse(
			wp_set_presence(
				'test/room',
				'relay-1',
				array(),
				array(
					'user_id'    => self::$editor_id,
					'expires_in' => -5,
				)
			)
		);
		$this->assertFalse(
			wp_set_presence(
				'test/room',
				'relay-1',
				array(),
				array(
					'user_id'    => self::$editor_id,
					'expires_in' => 'soon',
				)
			)
		);
		$this->assertCount( 0, wp_get_presence( 'test/room' ) );
	}

	/**
	 * The guard skips unchanged writes, which would now also skip the extension.
	 *
	 * @covers ::wp_presence_refresh_cutoff
	 */
	public function test_an_explicit_window_is_never_swallowed_by_the_redundant_write_guard() {
		wp_set_presence( 'test/room', 'relay-1', array( 'a' => 1 ), array( 'user_id' => self::$editor_id ) );

		$before = $this->stored_expires_gmt( 'test/room', 'relay-1' );

		// Same state, inside the refresh window: an ordinary write is skipped.
		wp_set_presence( 'test/room', 'relay-1', array( 'a' => 1 ), array( 'user_id' => self::$editor_id ) );
		$this->assertSame( $before, $this->stored_expires_gmt( 'test/room', 'relay-1' ) );

		// The same state with a window is the caller asking for the extension.
		wp_set_presence(
			'test/room',
			'relay-1',
			array( 'a' => 1 ),
			array(
				'user_id'    => self::$editor_id,
				'expires_in' => HOUR_IN_SECONDS,
			)
		);

		$this->assertGreaterThan(
			strtotime( $before . ' UTC' ),
			strtotime( $this->stored_expires_gmt( 'test/room', 'relay-1' ) . ' UTC' ),
			'An explicit window extends the row.'
		);
	}

	/**
	 * Rows written before the column existed carry its zero default, so they
	 * read as expired and the cleanup already scheduled collects them. Nobody
	 * is left stranded: a client still present is back on its next ping, which
	 * is why the upgrade needs no migration step of its own.
	 *
	 * @covers ::wp_get_presence
	 * @covers ::wp_delete_expired_presence_data
	 */
	public function test_a_row_from_before_the_column_expires_and_is_collected() {
		global $wpdb;

		wp_set_presence( 'test/room', 'client-1', array(), array( 'user_id' => self::$editor_id ) );
		$wpdb->query( "UPDATE {$wpdb->presence} SET expires_gmt = '0000-00-00 00:00:00'" );

		$this->assertCount( 0, wp_get_presence( 'test/room' ), 'It reads as expired.' );

		wp_delete_expired_presence_data();

		$this->assertSame(
			'0',
			$wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->presence}" ),
			'The scheduled cleanup collects it without a migration.'
		);

		// And the client is present again as soon as it pings.
		wp_set_presence( 'test/room', 'client-1', array(), array( 'user_id' => self::$editor_id ) );
		$this->assertCount( 1, wp_get_presence( 'test/room' ) );
	}

	/**
	 * The expiry rides the same test as the timestamp, so a write that is taken
	 * moves both and the row never outlives the activity behind it.
	 *
	 * @covers ::wp_presence_write_row
	 */
	public function test_a_write_past_the_refresh_cutoff_moves_the_expiry_with_it() {
		wp_set_presence( 'test/room', 'client-1', array( 'a' => 1 ), array( 'user_id' => self::$editor_id ) );
		$this->backdate( 'test/room', 'client-1', 60 );

		$before = $this->stored_expires_gmt( 'test/room', 'client-1' );

		wp_set_presence( 'test/room', 'client-1', array( 'a' => 1 ), array( 'user_id' => self::$editor_id ) );

		$this->assertGreaterThan(
			strtotime( $before . ' UTC' ),
			strtotime( $this->stored_expires_gmt( 'test/room', 'client-1' ) . ' UTC' ),
			'A refreshed row carries a new expiry.'
		);
	}

	/**
	 * Reads the stored expiry for a row, as the raw string the column holds.
	 */
	private function stored_expires_gmt( $room, $client_id ) {
		global $wpdb;

		return $wpdb->get_var(
			$wpdb->prepare(
				"SELECT expires_gmt FROM {$wpdb->presence} WHERE room = %s AND client_id = %s",
				$room,
				$client_id
			)
		);
	}

	/**
	 * Reads the stored timestamp for a row, as the raw string the column holds.
	 */
	private function stored_date_gmt( $room, $client_id ) {
		global $wpdb;

		return $wpdb->get_var(
			$wpdb->prepare(
				"SELECT date_gmt FROM {$wpdb->presence} WHERE room = %s AND client_id = %s",
				$room,
				$client_id
			)
		);
	}

	/**
	 * Backdates a row so the refresh window can be crossed without waiting.
	 */
	private function backdate( $room, $client_id, $seconds ) {
		global $wpdb;

		$wpdb->update(
			$wpdb->presence,
			array(
				'date_gmt'    => gmdate( 'Y-m-d H:i:s', time() - $seconds ),
				'expires_gmt' => gmdate( 'Y-m-d H:i:s', time() - $seconds + wp_presence_get_timeout( WP_PRESENCE_DEFAULT_TTL ) ),
			),
			array(
				'room'      => $room,
				'client_id' => $client_id,
			),
			array( '%s', '%s' ),
			array( '%s', '%s' )
		);
	}

	/**
	 * @covers ::wp_set_presence
	 * @covers ::wp_presence_refresh_cutoff
	 */
	public function test_unchanged_state_within_the_refresh_window_skips_the_write() {
		wp_set_presence( 'test/room', 'client-1', array( 'action' => 'editing' ), array( 'user_id' => self::$editor_id ) );
		$this->backdate( 'test/room', 'client-1', 5 );

		$before = $this->stored_date_gmt( 'test/room', 'client-1' );
		$result = wp_set_presence( 'test/room', 'client-1', array( 'action' => 'editing' ), array( 'user_id' => self::$editor_id ) );

		$this->assertTrue( $result, 'Presence is still recorded, so a skipped write reports success.' );
		$this->assertSame(
			$before,
			$this->stored_date_gmt( 'test/room', 'client-1' ),
			'An unchanged state inside the refresh window must not touch the row.'
		);
	}

	/**
	 * @covers ::wp_set_presence
	 * @covers ::wp_presence_refresh_cutoff
	 */
	public function test_unchanged_state_past_the_refresh_window_writes() {
		wp_set_presence( 'test/room', 'client-1', array( 'action' => 'editing' ), array( 'user_id' => self::$editor_id ) );
		$this->backdate( 'test/room', 'client-1', WP_PRESENCE_DEFAULT_TTL - 5 );

		$before = $this->stored_date_gmt( 'test/room', 'client-1' );
		wp_set_presence( 'test/room', 'client-1', array( 'action' => 'editing' ), array( 'user_id' => self::$editor_id ) );

		$this->assertNotSame(
			$before,
			$this->stored_date_gmt( 'test/room', 'client-1' ),
			'A row close to the cutoff must be refreshed even when the state is identical.'
		);
	}

	/**
	 * @covers ::wp_set_presence
	 * @covers ::wp_presence_refresh_cutoff
	 */
	public function test_changed_state_writes_inside_the_refresh_window() {
		wp_set_presence( 'test/room', 'client-1', array( 'action' => 'editing' ), array( 'user_id' => self::$editor_id ) );
		$this->backdate( 'test/room', 'client-1', 5 );

		$before = $this->stored_date_gmt( 'test/room', 'client-1' );
		wp_set_presence( 'test/room', 'client-1', array( 'action' => 'idle' ), array( 'user_id' => self::$editor_id ) );

		$this->assertNotSame(
			$before,
			$this->stored_date_gmt( 'test/room', 'client-1' ),
			'A state change is the thing the guard must never swallow.'
		);

		$entries = wp_get_presence( 'test/room' );
		$this->assertSame( 'idle', $entries[0]->data['action'] );
	}

	/**
	 * An explicit timestamp is how a relay backdates a collaborator who has
	 * since left; the redundant-write guard has no way to know that, so an
	 * explicit $date_gmt must bypass it rather than be silently swallowed.
	 *
	 * @covers ::wp_set_presence
	 * @covers ::wp_presence_refresh_cutoff
	 */
	public function test_explicit_timestamp_bypasses_the_redundant_write_guard() {
		wp_set_presence( 'test/room', 'client-1', array( 'action' => 'editing' ), array( 'user_id' => self::$editor_id ) );
		$this->backdate( 'test/room', 'client-1', 5 );

		$past = gmdate( 'Y-m-d H:i:s', time() - 60 );
		wp_set_presence(
			'test/room',
			'client-1',
			array( 'action' => 'editing' ),
			array(
				'user_id'  => self::$editor_id,
				'date_gmt' => $past,
			)
		);

		$this->assertSame(
			$past,
			$this->stored_date_gmt( 'test/room', 'client-1' ),
			'An explicit timestamp must land even when the guard would otherwise skip an unchanged state.'
		);
	}

	/**
	 * @covers ::wp_set_presence
	 * @covers ::wp_presence_refresh_cutoff
	 */
	public function test_skipped_write_does_not_announce_an_admin_room_change() {
		if ( $GLOBALS['wpdb'] instanceof WP_SQLite_DB ) {
			$this->markTestSkipped( 'The SQLite driver reports an unchanged upsert as one affected row, where MySQL reports none.' );
		}

		$room = wp_presence_admin_room();

		wp_set_presence( $room, 'user-' . self::$editor_id, array( 'screen' => 'dashboard' ), array( 'user_id' => self::$editor_id ) );
		$this->backdate( $room, 'user-' . self::$editor_id, 5 );

		// Under WP_MULTISITE=1 this class does not provision the summary table, so
		// wp_presence_network_summary_push_is_due() is false and the guard is free
		// to skip. The network suite covers the case where a push is owed.
		if ( function_exists( 'wp_presence_network_summary_push_is_due' ) ) {
			$this->assertFalse( wp_presence_network_summary_push_is_due(), 'Precondition: no push is owed here.' );
		}

		$fired = 0;
		add_action(
			'wp_presence_admin_room_changed',
			static function () use ( &$fired ) {
				++$fired;
			}
		);

		wp_set_presence( $room, 'user-' . self::$editor_id, array( 'screen' => 'dashboard' ), array( 'user_id' => self::$editor_id ) );

		$this->assertSame( 0, $fired, 'Nobody arrived or left, so no surface needs to re-render.' );
	}

	/**
	 * Counts INSERT statements against the presence table during a callback.
	 */
	private function count_presence_inserts( callable $during ) {
		global $wpdb;

		$count = 0;
		$table = $wpdb->presence;

		$counter = static function ( $query ) use ( &$count, $table ) {
			if ( 0 === strpos( ltrim( $query ), 'INSERT' ) && false !== strpos( $query, $table ) && ! self::is_version_bump( $query ) ) {
				++$count;
			}

			return $query;
		};

		add_filter( 'query', $counter );
		$during();
		remove_filter( 'query', $counter );

		return $count;
	}

	/**
	 * @covers ::wp_presence_refresh_threshold
	 * @covers ::wp_set_presence
	 * @covers ::wp_presence_refresh_cutoff
	 */
	public function test_a_ttl_below_the_tick_gap_leaves_no_room_to_skip() {
		add_filter(
			'wp_presence_default_ttl',
			static function () {
				return 60;
			}
		);

		$this->assertSame( 0, wp_presence_refresh_threshold(), 'A 60s TTL against a 120s gap cannot afford to skip anything.' );

		wp_set_presence( 'test/room', 'client-1', array( 'action' => 'editing' ), array( 'user_id' => self::$editor_id ) );
		$this->backdate( 'test/room', 'client-1', 1 );

		$before = $this->stored_date_gmt( 'test/room', 'client-1' );
		wp_set_presence( 'test/room', 'client-1', array( 'action' => 'editing' ), array( 'user_id' => self::$editor_id ) );

		$this->assertNotSame( $before, $this->stored_date_gmt( 'test/room', 'client-1' ) );
	}

	/**
	 * The zero threshold is the only case the early return decides on its own:
	 * at any age above zero the comparison already refuses to skip.
	 *
	 * @covers ::wp_set_presence
	 * @covers ::wp_presence_refresh_cutoff
	 */
	public function test_a_zero_threshold_writes_again_inside_the_same_second() {
		add_filter(
			'wp_presence_default_ttl',
			static function () {
				return 60;
			}
		);

		wp_set_presence( 'test/room', 'client-1', array( 'action' => 'editing' ), array( 'user_id' => self::$editor_id ) );

		$inserts = $this->count_presence_inserts(
			function () {
				wp_set_presence( 'test/room', 'client-1', array( 'action' => 'editing' ), array( 'user_id' => self::$editor_id ) );
			}
		);

		$this->assertSame( 1, $inserts, 'A row written this same second is still owed its refresh.' );
	}

	/**
	 * Whether a query writes a room's version row, which the version tests count on their own.
	 */
	private static function is_version_bump( $query ) {
		return false !== strpos( $query, "'" . wp_presence_version_client_id() . "'" ) && false !== stripos( $query, 'ON DUPLICATE KEY UPDATE' );
	}

	/**
	 * Counts every query against the presence table during a callback, other than a room version bump.
	 */
	private function count_presence_queries( callable $during ) {
		global $wpdb;

		$count = 0;
		$table = $wpdb->presence;

		$counter = static function ( $query ) use ( &$count, $table ) {
			if ( false !== strpos( $query, $table ) && ! self::is_version_bump( $query ) ) {
				++$count;
			}

			return $query;
		};

		add_filter( 'query', $counter );
		$during();
		remove_filter( 'query', $counter );

		return $count;
	}

	/**
	 * The upsert decides redundancy itself, so no SELECT runs ahead of it.
	 *
	 * @covers ::wp_set_presence
	 * @covers ::wp_presence_refresh_cutoff
	 * @covers ::wp_presence_write_row
	 */
	public function test_a_write_costs_one_query_whether_or_not_it_is_redundant() {
		wp_set_presence( 'test/room', 'client-1', array( 'action' => 'editing' ), array( 'user_id' => self::$editor_id ) );
		$this->backdate( 'test/room', 'client-1', 5 );

		$before = $this->stored_date_gmt( 'test/room', 'client-1' );

		$redundant = $this->count_presence_queries(
			function () {
				wp_set_presence( 'test/room', 'client-1', array( 'action' => 'editing' ), array( 'user_id' => self::$editor_id ) );
			}
		);

		$this->assertSame( 1, $redundant, 'An unchanged state inside the refresh window.' );
		$this->assertSame( $before, $this->stored_date_gmt( 'test/room', 'client-1' ), 'The upsert still has to leave date_gmt alone.' );

		$changed = $this->count_presence_queries(
			function () {
				wp_set_presence( 'test/room', 'client-1', array( 'action' => 'idle' ), array( 'user_id' => self::$editor_id ) );
			}
		);

		$this->assertSame( 1, $changed, 'A changed state.' );
	}

	/**
	 * The SQLite integration's IF() always takes the false branch, so a
	 * refresh built on it never moves date_gmt there (#616).
	 *
	 * @covers ::wp_presence_write_row
	 */
	public function test_the_refresh_upsert_avoids_if() {
		wp_set_presence( 'test/room', 'client-1', array( 'action' => 'editing' ), array( 'user_id' => self::$editor_id ) );

		$upserts = array();
		$capture = static function ( $query ) use ( &$upserts ) {
			if ( false !== stripos( $query, 'ON DUPLICATE KEY UPDATE' ) && ! self::is_version_bump( $query ) ) {
				$upserts[] = $query;
			}

			return $query;
		};

		add_filter( 'query', $capture );
		wp_set_presence( 'test/room', 'client-1', array( 'action' => 'editing' ), array( 'user_id' => self::$editor_id ) );
		remove_filter( 'query', $capture );

		$this->assertCount( 1, $upserts );
		$this->assertStringContainsString( 'date_gmt <', $upserts[0], 'The write has to have taken the refresh path.' );
		$this->assertDoesNotMatchRegularExpression( '/\bIF\s*\(/i', $upserts[0] );
	}

	/**
	 * @covers ::wp_presence_next_tick_gap
	 */
	public function test_the_tick_gap_assumes_a_blurred_window_unless_the_request_says_otherwise() {
		$this->assertSame( 120, wp_presence_next_tick_gap(), 'Nothing outside a Heartbeat request says how long the gap is.' );

		$_POST['interval']  = '10';
		$_POST['has_focus'] = 'false';

		$this->assertSame( 120, wp_presence_next_tick_gap(), 'A blurred tab returns in 120s whatever interval it reports.' );

		$_POST['has_focus'] = 'true';

		$this->assertSame( 10, wp_presence_next_tick_gap() );

		$_POST['interval'] = '0';

		$this->assertSame( 120, wp_presence_next_tick_gap(), 'An unusable interval falls back rather than skipping forever.' );

		unset( $_POST['interval'], $_POST['has_focus'] );
	}

	/**
	 * A focused tab has TTL to spare, which is exactly when a row can sit
	 * unwritten long enough to read as an absent user.
	 *
	 * @covers ::wp_presence_refresh_threshold
	 * @covers ::wp_presence_max_staleness
	 */
	public function test_the_refresh_threshold_stops_at_the_staleness_cap() {
		$_POST['interval']  = '15';
		$_POST['has_focus'] = 'true';

		$this->assertSame( wp_presence_max_staleness(), wp_presence_refresh_threshold(), 'The TTL leaves room to skip for longer than a reader can tell a skipped write from an absent client.' );

		unset( $_POST['interval'], $_POST['has_focus'] );
	}

	/**
	 * @covers ::wp_presence_max_staleness
	 */
	public function test_the_staleness_cap_matches_the_figure_the_readme_publishes() {
		$this->assertSame( 30, wp_presence_max_staleness(), 'README.md tells consumers how far behind date_gmt can be, so the number has to change in both places.' );
	}

	/**
	 * @covers ::wp_presence_idle_threshold
	 */
	public function test_the_idle_threshold_follows_the_heartbeat_backoff() {
		add_filter(
			'wp_presence_heartbeat_idle_interval',
			static function () {
				return 90;
			}
		);

		$this->assertSame( wp_presence_max_staleness() + 90, wp_presence_idle_threshold(), 'A client told to back off further has to stay active for longer before it counts as idle.' );
	}

	/**
	 * @covers ::wp_presence_is_agent_user
	 */
	public function test_is_agent_user_defaults_to_false() {
		$this->assertFalse( wp_presence_is_agent_user( self::$editor_id ), 'With nothing marking a user as an agent, everyone is a person.' );
	}

	/**
	 * @covers ::wp_presence_is_agent_user
	 */
	public function test_is_agent_user_reads_the_filter() {
		$mark_agent = static function ( $is_agent, $user_id ) {
			return self::$editor_id === $user_id ? true : $is_agent;
		};
		add_filter( 'wp_presence_is_agent_user', $mark_agent, 10, 2 );

		$this->assertTrue( wp_presence_is_agent_user( self::$editor_id ) );
		$this->assertFalse( wp_presence_is_agent_user( self::$subscriber_id ) );

		remove_filter( 'wp_presence_is_agent_user', $mark_agent, 10 );
	}

	/**
	 * @covers ::wp_presence_is_agent_user
	 * @covers ::wp_presence_is_agent_user_via_wpai
	 */
	public function test_is_agent_user_defers_to_wpai_is_agent_user_when_it_exists() {
		wp_presence_test_wpai_is_agent_user_stub( self::$editor_id );

		$this->assertTrue( wp_presence_is_agent_user( self::$editor_id ) );
		$this->assertFalse( wp_presence_is_agent_user( self::$subscriber_id ) );

		wp_presence_test_wpai_is_agent_user_stub( null );
	}

	/**
	 * Agents write only the post room they are editing, never `admin/online`
	 * itself, so Who's Online and the admin bar have to backfill an agent's
	 * row from there.
	 *
	 * @covers ::wp_presence_admin_room_entries
	 */
	public function test_admin_room_entries_merges_an_agent_row_from_a_post_room() {
		$agent_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->mark_as_agent( $agent_id );

		wp_set_presence(
			'postType/post:42',
			'agent-' . $agent_id,
			array(),
			array(
				'user_id'    => $agent_id,
				'expires_in' => 60,
			)
		);

		$entries  = wp_presence_admin_room_entries();
		$user_ids = wp_list_pluck( $entries, 'user_id' );

		$this->assertContains( (string) $agent_id, $user_ids );

		foreach ( $entries as $entry ) {
			if ( (int) $entry->user_id === $agent_id ) {
				$this->assertTrue( $entry->data['is_agent'] );
				$this->assertSame( 42, $entry->data['post_id'] );
			}
		}
	}

	/**
	 * A human editor's post-room row (from the post lock bridge, or from a
	 * plugin relaying awareness) must not spill into the admin room's list —
	 * only an agent's is backfilled, since a person already has their own
	 * `admin/online` row from Heartbeat.
	 *
	 * @covers ::wp_presence_admin_room_entries
	 */
	public function test_admin_room_entries_does_not_merge_a_non_agent_post_room_row() {
		wp_set_presence( 'postType/post:42', 'editor-' . self::$editor_id, array(), array( 'user_id' => self::$editor_id ) );

		$entries  = wp_presence_admin_room_entries();
		$user_ids = wp_list_pluck( $entries, 'user_id' );

		$this->assertNotContains( (string) self::$editor_id, $user_ids );
	}

	/**
	 * An agent that also runs Heartbeat, or is otherwise already present in
	 * `admin/online`, must not be listed twice.
	 *
	 * @covers ::wp_presence_admin_room_entries
	 */
	public function test_admin_room_entries_does_not_duplicate_an_agent_already_in_the_admin_room() {
		$agent_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->mark_as_agent( $agent_id );

		wp_set_presence( 'admin/online', 'user-' . $agent_id, array( 'screen' => 'dashboard' ), array( 'user_id' => $agent_id ) );
		wp_set_presence(
			'postType/post:42',
			'agent-' . $agent_id,
			array(),
			array(
				'user_id'    => $agent_id,
				'expires_in' => 60,
			)
		);

		$entries  = wp_presence_admin_room_entries();
		$user_ids = wp_list_pluck( $entries, 'user_id' );

		$this->assertCount( 1, array_keys( $user_ids, (string) $agent_id, true ) );
	}

	/**
	 * An agent runs no Heartbeat, so its own `$expires_in` is the only thing
	 * that ever removes its row — there is no pagehide handler to rely on.
	 *
	 * @covers ::wp_presence_admin_room_entries
	 * @covers ::wp_set_presence
	 */
	public function test_an_agent_row_expires_on_its_own_expires_in_with_no_heartbeat() {
		$agent_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->mark_as_agent( $agent_id );

		wp_set_presence(
			'postType/post:42',
			'agent-' . $agent_id,
			array(),
			array(
				'user_id'    => $agent_id,
				'expires_in' => 60,
			)
		);

		$user_ids = wp_list_pluck( wp_presence_admin_room_entries(), 'user_id' );
		$this->assertContains( (string) $agent_id, $user_ids, 'The agent shows up labelled while its 60-second window is still open.' );

		global $wpdb;
		$wpdb->update(
			$wpdb->presence,
			array( 'expires_gmt' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ),
			array( 'client_id' => 'agent-' . $agent_id ),
			array( '%s' ),
			array( '%s' )
		);
		wp_cache_set_last_changed( 'presence' );

		$user_ids = wp_list_pluck( wp_presence_admin_room_entries(), 'user_id' );
		$this->assertNotContains( (string) $agent_id, $user_ids, 'Once its window has passed, the row is gone with nothing else having to remove it.' );
	}

	/**
	 * An agent has no Heartbeat, so saving a post is what puts it in the
	 * post's room, labelled, with nothing else having to run.
	 *
	 * @covers ::wp_presence_on_agent_post_saved
	 */
	public function test_an_agent_saving_a_post_gets_a_labelled_row_in_its_room() {
		$agent_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->mark_as_agent( $agent_id );
		wp_set_current_user( $agent_id );

		$post_id = self::factory()->post->create();

		$entries = wp_get_presence( 'postType/post:' . $post_id );

		$this->assertCount( 1, $entries );
		$this->assertSame( 'agent-' . $agent_id, $entries[0]->client_id );
		$this->assertSame( (string) $agent_id, $entries[0]->user_id );
		$this->assertContains( (string) $agent_id, wp_list_pluck( wp_presence_admin_room_entries(), 'user_id' ), 'The row is labelled as an agent wherever presence is listed.' );
	}

	/**
	 * A person's presence rides Heartbeat, so their save writes nothing.
	 *
	 * @covers ::wp_presence_on_agent_post_saved
	 */
	public function test_a_person_saving_a_post_gets_no_row() {
		wp_set_current_user( self::$editor_id );

		$post_id = self::factory()->post->create();

		$this->assertSame( array(), wp_get_presence( 'postType/post:' . $post_id ) );
	}

	/**
	 * The row's window is the idle threshold, so a busy agent never reads as
	 * idle, and it lapses on its own once the agent stops saving.
	 *
	 * @covers ::wp_presence_on_agent_post_saved
	 */
	public function test_an_agent_save_row_lasts_the_idle_threshold_and_expires_with_no_heartbeat() {
		global $wpdb;

		$agent_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->mark_as_agent( $agent_id );
		wp_set_current_user( $agent_id );

		$post_id = self::factory()->post->create();
		$client  = 'agent-' . $agent_id;

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT date_gmt, expires_gmt FROM {$wpdb->presence} WHERE client_id = %s", $client ) );

		$this->assertSame( wp_presence_idle_threshold(), strtotime( $row->expires_gmt ) - strtotime( $row->date_gmt ) );

		$wpdb->update(
			$wpdb->presence,
			array( 'expires_gmt' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ),
			array( 'client_id' => $client ),
			array( '%s' ),
			array( '%s' )
		);

		$this->assertSame( array(), wp_get_presence( 'postType/post:' . $post_id ) );
		$this->assertNotContains( (string) $agent_id, wp_list_pluck( wp_presence_admin_room_entries(), 'user_id' ) );
	}

	/**
	 * Trashing a post is not editing it, so it writes no row.
	 *
	 * @covers ::wp_presence_on_agent_post_saved
	 */
	public function test_an_agent_trashing_a_post_gets_no_row() {
		$post_id  = self::factory()->post->create();
		$agent_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->mark_as_agent( $agent_id );
		wp_set_current_user( $agent_id );

		wp_trash_post( $post_id );

		$this->assertSame( array(), wp_get_presence( 'postType/post:' . $post_id ) );
	}

	/**
	 * An auto-draft is the editor opening a new post, not the agent saving one.
	 *
	 * @covers ::wp_presence_on_agent_post_saved
	 */
	public function test_an_agent_creating_an_auto_draft_gets_no_row() {
		$agent_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->mark_as_agent( $agent_id );
		wp_set_current_user( $agent_id );

		$post_id = wp_insert_post(
			array(
				'post_title'  => 'Auto draft',
				'post_status' => 'auto-draft',
			)
		);

		$this->assertSame( array(), wp_get_presence( 'postType/post:' . $post_id ) );
	}

	/**
	 * A post type without presence support has no room to write to.
	 *
	 * @covers ::wp_presence_on_agent_post_saved
	 */
	public function test_an_agent_saving_a_post_type_without_presence_support_gets_no_row() {
		register_post_type( 'no_presence_type', array( 'show_ui' => false ) );

		$agent_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->mark_as_agent( $agent_id );
		wp_set_current_user( $agent_id );

		$post_id = self::factory()->post->create( array( 'post_type' => 'no_presence_type' ) );

		$this->assertSame( array(), wp_get_presence( 'postType/no_presence_type:' . $post_id ) );

		unregister_post_type( 'no_presence_type' );
	}









	/**
	 * Marks a user as an agent for the current test, through the same filter
	 * a real Agent Users integration would use.
	 *
	 * @param int $user_id The user ID to mark as an agent.
	 */
	private function mark_as_agent( $user_id ) {
		add_filter(
			'wp_presence_is_agent_user',
			static function ( $is_agent, $filtered_user_id ) use ( $user_id ) {
				return $user_id === $filtered_user_id ? true : $is_agent;
			},
			10,
			2
		);
	}
}
