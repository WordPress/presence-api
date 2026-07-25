<?php
/**
 * Tests for default dashboard widget ordering.
 *
 * @package Presence_API
 *
 * @group presence
 */
class WP_Test_Presence_Dashboard_Widget_Order extends WP_UnitTestCase {

	private static $admin_id;

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$admin_id = $factory->user->create( array( 'role' => 'administrator' ) );
	}

	/**
	 * @covers ::wp_presence_default_widget_order
	 */
	public function test_returns_default_order_when_no_preference_set() {
		$result = wp_presence_default_widget_order( false );

		$this->assertIsArray( $result );
		$this->assertStringStartsWith( 'presence_whos_online,presence_active_posts', $result['normal'] );
	}

	/**
	 * @covers ::wp_presence_default_widget_order
	 */
	public function test_returns_existing_order_unchanged_when_preference_is_set() {
		$custom = array(
			'normal' => 'dashboard_activity,dashboard_right_now',
			'side'   => 'dashboard_quick_press',
		);

		$result = wp_presence_default_widget_order( $custom );

		$this->assertSame( $custom, $result );
	}

	/**
	 * @covers ::wp_presence_default_widget_order
	 */
	public function test_filter_is_applied_via_get_user_option() {
		wp_set_current_user( self::$admin_id );

		$order = get_user_option( 'meta-box-order_dashboard', self::$admin_id );

		$this->assertIsArray( $order );
		$this->assertStringStartsWith( 'presence_whos_online,presence_active_posts', $order['normal'] );
	}

	/**
	 * @covers ::wp_presence_default_widget_order
	 */
	public function test_filter_does_not_override_saved_preference() {
		wp_set_current_user( self::$admin_id );
		$custom = array( 'normal' => 'dashboard_right_now,dashboard_activity' );
		update_user_option( self::$admin_id, 'meta-box-order_dashboard', $custom );

		$order = get_user_option( 'meta-box-order_dashboard', self::$admin_id );

		$this->assertSame( $custom, $order );

		delete_user_option( self::$admin_id, 'meta-box-order_dashboard' );
	}
}
