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

	/**
	 * The hooks the admin-bar switch decides, as registered in default-filters.php.
	 *
	 * @var array<int, array{0: string, 1: string, 2: int}>
	 */
	private static $admin_bar_hooks = array(
		array( 'admin_bar_menu', 'wp_presence_admin_bar_node', 80 ),
		array( 'admin_enqueue_scripts', 'wp_presence_admin_bar_assets', 10 ),
		array( 'wp_enqueue_scripts', 'wp_presence_admin_bar_assets', 10 ),
		array( 'heartbeat_received', 'wp_presence_admin_bar_heartbeat_received', 13 ),
	);

	/**
	 * The hooks the post-list switch decides, as registered in default-filters.php.
	 *
	 * @var array<int, array{0: string, 1: string, 2: int}>
	 */
	private static $post_list_hooks = array(
		array( 'admin_init', 'wp_presence_register_post_list_columns', 10 ),
		array( 'heartbeat_received', 'wp_presence_editors_column_heartbeat_received', 13 ),
	);

	public function tear_down() {
		unset( $_POST['wp_presence_network_features'], $_REQUEST['_wpnonce'], $_GET['updated'], $GLOBALS['title'] );
		set_current_screen( 'front' );
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
	 * Re-runs default-filters.php with the admin-bar switch in the given position.
	 */
	private function register_hooks_with_admin_bar( $enabled ) {
		foreach ( self::$admin_bar_hooks as $hook ) {
			remove_filter( $hook[0], $hook[1], $hook[2] );
		}

		update_option( 'wp_presence_features', array( 'admin-bar' => $enabled ? 1 : 0 ) );
		include WP_PRESENCE_PLUGIN_DIR . 'includes/default-filters.php';
	}

	/**
	 * Re-runs default-filters.php the way the plugin does at load, with the
	 * post-list switch in the given position.
	 */
	private function register_hooks_with_post_list( $enabled ) {
		foreach ( self::$post_list_hooks as $hook ) {
			remove_filter( $hook[0], $hook[1], $hook[2] );
		}

		update_option( 'wp_presence_features', array( 'post-list' => $enabled ? 1 : 0 ) );
		include WP_PRESENCE_PLUGIN_DIR . 'includes/default-filters.php';
	}

	/**
	 * Existing sites have never seen the option, and a piece added in a later
	 * release must not switch itself off on them.
	 *
	 * @covers ::wp_presence_feature_enabled
	 * @covers ::wp_presence_feature_stored_choice
	 */
	public function test_a_feature_with_no_stored_choice_is_on() {
		delete_option( 'wp_presence_features' );
		$this->assertTrue( wp_presence_feature_enabled( 'post-locks' ) );

		update_option( 'wp_presence_features', array( 'something-else' => 0 ) );
		$this->assertTrue( wp_presence_feature_enabled( 'post-locks' ), 'Another feature switched off says nothing about this one.' );
	}

	/**
	 * @covers ::wp_presence_feature_enabled
	 * @covers ::wp_presence_feature_stored_choice
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
	 * @group ms-required
	 *
	 * @covers ::wp_presence_feature_enabled
	 */
	public function test_the_network_switching_a_feature_off_wins_over_the_site() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires multisite.' );
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
	 * Switching the admin bar off takes the faces away and leaves the screen token every admin page needs.
	 *
	 * @covers ::wp_presence_feature_enabled
	 */
	public function test_switching_the_admin_bar_off_leaves_its_hooks_unregistered() {
		$this->register_hooks_with_admin_bar( false );

		foreach ( self::$admin_bar_hooks as $hook ) {
			$this->assertFalse( has_filter( $hook[0], $hook[1] ), "{$hook[1]} should not be hooked to {$hook[0]}." );
		}

		$this->assertSame( 10, has_filter( 'wp_refresh_nonces', 'wp_presence_refresh_screen_token' ), 'presence-ping.js still needs a fresh screen token.' );

		$this->register_hooks_with_admin_bar( true );

		foreach ( self::$admin_bar_hooks as $hook ) {
			$this->assertSame( $hook[2], has_filter( $hook[0], $hook[1] ), "{$hook[1]} should be hooked to {$hook[0]} at {$hook[2]}." );
		}
	}

	/**
	 * Switching the post list off leaves the Editors column unhooked.
	 *
	 * @covers ::wp_presence_feature_enabled
	 */
	public function test_switching_post_list_off_leaves_its_hooks_unregistered() {
		$this->register_hooks_with_post_list( false );

		foreach ( self::$post_list_hooks as $hook ) {
			$this->assertFalse( has_filter( $hook[0], $hook[1] ), "{$hook[1]} should not be hooked to {$hook[0]}." );
		}

		$this->register_hooks_with_post_list( true );

		foreach ( self::$post_list_hooks as $hook ) {
			$this->assertSame( $hook[2], has_filter( $hook[0], $hook[1] ), "{$hook[1]} should be hooked to {$hook[0]} at {$hook[2]}." );
		}
	}

	/**
	 * Switching the synced pattern notice off leaves it unhooked from the block editor.
	 *
	 * @covers ::wp_presence_feature_enabled
	 */
	public function test_switching_the_synced_pattern_notice_off_leaves_it_unhooked() {
		foreach ( array( false, true ) as $enabled ) {
			remove_action( 'enqueue_block_editor_assets', 'wp_presence_enqueue_synced_pattern_notice' );
			update_option( 'wp_presence_features', array( 'synced-patterns' => $enabled ? 1 : 0 ) );
			include WP_PRESENCE_PLUGIN_DIR . 'includes/default-filters.php';

			$this->assertSame( $enabled ? 10 : false, has_action( 'enqueue_block_editor_assets', 'wp_presence_enqueue_synced_pattern_notice' ) );
		}
	}

	/**
	 * With its hooks gone, the admin bar renders without the presence node.
	 *
	 * @covers ::wp_presence_feature_enabled
	 */
	public function test_the_admin_bar_switched_off_adds_no_node() {
		require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		foreach ( array( false, true ) as $enabled ) {
			$this->register_hooks_with_admin_bar( $enabled );

			$bar = new WP_Admin_Bar();
			do_action_ref_array( 'admin_bar_menu', array( &$bar ) );

			$this->assertSame( $enabled, null !== $bar->get_node( 'presence-online' ) );
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
	 * Only the unhooked lock filter would read what priming fetches, so the
	 * Active Posts widget's call must not query the table for nothing.
	 *
	 * @covers ::wp_presence_prime_post_locks
	 */
	public function test_post_locks_switched_off_skip_priming() {
		global $wpdb;

		$this->register_hooks_with_post_locks( false );
		$post_id = self::factory()->post->create();

		$queries = $wpdb->num_queries;
		wp_presence_prime_post_locks( array( $post_id ) );

		$this->assertSame( $queries, $wpdb->num_queries );
	}

	/**
	 * @covers ::wp_presence_sanitize_features
	 * @covers ::wp_presence_get_features
	 */
	public function test_sanitizing_stores_every_feature_and_drops_the_rest() {
		$this->assertSame(
			array(
				'post-locks' => 0,
				'admin-bar'  => 0,
				'post-list'  => 0,
				'synced-patterns' => 0,
			),
			wp_presence_sanitize_features( array( 'not-a-feature' => '1' ) ),
			'A feature that posted nothing is stored as off, and unknown keys are dropped.'
		);
		$this->assertSame(
			array(
				'post-locks' => 1,
				'admin-bar'  => 0,
				'post-list'  => 0,
				'synced-patterns' => 0,
			),
			wp_presence_sanitize_features( array( 'post-locks' => '1' ) )
		);
		$this->assertSame(
			array(
				'post-locks' => 0,
				'admin-bar'  => 0,
				'post-list'  => 0,
				'synced-patterns' => 0,
			),
			wp_presence_sanitize_features( 'garbage' )
		);
	}

	/**
	 * register_setting() is what lets options.php save the plugin's own page.
	 *
	 * @covers ::wp_presence_register_feature_settings
	 */
	public function test_the_option_is_saved_from_the_plugins_own_page() {
		wp_presence_register_feature_settings();

		$registered = get_registered_settings();

		$this->assertSame( 'presence-api', $registered['wp_presence_features']['group'] );
	}

	/**
	 * The recording switch is the setting a site keeps, so the feature switches stay off its screen.
	 *
	 * @covers ::wp_presence_register_feature_settings
	 * @covers ::wp_presence_register_settings
	 */
	public function test_each_feature_has_a_row_on_the_plugins_page_and_none_on_settings_general() {
		global $wp_settings_fields;

		wp_presence_register_settings();
		wp_presence_register_feature_settings();

		$this->assertSame( 'Post locks', $wp_settings_fields['presence-api']['wp_presence_features']['wp_presence_features_post-locks']['title'] );
		$this->assertSame( 'Admin bar', $wp_settings_fields['presence-api']['wp_presence_features']['wp_presence_features_admin-bar']['title'] );
		$this->assertSame( 'Posts list', $wp_settings_fields['presence-api']['wp_presence_features']['wp_presence_features_post-list']['title'] );
		$this->assertArrayNotHasKey( 'wp_presence_features_post-locks', $wp_settings_fields['general']['default'] );
		$this->assertArrayNotHasKey( 'wp_presence_features_admin-bar', $wp_settings_fields['general']['default'] );
		$this->assertArrayNotHasKey( 'wp_presence_features_post-list', $wp_settings_fields['general']['default'] );
		$this->assertStringNotContainsString( 'wp_presence_features', $this->render( 'wp_presence_render_recording_field' ) );
	}

	/**
	 * @covers ::wp_presence_add_features_page
	 */
	public function test_the_page_sits_under_settings() {
		global $submenu;

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		wp_presence_add_features_page();

		$this->assertContains( 'presence-api', wp_list_pluck( $submenu['options-general.php'], 2 ) );
	}

	/**
	 * @covers ::wp_presence_render_feature_field
	 */
	public function test_a_feature_checkbox_follows_the_stored_option() {
		$args     = array(
			'label_for'   => 'wp_presence_features_post-locks',
			'option'      => 'wp_presence_features',
			'feature'     => 'post-locks',
			'description' => 'Keep post locks in the presence table.',
		);
		$checkbox = '/<input type="checkbox"[^>]*name="wp_presence_features\[post-locks\]"[^>]*>/';

		delete_option( 'wp_presence_features' );
		$on = $this->render( 'wp_presence_render_feature_field', $args );
		preg_match( $checkbox, $on, $box );

		$this->assertStringContainsString( 'checked', $box[0], 'A feature never chosen renders checked.' );
		// An unchecked box posts nothing, so the hidden field is what carries the off.
		$this->assertStringContainsString( '<input type="hidden" name="wp_presence_features[post-locks]" value="0"', $on );

		update_option( 'wp_presence_features', array( 'post-locks' => 0 ) );
		preg_match( $checkbox, $this->render( 'wp_presence_render_feature_field', $args ), $box );

		$this->assertStringNotContainsString( 'checked', $box[0] );
	}

	/**
	 * A site admin would otherwise see a checked box for a feature that is off.
	 *
	 * @group ms-required
	 *
	 * @covers ::wp_presence_render_feature_field
	 */
	public function test_a_site_is_told_when_the_network_has_switched_a_feature_off() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires multisite.' );
		}

		$args = array(
			'label_for'   => 'wp_presence_features_post-locks',
			'option'      => 'wp_presence_features',
			'feature'     => 'post-locks',
			'description' => 'Keep post locks in the presence table.',
		);

		$this->assertStringNotContainsString( 'Switched off for every site', $this->render( 'wp_presence_render_feature_field', $args ) );

		update_site_option( 'wp_presence_network_features', array( 'post-locks' => 0 ) );

		$this->assertStringContainsString( 'Switched off for every site', $this->render( 'wp_presence_render_feature_field', $args ) );
	}

	/**
	 * Stopped at the redirect, which is the last thing the handler does before it exits.
	 *
	 * @group ms-required
	 *
	 * @covers ::wp_presence_save_network_features
	 */
	public function test_the_network_page_saves_both_ways() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires multisite.' );
		}

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		grant_super_admin( $admin_id );
		wp_set_current_user( $admin_id );
		add_filter(
			'wp_redirect',
			static function () {
				throw new RuntimeException( 'redirected' );
			}
		);

		$saved = array();

		foreach ( array( '0', '1' ) as $choice ) {
			$_REQUEST['_wpnonce']                  = wp_create_nonce( 'wp_presence_network_features' );
			$_POST['wp_presence_network_features'] = array( 'post-locks' => $choice );

			try {
				wp_presence_save_network_features();
			} catch ( RuntimeException $redirected ) {
				$saved[] = get_site_option( 'wp_presence_network_features' );
			}
		}

		$this->assertSame(
			array(
				array(
					'post-locks' => 0,
					'admin-bar'  => 0,
					'post-list'  => 0,
					'synced-patterns' => 0,
				),
				array(
					'post-locks' => 1,
					'admin-bar'  => 0,
					'post-list'  => 0,
					'synced-patterns' => 0,
				),
			),
			$saved
		);
	}

	/**
	 * @covers ::wp_presence_render_features_page
	 * @covers ::wp_presence_render_features_section
	 * @covers ::wp_presence_render_feature_field
	 */
	public function test_the_site_page_is_a_form_options_php_can_save() {
		$GLOBALS['title'] = 'Presence API';
		wp_presence_register_feature_settings();

		$page = $this->render( 'wp_presence_render_features_page' );

		$this->assertStringContainsString( '<h1>Presence API</h1>', $page );
		$this->assertStringContainsString( 'action="options.php"', $page );
		$this->assertMatchesRegularExpression( '/name=[\'"]option_page[\'"] value=[\'"]presence-api[\'"]/', $page, 'options.php saves only the group the form names.' );
		$this->assertStringContainsString( 'name="wp_presence_features[post-locks]" value="1"', $page );
		$this->assertStringContainsString( 'while WordPress core adopts each feature', $page );
	}

	/**
	 * @group ms-required
	 *
	 * @covers ::wp_presence_add_network_features_page
	 */
	public function test_the_network_page_sits_under_network_settings() {
		global $submenu;

		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires multisite.' );
		}

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		grant_super_admin( $admin_id );
		wp_set_current_user( $admin_id );
		wp_presence_add_network_features_page();

		$this->assertContains( 'presence-api', wp_list_pluck( $submenu['settings.php'], 2 ) );
	}

	/**
	 * options.php only saves site options, so the network form has to post to its own handler with its own nonce.
	 *
	 * @group ms-required
	 *
	 * @covers ::wp_presence_render_network_features_page
	 * @covers ::wp_presence_render_features_section
	 * @covers ::wp_presence_render_feature_field
	 */
	public function test_the_network_page_posts_to_its_own_handler() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires multisite.' );
		}

		$GLOBALS['title'] = 'Presence API';
		set_current_screen( 'settings-network' );
		wp_presence_register_feature_settings();
		update_site_option( 'wp_presence_network_features', array( 'post-locks' => 0 ) );

		$page = $this->render( 'wp_presence_render_network_features_page' );

		$this->assertStringContainsString( 'edit.php?action=wp_presence_features', $page );
		$this->assertStringContainsString( 'name="_wpnonce"', $page );
		$this->assertMatchesRegularExpression( '/<input type="checkbox"[^>]*name="wp_presence_network_features\[post-locks\]" value="1"\s*\/>/', $page, 'The box follows the network option, which is off.' );
		$this->assertStringContainsString( 'turns it off on every site', $page );
		$this->assertStringNotContainsString( 'Settings saved.', $page );

		$_GET['updated'] = 'true';

		$this->assertStringContainsString( 'Settings saved.', $this->render( 'wp_presence_render_network_features_page' ) );
	}

	/**
	 * A valid nonce is not enough: a site administrator on the network must not change every site.
	 *
	 * @group ms-required
	 *
	 * @covers ::wp_presence_save_network_features
	 */
	public function test_the_network_page_refuses_a_user_who_cannot_manage_the_network() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires multisite.' );
		}

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_REQUEST['_wpnonce']                  = wp_create_nonce( 'wp_presence_network_features' );
		$_POST['wp_presence_network_features'] = array( 'post-locks' => '0' );

		try {
			wp_presence_save_network_features();
			$this->fail( 'The save should have been refused.' );
		} catch ( WPDieException $refused ) {
			$this->assertFalse( get_site_option( 'wp_presence_network_features' ), 'Nothing was stored.' );
		}
	}
}
