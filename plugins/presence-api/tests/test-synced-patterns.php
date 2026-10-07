<?php
/**
 * Tests for the synced pattern lock in the block editor.
 *
 * @package Presence_API
 *
 * @group presence
 */

class WP_Test_Presence_Synced_Patterns extends WP_Presence_UnitTestCase {

	public function tear_down() {
		wp_dequeue_script( 'wp-presence-synced-patterns' );
		wp_deregister_script( 'wp-presence-synced-patterns' );
		parent::tear_down();
	}

	/**
	 * @covers ::wp_presence_enqueue_synced_pattern_notice
	 */
	public function test_enqueues_only_for_someone_who_can_edit_synced_patterns() {
		// CI runs PHPUnit without building src/, so stand in for the build when it is missing.
		$asset_file = WP_PRESENCE_PLUGIN_DIR . 'build/synced-patterns.asset.php';
		$fixture    = ! file_exists( $asset_file );
		$new_dir    = ! is_dir( dirname( $asset_file ) );
		if ( $fixture ) {
			wp_mkdir_p( dirname( $asset_file ) );
			file_put_contents( $asset_file, "<?php return array( 'dependencies' => array(), 'version' => 'test' );" );
		}
		$stub = ! wp_script_is( 'wp-presence', 'registered' );
		if ( $stub ) {
			wp_register_script( 'wp-presence', false, array(), 'test', true );
		}

		try {
			wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
			wp_presence_enqueue_synced_pattern_notice();
			$this->assertFalse( wp_script_is( 'wp-presence-synced-patterns' ), 'A subscriber cannot edit synced patterns.' );

			wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
			wp_presence_enqueue_synced_pattern_notice();
		} finally {
			if ( $fixture ) {
				unlink( $asset_file );
			}
			if ( $new_dir ) {
				rmdir( dirname( $asset_file ) );
			}
		}

		$this->assertTrue( wp_script_is( 'wp-presence-synced-patterns' ) );
		$this->assertContains( 'wp-presence', wp_scripts()->registered['wp-presence-synced-patterns']->deps );

		if ( $stub ) {
			wp_deregister_script( 'wp-presence' );
		}
	}
}
