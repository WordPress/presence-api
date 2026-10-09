<?php
/**
 * Tests for the post-lock bridge.
 *
 * @package Presence_API
 *
 * @group presence
 */
class WP_Test_Presence_Post_Lock_Bridge extends WP_Presence_UnitTestCase {

	private static $editor_id;
	private static $other_editor_id;
	private static $subscriber_id;

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		require_once ABSPATH . 'wp-admin/includes/post.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';

		self::$editor_id       = $factory->user->create( array( 'role' => 'editor' ) );
		self::$other_editor_id = $factory->user->create( array( 'role' => 'editor' ) );
		self::$subscriber_id   = $factory->user->create( array( 'role' => 'subscriber' ) );
	}

	/**
	 * @covers ::wp_presence_bridge_post_lock
	 */
	public function test_post_lock_bridge_requires_edit_cap() {
		$post_id = self::factory()->post->create();

		wp_set_current_user( self::$subscriber_id );

		$response = wp_presence_bridge_post_lock(
			array(),
			array(
				'wp-refresh-post-lock' => array(
					'post_id' => $post_id,
				),
			),
			'post'
		);

		$entries = wp_get_presence( wp_presence_post_room( $post_id ), array( 'timeout' => 300 ) );
		$this->assertCount( 0, $entries, 'Subscriber should not create a presence entry for a post they cannot edit.' );
	}

	/**
	 * @covers ::wp_presence_bridge_post_lock
	 */
	public function test_post_lock_bridge_creates_presence() {
		$post_id = self::factory()->post->create();

		wp_set_current_user( self::$editor_id );

		wp_presence_bridge_post_lock(
			array(),
			array(
				'wp-refresh-post-lock' => array(
					'post_id' => $post_id,
				),
			),
			'post'
		);

		$room    = wp_presence_post_room( $post_id );
		$entries = wp_get_presence( $room );

		$this->assertCount( 1, $entries );
		$this->assertSame( 'editor-' . self::$editor_id, $entries[0]->client_id );
		$this->assertTrue( $entries[0]->data['locked'] );
	}

	/**
	 * The editor handler writes the same entry from the same payload, so the
	 * bridge writing again would only cost a second query for the same row.
	 *
	 * @covers ::wp_presence_bridge_post_lock
	 */
	public function test_post_lock_bridge_defers_to_the_editor_ping() {
		$post_id = self::factory()->post->create();

		wp_set_current_user( self::$editor_id );

		wp_presence_bridge_post_lock(
			array(),
			array(
				'wp-refresh-post-lock'  => array(
					'post_id' => $post_id,
				),
				'presence-editor-ping' => array(
					'post_id' => $post_id,
				),
			),
			'post'
		);

		$this->assertCount(
			0,
			wp_get_presence( wp_presence_post_room( $post_id ) ),
			'The bridge should stand down when the editor ping already covers this post.'
		);
	}

	/**
	 * @covers ::wp_presence_bridge_post_lock
	 */
	public function test_post_lock_bridge_writes_when_the_editor_ping_is_for_another_post() {
		$locked_id = self::factory()->post->create();
		$other_id  = self::factory()->post->create();

		wp_set_current_user( self::$editor_id );

		wp_presence_bridge_post_lock(
			array(),
			array(
				'wp-refresh-post-lock'  => array(
					'post_id' => $locked_id,
				),
				'presence-editor-ping' => array(
					'post_id' => $other_id,
				),
			),
			'post'
		);

		$entries = wp_get_presence( wp_presence_post_room( $locked_id ) );

		$this->assertCount( 1, $entries );
		$this->assertSame( 'editor-' . self::$editor_id, $entries[0]->client_id );
	}

	/**
	 * Asserts the cache key itself, since a meta write anywhere would bump it.
	 *
	 * @covers ::wp_presence_update_post_lock
	 * @covers ::wp_presence_get_post_lock
	 * @covers ::wp_presence_post_lock_room
	 * @covers ::wp_presence_post_lock_value
	 * @covers ::wp_presence_post_lock_client_id
	 */
	public function test_refreshing_a_post_lock_leaves_cached_post_queries_valid() {
		$post_id = self::factory()->post->create();

		wp_set_current_user( self::$editor_id );
		wp_set_post_lock( $post_id );
		$last_changed = wp_cache_get_last_changed( 'posts' );
		wp_set_post_lock( $post_id );

		$this->assertSame( $last_changed, wp_cache_get_last_changed( 'posts' ) );

		wp_set_current_user( self::$other_editor_id );
		$this->assertSame( self::$editor_id, wp_check_post_lock( $post_id ), 'A second user should still see the post as locked.' );
	}

	/**
	 * @covers ::wp_presence_delete_post_lock
	 */
	public function test_deleting_a_post_lock_releases_the_post() {
		$post_id = self::factory()->post->create();

		wp_set_current_user( self::$editor_id );
		wp_set_post_lock( $post_id );
		delete_post_meta( $post_id, '_edit_lock' );

		wp_set_current_user( self::$other_editor_id );
		$this->assertFalse( wp_check_post_lock( $post_id ) );
	}

	/**
	 * @covers ::wp_presence_post_lock_room
	 */
	public function test_a_type_without_presence_still_keeps_its_lock_out_of_meta() {
		register_post_type( 'no_presence', array( 'show_ui' => true, 'supports' => array( 'title' ) ) );
		$post_id = self::factory()->post->create( array( 'post_type' => 'no_presence' ) );

		wp_set_current_user( self::$editor_id );
		wp_set_post_lock( $post_id );

		$this->assertNotEmpty( wp_presence_room_rows( 'postType/no_presence:' . $post_id, null, wp_presence_post_lock_client_id() ) );
		wp_set_current_user( self::$other_editor_id );
		$this->assertSame( self::$editor_id, wp_check_post_lock( $post_id ) );

		unregister_post_type( 'no_presence' );
	}

	/**
	 * Core releases a lock on unload only if it still holds it, by passing it as $prev_value.
	 *
	 * @covers ::wp_presence_update_post_lock
	 */
	public function test_a_stale_previous_lock_leaves_the_current_one() {
		$post_id = self::factory()->post->create();

		wp_set_current_user( self::$editor_id );
		wp_set_post_lock( $post_id );

		$released = update_post_meta( $post_id, '_edit_lock', '1:' . self::$other_editor_id, '1:' . self::$other_editor_id );

		$this->assertFalse( $released );
		wp_set_current_user( self::$other_editor_id );
		$this->assertSame( self::$editor_id, wp_check_post_lock( $post_id ) );
	}

	/**
	 * @covers ::wp_presence_post_lock_room
	 * @covers ::wp_presence_update_post_lock
	 * @covers ::wp_set_presence
	 */
	public function test_post_lock_stays_out_of_meta_when_recording_is_off() {
		add_filter( 'wp_presence_recording_enabled', '__return_false' );
		$post_id = self::factory()->post->create();

		wp_set_current_user( self::$editor_id );
		wp_set_post_lock( $post_id );

		$this->assertNotEmpty( wp_presence_room_rows( 'postType/post:' . $post_id, null, wp_presence_post_lock_client_id() ) );
		wp_set_current_user( self::$other_editor_id );
		$this->assertSame( self::$editor_id, wp_check_post_lock( $post_id ) );
	}

	/**
	 * The posts list checks every row's lock on each tick, so the checks share one query.
	 *
	 * @covers ::wp_presence_prime_heartbeat_locks
	 * @covers ::wp_presence_prime_post_locks
	 * @covers ::wp_presence_post_lock_value
	 */
	public function test_checking_locked_posts_reads_every_lock_in_one_query() {
		$post_ids = self::factory()->post->create_many( 3 );

		wp_set_current_user( self::$editor_id );
		wp_set_post_lock( $post_ids[0] );
		wp_set_current_user( self::$other_editor_id );

		$data    = array( 'wp-check-locked-posts' => preg_replace( '/^/', 'post-', $post_ids ) );
		$queries = 0;
		$count   = static function ( $query ) use ( &$queries ) {
			global $wpdb;

			$queries += (int) str_contains( $query, $wpdb->presence );

			return $query;
		};

		add_filter( 'query', $count );
		wp_presence_prime_heartbeat_locks( array(), $data );
		$response = wp_check_locked_posts( array(), $data, 'edit-post' );
		remove_filter( 'query', $count );

		$this->assertSame( 1, $queries );
		$this->assertSame( array( 'post-' . $post_ids[0] ), array_keys( $response['wp-check-locked-posts'] ) );

		wp_set_post_lock( $post_ids[1] );
		wp_set_current_user( self::$editor_id );
		$this->assertSame( self::$other_editor_id, wp_check_post_lock( $post_ids[1] ), 'A lock taken after priming should be read fresh.' );
	}

	/**
	 * A heartbeat that refreshes no lock has nothing to bridge.
	 *
	 * @covers ::wp_presence_bridge_post_lock
	 */
	public function test_post_lock_bridge_ignores_a_heartbeat_without_a_lock() {
		wp_set_current_user( self::$editor_id );

		$response = wp_presence_bridge_post_lock( array( 'kept' => true ), array(), 'post' );

		$this->assertSame( array( 'kept' => true ), $response );
		$this->assertSame( 0, $this->count_presence_rows() );
	}

	/**
	 * A type without presence support has no room to write the editor's entry to.
	 *
	 * @covers ::wp_presence_bridge_post_lock
	 */
	public function test_post_lock_bridge_skips_a_type_without_presence() {
		register_post_type( 'no_presence', array( 'show_ui' => true, 'supports' => array( 'title' ) ) );
		$post_id = self::factory()->post->create( array( 'post_type' => 'no_presence' ) );

		wp_set_current_user( self::$editor_id );
		wp_presence_bridge_post_lock( array(), array( 'wp-refresh-post-lock' => array( 'post_id' => $post_id ) ), 'post' );

		$this->assertEmpty( wp_get_presence( 'postType/no_presence:' . $post_id ) );

		unregister_post_type( 'no_presence' );
	}

	/**
	 * Only `_edit_lock` on a real post moves out of meta, and only once the table exists.
	 *
	 * @covers ::wp_presence_post_lock_room
	 */
	public function test_the_lock_room_is_only_found_for_a_post_lock() {
		$post_id = self::factory()->post->create();

		$this->assertSame( 'postType/post:' . $post_id, wp_presence_post_lock_room( $post_id, '_edit_lock' ) );
		$this->assertFalse( wp_presence_post_lock_room( $post_id, '_edit_last' ), 'Another meta key stays in meta.' );
		$this->assertFalse( wp_presence_post_lock_room( 0, '_edit_lock' ), 'No post means no room.' );
		$this->assertFalse( wp_presence_post_lock_room( PHP_INT_MAX, '_edit_lock' ), 'A missing post means no room.' );

		add_filter( 'option_wp_presence_db_version', '__return_zero' );

		$this->assertFalse( wp_presence_post_lock_room( $post_id, '_edit_lock' ), 'Without the table the lock stays in meta.' );
	}

	/**
	 * An unlocked post reads as empty in the shape core asked for, as meta would.
	 *
	 * @covers ::wp_presence_get_post_lock
	 * @covers ::wp_presence_post_lock_value
	 */
	public function test_an_unlocked_post_reads_as_empty() {
		$post_id = self::factory()->post->create();

		$this->assertSame( '', get_post_meta( $post_id, '_edit_lock', true ) );
		$this->assertSame( array(), get_post_meta( $post_id, '_edit_lock' ) );
	}

	/**
	 * Every hook hands back `$check` for a key it does not own, so other meta is untouched.
	 *
	 * @covers ::wp_presence_get_post_lock
	 * @covers ::wp_presence_update_post_lock
	 * @covers ::wp_presence_delete_post_lock
	 */
	public function test_other_meta_keys_pass_through() {
		$post_id = self::factory()->post->create();

		$this->assertNull( wp_presence_get_post_lock( null, $post_id, '_edit_last', true ) );
		$this->assertNull( wp_presence_update_post_lock( null, $post_id, '_edit_last', '1', '' ) );
		$this->assertNull( wp_presence_delete_post_lock( null, $post_id, '_edit_last', '', false ) );
	}

	/**
	 * A lock without a user is paired with `_edit_last` by core, which only meta can do.
	 *
	 * @covers ::wp_presence_update_post_lock
	 */
	public function test_a_lock_without_a_user_stays_in_meta() {
		$post_id = self::factory()->post->create();

		$this->assertNull( wp_presence_update_post_lock( null, $post_id, '_edit_lock', (string) time(), '' ) );
		$this->assertNull( wp_presence_update_post_lock( null, $post_id, '_edit_lock', '0:' . self::$editor_id, '' ) );
		$this->assertSame( 0, $this->count_presence_rows() );
	}

	/**
	 * A delete across every post carries no post to find a room for.
	 *
	 * @covers ::wp_presence_delete_post_lock
	 */
	public function test_deleting_every_lock_is_left_to_meta() {
		$post_id = self::factory()->post->create();

		wp_set_current_user( self::$editor_id );
		wp_set_post_lock( $post_id );

		$this->assertNull( wp_presence_delete_post_lock( null, 0, '_edit_lock', '', true ) );
		$this->assertNotEmpty( wp_presence_room_rows( 'postType/post:' . $post_id, null, wp_presence_post_lock_client_id() ), 'The lock row is untouched.' );
	}

	/**
	 * Posts with no room to read skip the query altogether.
	 *
	 * @covers ::wp_presence_prime_post_locks
	 */
	public function test_priming_without_any_rooms_runs_no_query() {
		$queries = 0;
		$count   = static function ( $query ) use ( &$queries ) {
			global $wpdb;

			$queries += (int) str_contains( $query, $wpdb->presence );

			return $query;
		};

		add_filter( 'query', $count );
		wp_presence_prime_post_locks( array( 0, PHP_INT_MAX ) );
		remove_filter( 'query', $count );

		$this->assertSame( 0, $queries );
		$this->assertEmpty( $GLOBALS['_wp_presence_post_locks'] ?? null );
	}

	/**
	 * The admin posts list primes its locks, and a front-end or secondary query does not.
	 *
	 * @covers ::wp_presence_prime_post_list_locks
	 */
	public function test_only_the_admin_main_query_primes_locks() {
		global $wp_the_query;

		$post_id = self::factory()->post->create();
		$posts   = array( get_post( $post_id ) );
		$room    = 'postType/post:' . $post_id;
		$main    = $wp_the_query;
		$query   = new WP_Query();

		$wp_the_query = $query;

		try {
			$this->assertSame( $posts, wp_presence_prime_post_list_locks( $posts, $query ) );
			$this->assertEmpty( $GLOBALS['_wp_presence_post_locks'] ?? null, 'The front end does not prime.' );

			set_current_screen( 'edit-post' );

			wp_presence_prime_post_list_locks( $posts, new WP_Query() );
			$this->assertEmpty( $GLOBALS['_wp_presence_post_locks'] ?? null, 'A secondary query does not prime.' );

			wp_presence_prime_post_list_locks( $posts, $query );
			$this->assertSame( '', $GLOBALS['_wp_presence_post_locks'][ $room ] );
		} finally {
			$wp_the_query = $main;
			set_current_screen( 'front' );
		}
	}

	/**
	 * Counts every row in the presence table, reserved rows included.
	 *
	 * @return int The row count.
	 */
	private function count_presence_rows() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->presence}" );
	}
}
