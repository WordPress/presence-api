<?php
/**
 * Tests for stale-screen detection.
 *
 * @package Presence_API
 *
 * @group presence
 */
class WP_Test_Presence_Screen_Revisions extends WP_UnitTestCase {

	private static $admin_id;
	private static $editor_id;

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$admin_id  = $factory->user->create( array( 'role' => 'administrator' ) );
		self::$editor_id = $factory->user->create( array( 'role' => 'editor' ) );
	}

	public function tear_down() {
		delete_option( 'wp_presence_screen_revisions' );
		unset( $_POST['option_page'] );
		parent::tear_down();
	}

	/**
	 * @covers ::wp_presence_bump_screen_revision
	 */
	public function test_bump_increments_revision_and_records_actor() {
		wp_set_current_user( self::$admin_id );

		$first  = wp_presence_bump_screen_revision( 'options/general' );
		$second = wp_presence_bump_screen_revision( 'options/general' );

		$this->assertSame( 1, $first );
		$this->assertSame( 2, $second );

		$entry = wp_presence_get_screen_revision( 'options/general' );
		$this->assertNotNull( $entry );
		$this->assertSame( 2, (int) $entry['rev'] );
		$this->assertSame( self::$admin_id, (int) $entry['actor_id'] );
		$this->assertArrayNotHasKey(
			'actor_name',
			$entry,
			'Display name is resolved fresh on heartbeat — not stored — so renames show immediately.'
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

		update_option( 'blogname', 'New Title ' . wp_generate_password( 6, false ) );

		$entry = wp_presence_get_screen_revision( 'options/general' );
		$this->assertNotNull( $entry );
		$this->assertSame( 1, (int) $entry['rev'] );
	}

	/**
	 * @covers ::wp_presence_on_updated_option
	 */
	public function test_updated_option_does_not_bump_without_option_page() {
		wp_set_current_user( self::$admin_id );
		set_current_screen( 'options-general' );
		// No $_POST['option_page']: this looks like a side-effect option update, not a Settings page save.

		update_option( 'blogname', 'Side Effect ' . wp_generate_password( 6, false ) );

		$this->assertSame( array(), wp_presence_get_screen_revisions() );
	}

	/**
	 * @covers ::wp_presence_on_post_updated
	 */
	public function test_post_updated_bumps_post_screen() {
		wp_set_current_user( self::$admin_id );
		set_current_screen( 'post' );

		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		// factory->post->create() runs save_post; assert it produced a bump.
		$entry = wp_presence_get_screen_revision( 'post/' . $post_id );
		$this->assertNull( $entry, 'A fresh insert is not "post_updated" — only updates bump.' );

		wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => 'Updated title ' . wp_generate_password( 6, false ),
			)
		);

		$entry = wp_presence_get_screen_revision( 'post/' . $post_id );
		$this->assertNotNull( $entry );
		$this->assertSame( 1, (int) $entry['rev'] );
	}

	/**
	 * @covers ::wp_presence_on_post_updated
	 */
	public function test_post_updated_skips_autosave_and_revision() {
		wp_set_current_user( self::$admin_id );
		set_current_screen( 'post' );

		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		delete_option( 'wp_presence_screen_revisions' );

		// Autosaves and revisions go through post_updated too, so the hook
		// must filter them out — otherwise every autosave tick would bump.
		wp_create_post_autosave(
			array(
				'post_ID'      => $post_id,
				'post_type'    => 'post',
				'post_content' => 'Autosaved content',
				'post_title'   => 'Autosaved title',
			)
		);

		$this->assertNull(
			wp_presence_get_screen_revision( 'post/' . $post_id ),
			'Autosaves should not bump the parent post\'s screen revision.'
		);
	}

	/**
	 * @covers ::wp_presence_on_profile_update
	 */
	public function test_profile_update_bumps_user_screen() {
		wp_set_current_user( self::$admin_id );
		set_current_screen( 'user-edit' );

		wp_update_user(
			array(
				'ID'           => self::$editor_id,
				'display_name' => 'Edited Editor ' . wp_generate_password( 6, false ),
			)
		);

		$entry = wp_presence_get_screen_revision( 'user-edit/' . self::$editor_id );
		$this->assertNotNull( $entry );
		$this->assertSame( 1, (int) $entry['rev'] );
		$this->assertSame( self::$admin_id, (int) $entry['actor_id'] );
	}

	/**
	 * @covers ::wp_presence_on_edited_term
	 */
	public function test_edited_term_bumps_term_screen() {
		wp_set_current_user( self::$admin_id );
		set_current_screen( 'edit-tags' );

		$term_id = self::factory()->term->create( array( 'taxonomy' => 'category' ) );
		delete_option( 'wp_presence_screen_revisions' );

		wp_update_term( $term_id, 'category', array( 'description' => 'Updated' ) );

		$entry = wp_presence_get_screen_revision( 'term/category/' . $term_id );
		$this->assertNotNull( $entry );
		$this->assertSame( 1, (int) $entry['rev'] );
	}

	/**
	 * @covers ::wp_presence_on_edit_comment
	 */
	public function test_edit_comment_bumps_comment_screen() {
		wp_set_current_user( self::$admin_id );
		set_current_screen( 'comment' );

		$comment_id = self::factory()->comment->create();
		delete_option( 'wp_presence_screen_revisions' );

		wp_update_comment(
			array(
				'comment_ID'      => $comment_id,
				'comment_content' => 'Updated comment ' . wp_generate_password( 6, false ),
			)
		);

		$entry = wp_presence_get_screen_revision( 'comment/' . $comment_id );
		$this->assertNotNull( $entry );
		$this->assertSame( 1, (int) $entry['rev'] );
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

		wp_presence_bump_screen_revision( 'options/general' );

		remove_action( 'wp_presence_screen_revision_bumped', $callback, 10 );

		$this->assertCount( 1, $captured );
		$this->assertSame( 'options/general', $captured[0]['key'] );
		$this->assertSame( 1, $captured[0]['rev'] );
		$this->assertSame( self::$admin_id, $captured[0]['actor_id'] );
	}

	/**
	 * @covers ::wp_presence_screen_heartbeat_received
	 */
	public function test_heartbeat_requires_edit_posts_capability() {
		wp_presence_bump_screen_revision( 'options/general', self::$admin_id );

		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$response = wp_presence_screen_heartbeat_received(
			array(),
			array( 'presence-screen-ping' => array( 'key' => 'options/general' ) ),
			'options-general'
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
	public function test_heartbeat_caps_oversized_screen_key() {
		wp_set_current_user( self::$admin_id );
		// 200-char key is bumped on the server but the heartbeat handler
		// only looks up the first 191 chars, so it should not match.
		$long_key = str_repeat( 'a', 200 );
		wp_presence_bump_screen_revision( $long_key );

		$response = wp_presence_screen_heartbeat_received(
			array(),
			array( 'presence-screen-ping' => array( 'key' => $long_key ) ),
			'long'
		);

		$this->assertArrayNotHasKey( 'presence-screen-rev', $response );
	}

	/**
	 * @covers ::wp_presence_screen_heartbeat_received
	 */
	public function test_heartbeat_returns_current_revision_for_screen() {
		wp_set_current_user( self::$admin_id );
		wp_presence_bump_screen_revision( 'options/general', self::$admin_id );

		wp_set_current_user( self::$editor_id );

		$response = wp_presence_screen_heartbeat_received(
			array(),
			array( 'presence-screen-ping' => array( 'key' => 'options/general' ) ),
			'options-general'
		);

		$this->assertArrayHasKey( 'presence-screen-rev', $response );
		$payload = $response['presence-screen-rev'];
		$this->assertSame( 'options/general', $payload['key'] );
		$this->assertSame( 1, (int) $payload['rev'] );
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

		add_filter(
			'wp_presence_current_screen_key',
			static function ( $key ) {
				return $key ?: 'custom-screen';
			}
		);

		$this->assertSame( 'custom-screen', wp_presence_current_screen_key() );

		remove_all_filters( 'wp_presence_current_screen_key' );
	}
}
