<?php
/**
 * Tests for the "Online" column on the Network Admin Sites list.
 *
 * The column is drawn once per row on a table that can be paged fifty sites at
 * a time, so it asks the read path for that row's site only. What it must not
 * do is pull the network across to render one line of it.
 *
 * @package Presence_API
 *
 * @group presence
 * @group ms-required
 */
class WP_Test_Network_Sites_Column extends WP_Presence_Network_UnitTestCase {

	private static $editor_id;

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$editor_id = $factory->user->create( array( 'role' => 'editor' ) );
	}

	/**
	 * @covers ::wp_presence_register_network_sites_column
	 */
	public function test_sites_column_requires_capability() {
		$columns = wp_presence_register_network_sites_column( array( 'blogname' => 'Site' ) );

		$this->assertArrayNotHasKey( 'presence_online', $columns, 'A visitor with no capability should not see the column.' );
	}

	/**
	 * @covers ::wp_presence_register_network_sites_column
	 * @covers ::wp_presence_render_network_sites_column
	 */
	public function test_sites_column_renders_avatar_stack_and_count() {
		$this->become_network_admin();

		$blog_id = $this->create_blog();
		$this->set_presence_on_site( $blog_id, self::$editor_id );

		$columns = wp_presence_register_network_sites_column( array( 'blogname' => 'Site' ) );
		$this->assertArrayHasKey( 'presence_online', $columns );

		ob_start();
		wp_presence_render_network_sites_column( 'presence_online', $blog_id );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'presence-avatar-stack', $output );
		$this->assertStringContainsString( '1', $output );
	}

	/**
	 * @covers ::wp_presence_render_network_sites_column
	 */
	public function test_sites_column_shows_a_dash_for_a_site_with_nobody_online() {
		$this->become_network_admin();

		$blog_id = $this->create_blog();

		ob_start();
		wp_presence_render_network_sites_column( 'presence_online', $blog_id );
		$output = ob_get_clean();

		$this->assertSame( '&#8212;', $output );
	}

	/**
	 * @covers ::wp_presence_render_network_sites_column
	 */
	public function test_sites_column_ignores_other_columns() {
		$this->become_network_admin();

		ob_start();
		wp_presence_render_network_sites_column( 'blogname', 1 );
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}
}
