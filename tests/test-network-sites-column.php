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
	private static $subscriber_id;

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$editor_id     = $factory->user->create( array( 'role' => 'editor' ) );
		self::$subscriber_id = $factory->user->create( array( 'role' => 'subscriber' ) );
	}

	/**
	 * @covers ::wp_presence_register_network_sites_column
	 */
	public function test_sites_column_requires_capability() {
		$columns = wp_presence_register_network_sites_column( array( 'blogname' => 'Site' ) );

		$this->assertArrayNotHasKey( 'presence_online', $columns, 'A visitor with no capability should not see the column.' );
	}

	/**
	 * The header is gated, so nothing registers this column for a user without
	 * the capability. That holds only while ours is the only thing that can put
	 * the name on the screen, which is not a property this file controls.
	 *
	 * @covers ::wp_presence_render_network_sites_column
	 */
	public function test_sites_column_renders_nothing_without_capability() {
		$this->become_network_admin();
		$blog_id = $this->create_blog();
		$this->set_presence_on_site( $blog_id, self::$editor_id );

		wp_set_current_user( self::$subscriber_id );

		ob_start();
		wp_presence_render_network_sites_column( 'presence_online', $blog_id );
		$output = ob_get_clean();

		$this->assertSame( '', $output );
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
	 * Without this the avatars paint square, ringless and unoverlapped: no
	 * other presence asset loads on this screen.
	 *
	 * The three cases share one test because an enqueue outlives the test that
	 * made it, so the negatives have to come before the positive.
	 *
	 * @covers ::wp_presence_enqueue_network_sites_assets
	 * @covers ::wp_presence_enqueue_avatar_stack_style
	 */
	public function test_sites_list_enqueues_the_avatar_stack_style() {
		wp_presence_enqueue_network_sites_assets( 'sites.php' );
		$this->assertFalse( wp_style_is( 'wp-presence-avatar-stack', 'enqueued' ), 'A visitor with no capability sees no column to style.' );

		$this->become_network_admin();

		wp_presence_enqueue_network_sites_assets( 'index.php' );
		$this->assertFalse( wp_style_is( 'wp-presence-avatar-stack', 'enqueued' ), 'Only the Sites list needs it.' );

		wp_presence_enqueue_network_sites_assets( 'sites.php' );
		$this->assertTrue( wp_style_is( 'wp-presence-avatar-stack', 'enqueued' ) );
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

	/**
	 * @covers ::wp_presence_render_network_sites_column
	 */
	public function test_sites_column_shows_dash_for_archived_site() {
		$this->become_network_admin();

		$blog_id = $this->create_blog();
		$this->set_presence_on_site( $blog_id, self::$editor_id );

		update_blog_status( $blog_id, 'archived', 1 );
		wp_presence_flush_network_summary_cache();

		ob_start();
		wp_presence_render_network_sites_column( 'presence_online', $blog_id );
		$output = ob_get_clean();

		$this->assertSame( '&#8212;', $output, 'An archived site must show a dash in the Online column.' );
	}

	/**
	 * The column reads the same em dash either way, so the screen has to say
	 * which it is.
	 *
	 * @covers ::wp_presence_network_aggregation_notice
	 */
	public function test_sites_list_warns_when_the_network_does_not_aggregate() {
		$this->become_network_admin();
		set_current_screen( 'sites-network' );

		add_filter( 'wp_presence_network_aggregation_enabled', '__return_false' );

		ob_start();
		wp_presence_network_aggregation_notice();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'not aggregated across this network', $output );
	}

	/**
	 * @covers ::wp_presence_network_aggregation_notice
	 */
	public function test_sites_list_is_silent_while_the_network_aggregates() {
		$this->become_network_admin();
		set_current_screen( 'sites-network' );

		ob_start();
		wp_presence_network_aggregation_notice();

		$this->assertSame( '', ob_get_clean() );
	}

	/**
	 * network_admin_notices fires on every Network Admin screen, so the notice
	 * has to place itself. It belongs on the two that carry an Online column.
	 *
	 * @covers ::wp_presence_network_aggregation_notice
	 */
	public function test_the_notice_stays_off_screens_without_an_online_column() {
		$this->become_network_admin();
		set_current_screen( 'dashboard-network' );

		add_filter( 'wp_presence_network_aggregation_enabled', '__return_false' );

		ob_start();
		wp_presence_network_aggregation_notice();

		$this->assertSame( '', ob_get_clean() );
	}

	/**
	 * The notice names a column only a network administrator is shown.
	 *
	 * @covers ::wp_presence_network_aggregation_notice
	 */
	public function test_the_notice_requires_capability() {
		wp_set_current_user( self::$editor_id );
		set_current_screen( 'sites-network' );

		add_filter( 'wp_presence_network_aggregation_enabled', '__return_false' );

		ob_start();
		wp_presence_network_aggregation_notice();

		$this->assertSame( '', ob_get_clean() );
	}
}
