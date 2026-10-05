<?php
/**
 * Tests for the feature switches.
 *
 * @package Presence_API
 *
 * @group presence
 */

class WP_Test_Presence_Features extends WP_Presence_UnitTestCase {

	/**
	 * The hooks the post-locks switch decides, as registered in default-filters.php.
	 *
	 * @var array<int, array{0: string, 1: string, 2: int}>
	 */
	private static $post_lock_hooks = array(
		array( 'heartbeat_received', 'wp_presence_prime_heartbeat_locks', 5 ),
		array( 'heartbeat_received', 'wp_presence_bridge_post_lock', 11 ),
		array( 'get_post_metadata', 'wp_presence_get_post_lock', 10 ),
		array( 'the_posts', 'wp_presence_prime_post_list_locks', 10 ),
		array( 'update_post_metadata', 'wp_presence_update_post_lock', 10 ),
		array( 'delete_post_metadata', 'wp_presence_delete_post_lock', 10 ),
	);

	public function tear_down() {
		unset( $_POST['wp_presence_network_features'], $_POST['wp_presence_network_recording'] );
		parent::tear_down();
	}

	private function render( $callback, ...$args ) {
		ob_start();
		$callback( ...$args );

		return ob_get_clean();
	}

	/**
	 * Re-runs default-filters.php the way the plugin does at load, with the
	 * post-locks switch in the given position.
	 *
	 * The file is plain add_filter() calls, so running it again re-registers
	 * every other hook in place rather than doubling it.
	 */
	private function register_hooks_with_post_locks( $enabled ) {
		foreach ( self::$post_lock_hooks as $hook ) {
			remove_filter( $hook[0], $hook[1], $hook[2] );
		}

		update_option( 'wp_presence_features', array( 'post-locks' => $enabled ? 1 : 0 ) );
		include WP_PRESENCE_PLUGIN_DIR . 'includes/default-filters.php';
	}

	/**
	 * Existing sites have never seen the option, and a piece added in a later
	 * release must not switch itself off on them.
	 *
	 * @covers ::wp_presence_feature_enabled
	 */
	public function test_a_feature_with_no_stored_choice_is_on() {
		delete_option( 'wp_presence_features' );
		$this->assertTrue( wp_presence_feature_enabled( 'post-locks' ) );

		update_option( 'wp_presence_features', array( 'something-else' => 0 ) );
		$this->assertTrue( wp_presence_feature_enabled( 'post-locks' ), 'Another feature switched off says nothing about this one.' );
	}

	/**
	 * @covers ::wp_presence_feature_enabled
	 */
	public function test_a_feature_switched_off_on_the_site_is_off() {
		update_option( 'wp_presence_features', array( 'post-locks' => 0 ) );

		$this->assertFalse( wp_presence_feature_enabled( 'post-locks' ) );
	}

	/**
	 * @covers ::wp_presence_feature_enabled
	 */
	public function test_the_filter_has_the_last_word() {
		update_option( 'wp_presence_features', array( 'post-locks' => 0 ) );
		add_filter(
			'wp_presence_feature_enabled',
			static function ( $enabled, $feature ) {
				return 'post-locks' === $feature ? true : $enabled;
			},
			10,
			2
		);

		$this->assertTrue( wp_presence_feature_enabled( 'post-locks' ) );
	}

	/**
	 * Matches the recording switch: the network turning a feature off wins,
	 * and a site cannot turn it back on.
	 *
	 * @group multisite
	 *
	 * @covers ::wp_presence_feature_enabled
	 */
	public function test_the_network_switching_a_feature_off_wins_over_the_site() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'The network switch only exists on multisite.' );
		}

		update_option( 'wp_presence_features', array( 'post-locks' => 1 ) );
		update_site_option( 'wp_presence_network_features', array( 'post-locks' => 0 ) );

		$this->assertFalse( wp_presence_feature_enabled( 'post-locks' ) );

		update_site_option( 'wp_presence_network_features', array( 'post-locks' => 1 ) );
		update_option( 'wp_presence_features', array( 'post-locks' => 0 ) );

		$this->assertFalse( wp_presence_feature_enabled( 'post-locks' ), 'The network leaving it on does not override a site that switched it off.' );
	}

	/**
	 * Switching post locks off leaves _edit_lock in post meta, as core keeps it.
	 *
	 * @covers ::wp_presence_feature_enabled
	 */
	public function test_switching_post_locks_off_leaves_their_hooks_unregistered() {
		$this->register_hooks_with_post_locks( false );

		foreach ( self::$post_lock_hooks as $hook ) {
			$this->assertFalse( has_filter( $hook[0], $hook[1] ), "{$hook[1]} should not be hooked to {$hook[0]}." );
		}

		$this->register_hooks_with_post_locks( true );

		foreach ( self::$post_lock_hooks as $hook ) {
			$this->assertSame( $hook[2], has_filter( $hook[0], $hook[1] ), "{$hook[1]} should be hooked to {$hook[0]} at {$hook[2]}." );
		}
	}

	/**
	 * With the bridge unhooked, a lock goes to post meta and reads back from it.
	 *
	 * @covers ::wp_presence_feature_enabled
	 */
	public function test_post_locks_switched_off_fall_back_to_post_meta() {
		require_once ABSPATH . 'wp-admin/includes/post.php';

		$this->register_hooks_with_post_locks( false );

		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$post_id = self::factory()->post->create();
		wp_set_current_user( $user_id );

		wp_set_post_lock( $post_id );

		global $wpdb;
		$stored = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_edit_lock'", $post_id ) );

		$this->assertNotEmpty( $stored, 'The lock should be written to post meta.' );
		$this->assertStringEndsWith( ':' . $user_id, $stored );
	}

	/**
	 * @covers ::wp_presence_sanitize_features
	 */
	public function test_sanitizing_stores_every_feature_and_drops_the_rest() {
		$this->assertSame(
			array( 'post-locks' => 0 ),
			wp_presence_sanitize_features( array( 'not-a-feature' => '1' ) ),
			'A feature that posted nothing is stored as off, and unknown keys are dropped.'
		);
		$this->assertSame( array( 'post-locks' => 1 ), wp_presence_sanitize_features( array( 'post-locks' => '1' ) ) );
		$this->assertSame( array( 'post-locks' => 0 ), wp_presence_sanitize_features( 'garbage' ) );
	}

	/**
	 * @covers ::wp_presence_register_settings
	 */
	public function test_the_option_is_allowed_through_options_php() {
		wp_presence_register_settings();

		$this->assertArrayHasKey( 'wp_presence_features', get_registered_settings() );
	}

	/**
	 * @covers ::wp_presence_render_recording_field
	 * @covers ::wp_presence_render_feature_checkboxes
	 */
	public function test_each_feature_has_a_checkbox_beside_the_recording_switch() {
		delete_option( 'wp_presence_features' );
		$on = $this->render( 'wp_presence_render_recording_field' );

		$this->assertStringContainsString( 'name="wp_presence_features[post-locks]" value="1"  checked=\'checked\'', $on, 'A feature never chosen renders checked.' );
		// An unchecked box posts nothing, so the hidden field is what carries the off.
		$this->assertStringContainsString( 'name="wp_presence_features[post-locks]" value="0"', $on );

		update_option( 'wp_presence_features', array( 'post-locks' => 0 ) );

		$this->assertStringNotContainsString( 'value="1"  checked=\'checked\'', $this->render( 'wp_presence_render_feature_checkboxes', 'wp_presence_features', get_option( 'wp_presence_features' ) ) );
	}

	/**
	 * @covers ::wp_presence_save_network_settings
	 */
	public function test_the_network_features_save_both_ways() {
		$_POST['wp_presence_network_features'] = array( 'post-locks' => '0' );
		wp_presence_save_network_settings();

		$this->assertSame( array( 'post-locks' => 0 ), get_site_option( 'wp_presence_network_features' ) );

		$_POST['wp_presence_network_features'] = array( 'post-locks' => '1' );
		wp_presence_save_network_settings();

		$this->assertSame( array( 'post-locks' => 1 ), get_site_option( 'wp_presence_network_features' ) );
	}
}
