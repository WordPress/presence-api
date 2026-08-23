<?php
/**
 * Tests for stale-screen detection.
 *
 * @package Presence_API
 *
 * @group presence
 *
 * Helpers reached indirectly — through hook callbacks, or called internally
 * by an annotated entry point. Without these the coverage driver credits
 * only the annotated entry points and discards everything they call.
 *
 * @covers ::wp_presence_get_screen_revisions
 * @covers ::wp_presence_get_screen_revision
 * @covers ::wp_presence_known_options_pages
 * @covers ::wp_presence_parse_screen_key_target
 * @covers ::wp_presence_normalize_screen_key
 * @covers ::wp_presence_is_admin_screen_save
 * @covers ::wp_presence_current_user_can_access_screen
 * @covers ::wp_presence_enqueue_stale_screen_banner
 * @covers ::wp_presence_bump_screen_revision
 * @covers ::wp_presence_current_screen_key
 */
class WP_Test_Presence_Screen_Revisions extends WP_UnitTestCase {

	private static $admin_id;
	private static $admin2_id;
	private static $editor_id;

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$admin_id  = $factory->user->create( array( 'role' => 'administrator' ) );
		self::$admin2_id = $factory->user->create( array( 'role' => 'administrator' ) );
		self::$editor_id = $factory->user->create( array( 'role' => 'editor' ) );
	}

	public function tear_down() {
		delete_option( 'wp_presence_screen_revisions' );
		foreach ( wp_presence_known_options_pages() as $page ) {
			delete_option( 'wp_presence_screen_rev_options_' . $page );
		}
		// These three users are created once for the whole class rather than
		// per test, so their meta survives a test's DB-transaction rollback
		// in the object cache even though the row itself is gone. Clear it
		// explicitly, the same reason the option above needs it.
		foreach ( array( self::$admin_id, self::$admin2_id, self::$editor_id ) as $user_id ) {
			delete_user_meta( $user_id, '_wp_presence_screen_rev' );
		}
		unset( $_POST['option_page'] );
		unset( $_GET['user_id'], $_GET['taxonomy'], $_GET['tag_ID'], $_GET['c'] );
		// is_admin() reads $current_screen, which outlives the test that set it.
		set_current_screen( 'dashboard' );
		parent::tear_down();
	}

	/**
	 * @covers ::wp_presence_bump_screen_revision
	 */
	public function test_bump_writes_timestamp_and_actor_for_known_options_page() {
		wp_set_current_user( self::$admin_id );

		$before   = time();
		$revision = wp_presence_bump_screen_revision( 'options/general' );

		$this->assertGreaterThanOrEqual( $before, $revision );

		$entry = wp_presence_get_screen_revision( 'options/general' );
		$this->assertNotNull( $entry );
		$this->assertSame( $revision, (int) $entry['rev'] );
		$this->assertSame( self::$admin_id, (int) $entry['actor_id'] );
		$this->assertArrayNotHasKey(
			'actor_name',
			$entry,
			'Display name is resolved fresh on heartbeat, not stored, so renames show immediately.'
		);
	}

	/**
	 * @covers ::wp_presence_bump_screen_revision
	 */
	public function test_bumping_one_options_page_does_not_touch_another() {
		wp_set_current_user( self::$admin_id );

		wp_presence_bump_screen_revision( 'options/writing' );

		$this->assertNull(
			wp_presence_get_screen_revision( 'options/general' ),
			'Each Settings page now has its own option, so bumping one must not create or touch another.'
		);
	}

	/**
	 * @covers ::wp_presence_bump_screen_revision
	 */
	public function test_bump_rejects_empty_screen_key() {
		wp_set_current_user( self::$admin_id );

		$this->assertFalse( wp_presence_bump_screen_revision( '' ) );
		$this->assertSame( array(), wp_presence_get_screen_revisions() );
	}

	/**
	 * @covers ::wp_presence_on_updated_option
	 */
	public function test_updated_option_bumps_when_option_page_present() {
		wp_set_current_user( self::$admin_id );
		set_current_screen( 'options-general' );
		$_POST['option_page'] = 'general';

		$before = time();
		update_option( 'blogname', 'New Title ' . wp_generate_password( 6, false ) );

		$entry = wp_presence_get_screen_revision( 'options/general' );
		$this->assertNotNull( $entry );
		$this->assertGreaterThanOrEqual( $before, (int) $entry['rev'] );
	}

	/**
	 * @covers ::wp_presence_on_updated_option
	 */
	public function test_updated_option_does_not_bump_without_option_page() {
		wp_set_current_user( self::$admin_id );
		set_current_screen( 'options-general' );
		// No $_POST['option_page']: this looks like a side-effect option update, not a Settings page save.

		update_option( 'blogname', 'Side Effect ' . wp_generate_password( 6, false ) );

		$this->assertNull( wp_presence_get_screen_revision( 'options/general' ) );
	}

	/**
	 * @covers ::wp_presence_on_post_updated
	 */
	public function test_post_updated_bumps_post_screen() {
		wp_set_current_user( self::$admin_id );
		set_current_screen( 'post' );

		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		// Posts store no revision of their own — this reads core's own
		// post_modified_gmt, which every post has from the moment it's
		// created, so there is no "not yet touched" state to assert here
		// the way the old counter had.
		$created_entry = wp_presence_get_screen_revision( 'post/' . $post_id );
		$this->assertNotNull( $created_entry );

		// _edit_last is written by wp-admin's edit_post(), not by
		// wp_update_post() directly, so set it the way that flow would to
		// test our read of it.
		update_post_meta( $post_id, '_edit_last', self::$admin_id );

		sleep( 1 ); // post_modified_gmt has second granularity.

		wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => 'Updated title ' . wp_generate_password( 6, false ),
			)
		);

		$updated_entry = wp_presence_get_screen_revision( 'post/' . $post_id );
		$this->assertNotNull( $updated_entry );
		$this->assertGreaterThan( (int) $created_entry['rev'], (int) $updated_entry['rev'] );
		$this->assertSame( self::$admin_id, (int) $updated_entry['actor_id'] );
	}

	/**
	 * A Heartbeat tick is its own request with a cold cache, unlike the write
	 * path, which reads the post right after saving it, while it's still
	 * warm. Reading the revision here must cost the same one query the old
	 * shared option cost on every tick, not one for the post row and another
	 * for its meta, or this trades a write-side saving for a read-side
	 * regression on the far more frequent path.
	 *
	 * @covers ::wp_presence_get_screen_revision
	 */
	public function test_post_revision_lookup_costs_one_query_cold() {
		global $wpdb;

		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		clean_post_cache( $post_id );

		$before  = $wpdb->num_queries;
		wp_presence_get_screen_revision( 'post/' . $post_id );
		$queries = $wpdb->num_queries - $before;

		$this->assertSame( 1, $queries );
	}

	/**
	 * @covers ::wp_presence_on_post_updated
	 */
	public function test_post_updated_skips_autosave_and_revision() {
		wp_set_current_user( self::$admin_id );
		set_current_screen( 'post' );

		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$before  = wp_presence_get_screen_revision( 'post/' . $post_id );

		// Autosaves and revisions go through post_updated too, so the hook
		// must filter them out — otherwise every autosave tick would report
		// a change. Posts store nothing of their own now, so "filtered out"
		// means the underlying post row, and the revision read from it, is
		// untouched by the autosave.
		wp_create_post_autosave(
			array(
				'post_ID'      => $post_id,
				'post_type'    => 'post',
				'post_content' => 'Autosaved content',
				'post_title'   => 'Autosaved title',
			)
		);

		$after = wp_presence_get_screen_revision( 'post/' . $post_id );
		$this->assertSame( $before, $after, 'Autosaves should not change the parent post\'s reported revision.' );
	}

	/**
	 * @covers ::wp_presence_on_profile_update
	 */
	public function test_profile_update_bumps_user_screen() {
		wp_set_current_user( self::$admin_id );
		set_current_screen( 'user-edit' );

		$before = time();
		wp_update_user(
			array(
				'ID'           => self::$editor_id,
				'display_name' => 'Edited Editor ' . wp_generate_password( 6, false ),
			)
		);

		$entry = wp_presence_get_screen_revision( 'user-edit/' . self::$editor_id );
		$this->assertNotNull( $entry );
		$this->assertGreaterThanOrEqual( $before, (int) $entry['rev'] );
		$this->assertSame( self::$admin_id, (int) $entry['actor_id'] );
	}

	/**
	 * @covers ::wp_presence_on_edited_term
	 */
	public function test_edited_term_bumps_term_screen() {
		wp_set_current_user( self::$admin_id );
		set_current_screen( 'edit-tags' );

		$term_id = self::factory()->term->create( array( 'taxonomy' => 'category' ) );

		$before = time();
		wp_update_term( $term_id, 'category', array( 'description' => 'Updated' ) );

		$entry = wp_presence_get_screen_revision( 'term/category/' . $term_id );
		$this->assertNotNull( $entry );
		$this->assertGreaterThanOrEqual( $before, (int) $entry['rev'] );
	}

	/**
	 * @covers ::wp_presence_on_edit_comment
	 */
	public function test_edit_comment_bumps_comment_screen() {
		wp_set_current_user( self::$admin_id );
		set_current_screen( 'comment' );

		$comment_id = self::factory()->comment->create();

		$before = time();
		wp_update_comment(
			array(
				'comment_ID'      => $comment_id,
				'comment_content' => 'Updated comment ' . wp_generate_password( 6, false ),
			)
		);

		$entry = wp_presence_get_screen_revision( 'comment/' . $comment_id );
		$this->assertNotNull( $entry );
		$this->assertGreaterThanOrEqual( $before, (int) $entry['rev'] );
	}

	/**
	 * @covers ::wp_presence_bump_screen_revision
	 */
	public function test_bump_fires_revision_bumped_action() {
		wp_set_current_user( self::$admin_id );

		$captured = array();
		$callback = static function ( $key, $rev, $actor_id ) use ( &$captured ) {
			$captured[] = compact( 'key', 'rev', 'actor_id' );
		};
		add_action( 'wp_presence_screen_revision_bumped', $callback, 10, 3 );

		$before = time();
		wp_presence_bump_screen_revision( 'options/general' );

		remove_action( 'wp_presence_screen_revision_bumped', $callback, 10 );

		$this->assertCount( 1, $captured );
		$this->assertSame( 'options/general', $captured[0]['key'] );
		$this->assertGreaterThanOrEqual( $before, $captured[0]['rev'] );
		$this->assertSame( self::$admin_id, $captured[0]['actor_id'] );
	}

	/**
	 * @covers ::wp_presence_screen_heartbeat_received
	 */
	public function test_heartbeat_requires_edit_posts_capability() {
		// Use a non-`options/*` key so the viewer is gated on `edit_posts`
		// (the `options/*` prefix is gated on `manage_options` instead). Any
		// existing post already has a revision to read, since posts derive
		// theirs from post_modified_gmt rather than needing a bump first.
		$post_id = self::factory()->post->create();

		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$response = wp_presence_screen_heartbeat_received(
			array(),
			array( 'presence-screen-ping' => array( 'key' => 'post/' . $post_id ) ),
			'post'
		);

		$this->assertArrayNotHasKey(
			'presence-screen-rev',
			$response,
			'A subscriber without edit_posts should not learn screen-revision state via Heartbeat.'
		);
	}

	/**
	 * @covers ::wp_presence_screen_heartbeat_received
	 */
	public function test_heartbeat_matches_oversized_screen_key_after_normalization() {
		wp_set_current_user( self::$admin_id );
		$long_key = str_repeat( 'a', 200 );
		wp_presence_bump_screen_revision( $long_key );

		$response = wp_presence_screen_heartbeat_received(
			array(),
			array( 'presence-screen-ping' => array( 'key' => $long_key ) ),
			'long'
		);

		$this->assertArrayHasKey( 'presence-screen-rev', $response );
		$this->assertSame( substr( $long_key, 0, 191 ), $response['presence-screen-rev']['key'] );
	}

	/**
	 * @covers ::wp_presence_screen_heartbeat_received
	 */
	public function test_heartbeat_returns_current_revision_for_screen() {
		wp_set_current_user( self::$admin_id );
		$before = time();
		wp_presence_bump_screen_revision( 'options/general', self::$admin_id );

		// View as a *different* admin so the viewer can read an `options/*`
		// revision (the handler requires `manage_options` for that prefix)
		// without satisfying `actor_is_me`.
		wp_set_current_user( self::$admin2_id );

		$response = wp_presence_screen_heartbeat_received(
			array(),
			array( 'presence-screen-ping' => array( 'key' => 'options/general' ) ),
			'options-general'
		);

		$this->assertArrayHasKey( 'presence-screen-rev', $response );
		$payload = $response['presence-screen-rev'];
		$this->assertSame( 'options/general', $payload['key'] );
		$this->assertGreaterThanOrEqual( $before, (int) $payload['rev'] );
		$this->assertSame( self::$admin_id, (int) $payload['actor_id'] );
		$this->assertFalse( $payload['actor_is_me'] );
		$this->assertNotEmpty( $payload['actor_name'], 'Heartbeat should resolve the actor display name fresh.' );
		$this->assertNotEmpty( $payload['actor_avatar_url'], 'Heartbeat should carry the actor avatar URL.' );
		$this->assertNotEmpty( $payload['time_ago'], 'Heartbeat should carry a human-readable time diff.' );
	}

	/**
	 * @covers ::wp_presence_screen_heartbeat_received
	 */
	public function test_heartbeat_picks_up_renamed_actor() {
		wp_set_current_user( self::$admin_id );
		wp_presence_bump_screen_revision( 'options/general', self::$editor_id );

		// Rename the editor AFTER the bump — the heartbeat should reflect the new name.
		$renamed = 'Renamed Editor ' . wp_generate_password( 6, false );
		wp_update_user(
			array(
				'ID'           => self::$editor_id,
				'display_name' => $renamed,
			)
		);

		$response = wp_presence_screen_heartbeat_received(
			array(),
			array( 'presence-screen-ping' => array( 'key' => 'options/general' ) ),
			'options-general'
		);

		$this->assertSame( $renamed, $response['presence-screen-rev']['actor_name'] );
	}

	/**
	 * @covers ::wp_presence_screen_heartbeat_received
	 */
	public function test_heartbeat_flags_actor_is_me_for_current_user() {
		wp_set_current_user( self::$admin_id );
		wp_presence_bump_screen_revision( 'options/general', self::$admin_id );

		$response = wp_presence_screen_heartbeat_received(
			array(),
			array( 'presence-screen-ping' => array( 'key' => 'options/general' ) ),
			'options-general'
		);

		$this->assertTrue( $response['presence-screen-rev']['actor_is_me'] );
	}

	/**
	 * @covers ::wp_presence_screen_heartbeat_received
	 */
	public function test_heartbeat_returns_nothing_when_no_ping() {
		$response = wp_presence_screen_heartbeat_received( array(), array(), 'options-general' );
		$this->assertArrayNotHasKey( 'presence-screen-rev', $response );
	}

	/**
	 * @covers ::wp_presence_screen_heartbeat_received
	 */
	public function test_heartbeat_returns_nothing_for_unknown_screen() {
		$response = wp_presence_screen_heartbeat_received(
			array(),
			array( 'presence-screen-ping' => array( 'key' => 'options/never-saved' ) ),
			'options-general'
		);
		$this->assertArrayNotHasKey( 'presence-screen-rev', $response );
	}

	/**
	 * options/never-saved isn't one of the six known Settings pages, so it
	 * falls into the shared, size-bounded fallback option along with every
	 * other custom key — not a dedicated option of its own.
	 *
	 * @covers ::wp_presence_parse_screen_key_target
	 */
	public function test_unknown_options_page_is_treated_as_a_custom_key() {
		wp_set_current_user( self::$admin_id );

		wp_presence_bump_screen_revision( 'options/never-saved' );

		$this->assertArrayHasKey( 'options/never-saved', wp_presence_get_screen_revisions() );
	}

	/**
	 * @covers ::wp_presence_bump_screen_revision
	 */
	public function test_revision_map_is_bounded_by_limit() {
		wp_set_current_user( self::$admin_id );

		for ( $i = 0; $i < WP_PRESENCE_SCREEN_REV_LIMIT + 5; $i++ ) {
			wp_presence_bump_screen_revision( 'options/test-' . $i );
		}

		$map = wp_presence_get_screen_revisions();
		$this->assertLessThanOrEqual( WP_PRESENCE_SCREEN_REV_LIMIT, count( $map ) );
	}

	/**
	 * @covers ::wp_presence_current_screen_key
	 */
	public function test_current_screen_key_matches_save_path_for_settings() {
		wp_set_current_user( self::$admin_id );
		set_current_screen( 'options-general' );

		$this->assertSame( 'options/general', wp_presence_current_screen_key() );
	}

	/**
	 * @covers ::wp_presence_current_screen_key
	 */
	public function test_current_screen_key_filter_is_applied() {
		wp_set_current_user( self::$admin_id );
		set_current_screen( 'dashboard' );

		$callback = static function ( $key ) {
			return $key ?: 'custom-screen';
		};
		add_filter( 'wp_presence_current_screen_key', $callback );

		$this->assertSame( 'custom-screen', wp_presence_current_screen_key() );

		remove_filter( 'wp_presence_current_screen_key', $callback );
	}

	/**
	 * The post editor keys off the post being edited, not the request.
	 *
	 * @covers ::wp_presence_current_screen_key
	 */
	public function test_current_screen_key_reads_the_post_being_edited() {
		wp_set_current_user( self::$admin_id );
		set_current_screen( 'post' );

		$post_id                = self::factory()->post->create();
		$GLOBALS['post']        = get_post( $post_id );

		$this->assertSame( 'post/' . $post_id, wp_presence_current_screen_key() );

		unset( $GLOBALS['post'] );
	}

	/**
	 * @covers ::wp_presence_current_screen_key
	 */
	public function test_current_screen_key_reads_the_user_being_edited() {
		wp_set_current_user( self::$admin_id );
		set_current_screen( 'user-edit' );

		$_GET['user_id'] = self::$editor_id;

		$this->assertSame( 'user-edit/' . self::$editor_id, wp_presence_current_screen_key() );
	}

	/**
	 * Your own profile is the same screen as another user's edit screen, so it
	 * keys the same way and a change made elsewhere still marks it stale.
	 *
	 * @covers ::wp_presence_current_screen_key
	 */
	public function test_your_own_profile_keys_to_your_user_edit_screen() {
		wp_set_current_user( self::$admin_id );
		set_current_screen( 'profile' );

		$this->assertSame( 'user-edit/' . self::$admin_id, wp_presence_current_screen_key() );
	}

	/**
	 * @covers ::wp_presence_current_screen_key
	 */
	public function test_current_screen_key_reads_the_term_being_edited() {
		wp_set_current_user( self::$admin_id );
		set_current_screen( 'edit-tags' );

		$term_id            = self::factory()->term->create( array( 'taxonomy' => 'category' ) );
		$_GET['taxonomy']   = 'category';
		$_GET['tag_ID']     = $term_id;

		$this->assertSame( 'term/category/' . $term_id, wp_presence_current_screen_key() );
	}

	/**
	 * @covers ::wp_presence_current_screen_key
	 */
	public function test_current_screen_key_reads_the_comment_being_edited() {
		wp_set_current_user( self::$admin_id );
		set_current_screen( 'comment' );

		$comment_id = self::factory()->comment->create();
		$_GET['c']  = $comment_id;

		$this->assertSame( 'comment/' . $comment_id, wp_presence_current_screen_key() );
	}

	/**
	 * A covered screen base with nothing to key off produces no key rather than
	 * a partial one like `post/`.
	 *
	 * @covers ::wp_presence_current_screen_key
	 */
	public function test_a_covered_screen_with_no_object_produces_no_key() {
		wp_set_current_user( self::$admin_id );
		set_current_screen( 'user-edit' );

		$this->assertSame( '', wp_presence_current_screen_key() );
	}

	/**
	 * @covers ::wp_presence_current_screen_key
	 */
	public function test_there_is_no_screen_key_outside_the_admin() {
		wp_set_current_user( self::$admin_id );
		set_current_screen( 'front' );

		$this->assertSame( '', wp_presence_current_screen_key() );
	}

	/**
	 * Enqueues the banner against empty registries.
	 *
	 * wp_scripts() and wp_styles() are process globals, so without this a
	 * later test reads what an earlier one enqueued.
	 */
	private function enqueue_banner() {
		wp_deregister_script( 'wp-presence-stale-screen' );
		wp_deregister_script( 'wp-presence-tab-coordinator' );
		wp_deregister_style( 'wp-presence-stale-screen' );

		$scripts        = wp_scripts();
		$scripts->queue = array();
		$scripts->done  = array();

		$styles        = wp_styles();
		$styles->queue = array();
		$styles->done  = array();

		wp_presence_enqueue_stale_screen_banner();
	}

	/**
	 * Reads back the config object the banner script is handed.
	 *
	 * @return array|null Decoded config, or null when nothing was printed.
	 */
	private function banner_config() {
		$inline = wp_scripts()->get_data( 'wp-presence-stale-screen', 'before' );

		foreach ( (array) $inline as $script ) {
			if ( is_string( $script ) && preg_match( '/window\.wpPresenceStaleScreen = (.*);$/', $script, $m ) ) {
				return json_decode( $m[1], true );
			}
		}

		return null;
	}

	/**
	 * @covers ::wp_presence_enqueue_stale_screen_banner
	 */
	public function test_the_banner_carries_the_screen_key_and_the_revision_it_starts_from() {
		wp_set_current_user( self::$admin_id );
		set_current_screen( 'options-general' );

		$rev = wp_presence_bump_screen_revision( 'options/general', self::$admin2_id );

		$this->enqueue_banner();

		$this->assertTrue( wp_style_is( 'wp-presence-stale-screen', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'wp-presence-stale-screen', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'wp-presence-tab-coordinator', 'enqueued' ) );

		$config = $this->banner_config();
		$this->assertSame( 'options/general', $config['screenKey'] );
		$this->assertSame( $rev, $config['baselineRev'] );
		$this->assertArrayHasKey( 'reload', $config['strings'] );
	}

	/**
	 * An unvisited screen starts from zero, so the first bump is a change.
	 *
	 * @covers ::wp_presence_enqueue_stale_screen_banner
	 */
	public function test_a_screen_with_no_revision_yet_starts_the_baseline_at_zero() {
		wp_set_current_user( self::$admin_id );
		set_current_screen( 'options-writing' );

		$this->enqueue_banner();

		$this->assertSame( 0, $this->banner_config()['baselineRev'] );
	}

	/**
	 * @covers ::wp_presence_enqueue_stale_screen_banner
	 */
	public function test_the_banner_stays_off_screens_with_no_stale_detection() {
		wp_set_current_user( self::$admin_id );
		set_current_screen( 'dashboard' );

		$this->enqueue_banner();

		$this->assertFalse( wp_script_is( 'wp-presence-stale-screen', 'enqueued' ) );
	}

	/**
	 * @covers ::wp_presence_enqueue_stale_screen_banner
	 */
	public function test_the_banner_stays_off_the_front_end() {
		wp_set_current_user( self::$admin_id );
		set_current_screen( 'front' );

		$this->enqueue_banner();

		$this->assertFalse( wp_script_is( 'wp-presence-stale-screen', 'enqueued' ) );
	}

	/**
	 * @covers ::wp_presence_enqueue_stale_screen_banner
	 */
	public function test_a_user_without_edit_posts_gets_no_banner() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		set_current_screen( 'options-general' );

		$this->enqueue_banner();

		$this->assertFalse( wp_script_is( 'wp-presence-stale-screen', 'enqueued' ) );
	}

	/**
	 * The revision of a screen is only disclosed to someone who can already
	 * reach the object behind it.
	 *
	 * @dataProvider data_screens_out_of_reach
	 *
	 * @covers ::wp_presence_current_user_can_access_screen
	 *
	 * @param string $key_template Screen key with %d standing in for the object ID.
	 */
	public function test_a_screen_the_user_cannot_reach_is_not_disclosed( $key_template ) {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$ids = array(
			'user-edit/%d' => self::$admin_id,
			'term/category/%d' => self::factory()->term->create( array( 'taxonomy' => 'category' ) ),
			'comment/%d'   => self::factory()->comment->create(),
			'anything/%d'  => 1,
		);

		$this->assertFalse( wp_presence_current_user_can_access_screen( sprintf( $key_template, $ids[ $key_template ] ) ) );
	}

	public function data_screens_out_of_reach() {
		return array(
			'another user'  => array( 'user-edit/%d' ),
			'a term'        => array( 'term/category/%d' ),
			'a comment'     => array( 'comment/%d' ),
			'an unknown key' => array( 'anything/%d' ),
		);
	}

	/**
	 * Cron runs as no one on a schedule, so a revision bumped there would name
	 * a bystander as the actor.
	 *
	 * @covers ::wp_presence_is_admin_screen_save
	 * @covers ::wp_presence_on_profile_update
	 * @covers ::wp_presence_on_edited_term
	 * @covers ::wp_presence_on_edit_comment
	 * @covers ::wp_presence_on_post_updated
	 * @covers ::wp_presence_on_updated_option
	 */
	public function test_a_save_during_cron_bumps_nothing() {
		wp_set_current_user( self::$admin_id );
		set_current_screen( 'user-edit' );

		add_filter( 'wp_doing_cron', '__return_true' );

		wp_presence_on_profile_update( self::$editor_id );
		wp_presence_on_edited_term( 1, 1, 'category' );
		wp_presence_on_edit_comment( 1 );
		wp_presence_on_post_updated( 1, get_post( self::factory()->post->create() ), null );
		$_POST['option_page'] = 'media';
		wp_presence_on_updated_option( 'blogname' );

		remove_filter( 'wp_doing_cron', '__return_true' );

		$this->assertNull( wp_presence_get_screen_revision( 'user-edit/' . self::$editor_id ) );
		$this->assertNull( wp_presence_get_screen_revision( 'options/media' ) );
		$this->assertSame( array(), wp_presence_get_screen_revisions() );
	}

	/**
	 * Saving a Settings page fires updated_option once per changed option, and
	 * each would otherwise bump the same screen again.
	 *
	 * @covers ::wp_presence_on_updated_option
	 */
	public function test_one_settings_save_bumps_the_screen_once() {
		wp_set_current_user( self::$admin_id );
		set_current_screen( 'options-reading' );

		$_POST['option_page'] = 'reading';

		wp_presence_on_updated_option( 'blogname' );
		$first = wp_presence_get_screen_revision( 'options/reading' );

		wp_presence_on_updated_option( 'blogdescription' );
		$second = wp_presence_get_screen_revision( 'options/reading' );

		$this->assertSame( $first, $second );
	}

	/**
	 * A revision is a change to the post everyone is looking at, and a stored
	 * revision is a separate row that nobody has open.
	 *
	 * @covers ::wp_presence_on_post_updated
	 */
	public function test_a_stored_revision_is_not_a_change_to_its_parent() {
		wp_set_current_user( self::$admin_id );
		set_current_screen( 'post' );

		$post_id     = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$revision_id = wp_save_post_revision( $post_id );

		wp_presence_on_post_updated( $revision_id, get_post( $revision_id ), null );

		$this->assertSame( array(), wp_presence_get_screen_revisions() );
	}

	/**
	 * @covers ::wp_presence_on_post_updated
	 */
	public function test_an_auto_draft_is_not_a_change_anyone_needs_to_see() {
		wp_set_current_user( self::$admin_id );
		set_current_screen( 'post' );

		$post_id = self::factory()->post->create( array( 'post_status' => 'auto-draft' ) );

		wp_presence_on_post_updated( $post_id, get_post( $post_id ), null );

		$this->assertSame( array(), wp_presence_get_screen_revisions() );
	}

	/**
	 * @covers ::wp_presence_get_screen_revision
	 */
	public function test_an_empty_screen_key_has_no_revision() {
		$this->assertNull( wp_presence_get_screen_revision( '' ) );
	}

	/**
	 * @covers ::wp_presence_get_screen_revision
	 */
	public function test_a_deleted_post_has_no_revision() {
		$post_id = self::factory()->post->create();
		wp_delete_post( $post_id, true );

		$this->assertNull( wp_presence_get_screen_revision( 'post/' . $post_id ) );
	}

	/**
	 * @covers ::wp_presence_screen_heartbeat_received
	 */
	public function test_heartbeat_says_nothing_about_a_screen_nobody_has_saved() {
		wp_set_current_user( self::$admin_id );

		$response = wp_presence_screen_heartbeat_received(
			array(),
			array( 'presence-screen-ping' => array( 'key' => 'options/discussion' ) ),
			'dashboard'
		);

		$this->assertArrayNotHasKey( 'presence-screen-rev', $response );
	}

	/**
	 * A revision with no actor still reports the change, just without a name or
	 * a relative time to attribute it to.
	 *
	 * @covers ::wp_presence_screen_heartbeat_received
	 */
	public function test_heartbeat_reports_an_unattributed_change() {
		wp_set_current_user( self::$admin_id );

		update_option(
			'wp_presence_screen_rev_options_media',
			array(
				'rev'      => 7,
				'actor_id' => 0,
				'time'     => 0,
			),
			false
		);

		$response = wp_presence_screen_heartbeat_received(
			array(),
			array( 'presence-screen-ping' => array( 'key' => 'options/media' ) ),
			'dashboard'
		);

		$rev = $response['presence-screen-rev'];
		$this->assertSame( 7, $rev['rev'] );
		$this->assertSame( '', $rev['actor_name'] );
		$this->assertSame( '', $rev['time_ago'] );
		$this->assertFalse( $rev['actor_is_me'] );
	}
}
