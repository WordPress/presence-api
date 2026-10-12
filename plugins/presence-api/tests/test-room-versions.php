<?php
/**
 * Tests for room versions and the earliest expiry a reader waits on.
 *
 * @package Presence_API
 *
 * @group presence
 */
class WP_Test_Presence_Room_Versions extends WP_Presence_UnitTestCase {

	/**
	 * Editor who writes the rows.
	 *
	 * @var int
	 */
	protected static $editor_id;

	/**
	 * Second editor, for a row that changes hands.
	 *
	 * @var int
	 */
	protected static $other_editor_id;

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$editor_id       = $factory->user->create( array( 'role' => 'editor' ) );
		self::$other_editor_id = $factory->user->create( array( 'role' => 'editor' ) );
	}

	/**
	 * Nothing asked for, nothing returned, and a room nobody wrote has no version yet.
	 *
	 * @covers ::wp_get_presence_room_versions
	 * @covers ::wp_get_presence_room_next_expiry
	 */
	public function test_unwritten_rooms_have_no_version_or_expiry() {
		$this->assertSame( array(), wp_get_presence_room_versions( array() ) );
		$this->assertSame( array(), wp_get_presence_room_next_expiry( '' ) );
		$this->assertSame(
			array(
				'versions/a' => null,
				'versions/b' => null,
			),
			wp_get_presence_room_versions( array( 'versions/a', 'versions/b' ) )
		);
		$this->assertSame( array( 'versions/a' => null ), wp_get_presence_room_next_expiry( 'versions/a' ) );
	}

	/**
	 * Arrivals and removals are what peers see, so each moves the version once.
	 *
	 * @covers ::wp_set_presence
	 * @covers ::wp_remove_presence
	 * @covers ::wp_get_presence_room_versions
	 * @covers ::wp_presence_bump_room_version
	 */
	public function test_arrivals_and_removals_move_the_version() {
		$room = 'versions/room';

		wp_set_presence( $room, 'client-1', array( 'cursor' => 1 ), array( 'user_id' => self::$editor_id ) );
		$this->assertSame( '1', $this->version( $room ) );

		wp_set_presence( $room, 'client-2', array( 'cursor' => 1 ), array( 'user_id' => self::$editor_id ) );
		$this->assertSame( '2', $this->version( $room ) );

		wp_remove_presence( $room, 'client-1' );
		$this->assertSame( '3', $this->version( $room ) );

		wp_remove_presence( $room, 'client-1' );
		$this->assertSame( '3', $this->version( $room ), 'Removing a row that is not there changes nothing.' );
	}

	/**
	 * A new state or a new user on the same client is what peers see.
	 *
	 * @covers ::wp_set_presence
	 * @covers ::wp_presence_write_row
	 */
	public function test_a_change_of_state_or_user_moves_the_version() {
		$room = 'versions/room';

		wp_set_presence( $room, 'client-1', array( 'cursor' => 1 ), array( 'user_id' => self::$editor_id ) );
		wp_set_presence( $room, 'client-1', array( 'cursor' => 2 ), array( 'user_id' => self::$editor_id ) );
		$this->assertSame( '2', $this->version( $room ), 'A new state.' );

		wp_set_presence( $room, 'client-1', array( 'cursor' => 2 ), array( 'user_id' => self::$other_editor_id ) );
		$this->assertSame( '3', $this->version( $room ), 'A new user.' );
	}

	/**
	 * A write that only restamps a live row tells peers nothing, so it costs the upsert alone.
	 *
	 * @covers ::wp_set_presence
	 * @covers ::wp_presence_write_row
	 */
	public function test_a_refresh_leaves_the_version_alone() {
		$this->skip_on_sqlite();

		$room = 'versions/room';

		wp_set_presence( $room, 'client-1', array( 'cursor' => 1 ), array( 'user_id' => self::$editor_id ) );
		$this->backdate( $room, 'client-1', wp_presence_refresh_threshold() + 5 );
		$stamped = $this->stored( $room, 'client-1', 'date_gmt' );

		$queries = $this->count_presence_queries(
			function () use ( $room ) {
				wp_set_presence( $room, 'client-1', array( 'cursor' => 1 ), array( 'user_id' => self::$editor_id ) );
			}
		);

		$this->assertNotSame( $stamped, $this->stored( $room, 'client-1', 'date_gmt' ), 'Guard: the write has to have refreshed the row.' );
		$this->assertSame( '1', $this->version( $room ) );
		$this->assertSame( 1, $queries, 'The upsert, with no bump and no read ahead of it.' );
	}

	/**
	 * A client whose row expired had left, so writing it again is an arrival.
	 *
	 * @covers ::wp_set_presence
	 * @covers ::wp_presence_write_row
	 */
	public function test_writing_over_an_expired_row_moves_the_version() {
		global $wpdb;

		$room = 'versions/room';

		wp_set_presence( $room, 'client-1', array( 'cursor' => 1 ), array( 'user_id' => self::$editor_id ) );
		$wpdb->update(
			$wpdb->presence,
			array(
				'date_gmt'    => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ),
				'expires_gmt' => gmdate( 'Y-m-d H:i:s', time() - MINUTE_IN_SECONDS ),
			),
			array(
				'room'      => $room,
				'client_id' => 'client-1',
			)
		);

		wp_set_presence( $room, 'client-1', array( 'cursor' => 1 ), array( 'user_id' => self::$editor_id ) );

		$this->assertSame( '2', $this->version( $room ) );
	}

	/**
	 * Erasing a user takes them out of every room they were in.
	 *
	 * @covers ::wp_remove_user_presence
	 */
	public function test_removing_a_user_moves_each_room_they_were_in() {
		wp_set_presence( 'versions/a', 'client-1', array(), array( 'user_id' => self::$editor_id ) );
		wp_set_presence( 'versions/b', 'client-2', array(), array( 'user_id' => self::$editor_id ) );
		wp_set_presence( 'versions/c', 'client-3', array(), array( 'user_id' => self::$other_editor_id ) );

		wp_remove_user_presence( self::$editor_id );

		$this->assertSame(
			array(
				'versions/a' => '2',
				'versions/b' => '2',
				'versions/c' => '1',
			),
			wp_get_presence_room_versions( array( 'versions/a', 'versions/b', 'versions/c' ) )
		);
	}

	/**
	 * Bookkeeping such as a post lock is no participant, so peers never hear of it.
	 *
	 * @covers ::wp_set_presence
	 */
	public function test_a_reserved_row_leaves_the_version_alone() {
		$room = 'versions/room';

		wp_set_presence( $room, 'client-1', array(), array( 'user_id' => self::$editor_id ) );
		wp_set_presence( $room, '_lock', array(), array( 'user_id' => self::$editor_id ) );

		$this->assertSame( '1', $this->version( $room ) );
	}

	/**
	 * The counter is a reserved row, so reads, exports and erasure all pass it by.
	 *
	 * @covers ::wp_get_presence
	 * @covers ::wp_presence_get_rows_for_user
	 * @covers ::wp_remove_user_presence
	 */
	public function test_the_counter_never_reads_as_a_client() {
		global $wpdb;

		$room = 'versions/room';
		$user = self::factory()->user->create();

		wp_set_presence( $room, 'client-1', array(), array( 'user_id' => self::$editor_id ) );
		$this->assertSame( array( 'client-1' ), wp_list_pluck( wp_get_presence( $room ), 'client_id' ) );

		// A counter that has reached a user's ID still is not that user's row.
		$wpdb->update( $wpdb->presence, array( 'data' => (string) ( $user - 1 ) ), array( 'client_id' => wp_presence_version_client_id() ) );
		wp_presence_bump_room_version( $room );

		$this->assertSame( array(), wp_presence_get_rows_for_user( $user ), 'A personal data export would include the counter.' );

		wp_remove_user_presence( $user );

		$this->assertSame( (string) $user, $this->version( $room ) );
	}

	/**
	 * A bump with no room to put it in does nothing.
	 *
	 * @covers ::wp_presence_bump_room_version
	 */
	public function test_a_bump_needs_a_room() {
		wp_presence_bump_room_version( '' );
		wp_presence_bump_room_version( null );

		$this->assertSame( 0, $this->count_rows() );
	}

	/**
	 * The earliest live row says when to look again, leaving out expired and reserved rows.
	 *
	 * @covers ::wp_get_presence_room_next_expiry
	 */
	public function test_the_next_expiry_is_the_earliest_live_row() {
		global $wpdb;

		wp_set_presence(
			'expiry/a',
			'client-1',
			array(),
			array(
				'user_id'    => self::$editor_id,
				'expires_in' => 300,
			)
		);
		wp_set_presence(
			'expiry/a',
			'client-2',
			array(),
			array(
				'user_id'    => self::$editor_id,
				'expires_in' => 60,
			)
		);
		wp_set_presence( 'expiry/a', 'client-3', array(), array( 'user_id' => self::$editor_id ) );
		wp_set_presence(
			'expiry/a',
			'_lock',
			array(),
			array(
				'user_id'    => self::$editor_id,
				'expires_in' => 5,
			)
		);
		wp_set_presence(
			'expiry/b',
			'client-4',
			array(),
			array(
				'user_id'    => self::$editor_id,
				'expires_in' => 120,
			)
		);

		$wpdb->update(
			$wpdb->presence,
			array( 'expires_gmt' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ),
			array(
				'room'      => 'expiry/a',
				'client_id' => 'client-3',
			)
		);

		$this->assertSame(
			array(
				'expiry/a' => $this->stored( 'expiry/a', 'client-2', 'expires_gmt' ),
				'expiry/b' => $this->stored( 'expiry/b', 'client-4', 'expires_gmt' ),
				'expiry/c' => null,
			),
			wp_get_presence_room_next_expiry( array( 'expiry/a', 'expiry/b', 'expiry/c' ) )
		);
	}

	/**
	 * With a persistent object cache the counter lives there, and a refresh still leaves it alone.
	 *
	 * @covers ::wp_get_presence_room_versions
	 * @covers ::wp_presence_bump_room_version
	 */
	public function test_with_a_persistent_cache_the_counter_lives_in_the_cache() {
		$this->skip_on_sqlite();

		$room = 'cached/room';

		$this->with_persistent_cache(
			function () use ( $room ) {
				$this->assertSame( array( $room => null ), wp_get_presence_room_versions( $room ) );

				wp_set_presence( $room, 'client-1', array( 'cursor' => 1 ), array( 'user_id' => self::$editor_id ) );
				wp_set_presence( $room, 'client-1', array( 'cursor' => 2 ), array( 'user_id' => self::$editor_id ) );
				$this->assertSame( '2', $this->version( $room ) );

				$this->backdate( $room, 'client-1', wp_presence_refresh_threshold() + 5 );
				wp_set_presence( $room, 'client-1', array( 'cursor' => 2 ), array( 'user_id' => self::$editor_id ) );
				$this->assertSame( '2', $this->version( $room ), 'A refresh.' );

				wp_remove_presence( $room, 'client-1' );
				$this->assertSame( '3', $this->version( $room ) );
			}
		);

		$this->assertSame( 0, $this->count_rows( wp_presence_version_client_id() ), 'No _version row is written.' );
	}

	/**
	 * With a persistent object cache a quiet room's next expiry costs no query, and a write clears it.
	 *
	 * @covers ::wp_get_presence_room_next_expiry
	 * @covers ::wp_presence_forget_room_next_expiry
	 */
	public function test_with_a_persistent_cache_the_next_expiry_is_kept_until_the_room_is_written() {
		$room = 'cached/expiry';

		$this->with_persistent_cache(
			function () use ( $room ) {
				wp_set_presence(
					$room,
					'client-1',
					array(),
					array(
						'user_id'    => self::$editor_id,
						'expires_in' => 300,
					)
				);
				$first = wp_get_presence_room_next_expiry( $room )[ $room ];

				$queries = $this->count_presence_queries(
					function () use ( $room ) {
						wp_get_presence_room_next_expiry( $room );
					}
				);
				$this->assertSame( 0, $queries, 'A quiet room is answered from the cache.' );

				wp_set_presence(
					$room,
					'client-2',
					array(),
					array(
						'user_id'    => self::$editor_id,
						'expires_in' => 60,
					)
				);

				$this->assertNotSame( $first, wp_get_presence_room_next_expiry( $room )[ $room ] );
				$this->assertSame( $this->stored( $room, 'client-2', 'expires_gmt' ), wp_get_presence_room_next_expiry( $room )[ $room ] );
			}
		);
	}

	/**
	 * Returns a room's version.
	 *
	 * @param string $room The room identifier.
	 * @return string|null The version.
	 */
	private function version( $room ) {
		return wp_get_presence_room_versions( $room )[ $room ];
	}

	/**
	 * Moves a row's date_gmt back, leaving it live.
	 *
	 * @param string $room      The room identifier.
	 * @param string $client_id The client identifier.
	 * @param int    $seconds   How far back.
	 */
	private function backdate( $room, $client_id, $seconds ) {
		global $wpdb;

		$wpdb->update(
			$wpdb->presence,
			array( 'date_gmt' => gmdate( 'Y-m-d H:i:s', time() - $seconds ) ),
			array(
				'room'      => $room,
				'client_id' => $client_id,
			)
		);
		wp_cache_set_last_changed( 'presence' );
	}

	/**
	 * Reads one column of a stored row.
	 *
	 * @param string $room      The room identifier.
	 * @param string $client_id The client identifier.
	 * @param string $column    The column.
	 * @return string|null The value.
	 */
	private function stored( $room, $client_id, $column ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_var( $wpdb->prepare( "SELECT {$column} FROM {$wpdb->presence} WHERE room = %s AND client_id = %s", $room, $client_id ) );
	}

	/**
	 * Counts rows in the presence table, optionally for one client.
	 *
	 * @param string $client_id Optional. Only this client's rows.
	 * @return int The count.
	 */
	private function count_rows( $client_id = '' ) {
		global $wpdb;

		if ( '' === $client_id ) {
			return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->presence}" );
		}

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->presence} WHERE client_id = %s", $client_id ) );
	}

	/**
	 * Counts the queries against the presence table during a callback.
	 *
	 * @param callable $callback The work to measure.
	 * @return int The count.
	 */
	private function count_presence_queries( $callback ) {
		global $wpdb;

		$count   = 0;
		$table   = $wpdb->presence;
		$capture = static function ( $query ) use ( &$count, $table ) {
			$count += (int) ( false !== strpos( $query, $table ) );

			return $query;
		};

		add_filter( 'query', $capture );
		$callback();
		remove_filter( 'query', $capture );

		return $count;
	}

	/**
	 * Runs a callback as if a persistent object cache were installed.
	 *
	 * @param callable $callback The work to run.
	 */
	private function with_persistent_cache( $callback ) {
		// Passing null back would leave the flag on, since null means "only read it".
		$previous = (bool) wp_using_ext_object_cache( true );

		try {
			$callback();
		} finally {
			wp_using_ext_object_cache( $previous );
		}
	}

	/**
	 * Skips a test that relies on MySQL telling a refresh from a change.
	 */
	private function skip_on_sqlite() {
		if ( $GLOBALS['wpdb'] instanceof WP_SQLite_DB ) {
			$this->markTestSkipped( 'The SQLite integration counts every write as one a peer sees.' );
		}
	}
}
