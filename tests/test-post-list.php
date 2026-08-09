<?php
/**
 * Tests for the post list "Editors" column.
 *
 * @package Presence_API
 *
 * @group presence
 */
class WP_Test_Presence_Post_List extends WP_UnitTestCase {

	private static $editor_id;
	private static $subscriber_id;

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$editor_id     = $factory->user->create( array( 'role' => 'editor' ) );
		self::$subscriber_id = $factory->user->create( array( 'role' => 'subscriber' ) );
	}

	public function tear_down() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "TRUNCATE TABLE {$wpdb->presence}" );
		parent::tear_down();
	}

	/**
	 * Renders the column for a post and returns the buffered output.
	 *
	 * @param int $post_id The post to render the column for.
	 * @return string The rendered markup.
	 */
	private function render_column( $post_id ) {
		ob_start();
		wp_presence_render_editors_column( 'presence_editors', $post_id );
		return ob_get_clean();
	}

	/**
	 * @covers ::wp_presence_register_post_list_columns
	 */
	public function test_registers_columns_only_for_presence_supporting_post_types() {
		// Public, but does not support presence: excluded by the in-loop check.
		register_post_type( 'no_presence', array( 'public' => true ) );
		// Not public: excluded before the loop even sees it, by get_post_types( array( 'public' => true ) ).
		register_post_type( 'private_type', array( 'public' => false ) );
		add_post_type_support( 'private_type', 'presence' );

		wp_set_current_user( self::$editor_id );
		wp_presence_register_post_list_columns();

		$this->assertNotFalse( has_filter( 'manage_post_posts_columns', 'wp_presence_add_editors_column' ) );
		$this->assertNotFalse( has_action( 'manage_post_posts_custom_column', 'wp_presence_render_editors_column' ) );
		$this->assertFalse( has_filter( 'manage_no_presence_posts_columns', 'wp_presence_add_editors_column' ) );
		$this->assertFalse( has_filter( 'manage_private_type_posts_columns', 'wp_presence_add_editors_column' ) );
		$this->assertNotFalse( has_action( 'admin_enqueue_scripts', 'wp_presence_editors_column_css' ) );

		unregister_post_type( 'no_presence' );
		unregister_post_type( 'private_type' );
	}

	/**
	 * @covers ::wp_presence_register_post_list_columns
	 */
	public function test_does_not_register_columns_without_edit_posts_capability() {
		wp_set_current_user( self::$subscriber_id );
		wp_presence_register_post_list_columns();

		$this->assertFalse( has_filter( 'manage_post_posts_columns', 'wp_presence_add_editors_column' ) );
	}

	/**
	 * @covers ::wp_presence_add_editors_column
	 */
	public function test_add_editors_column_inserts_before_date_and_preserves_others() {
		$columns = array(
			'cb'    => '<input type="checkbox" />',
			'title' => 'Title',
			'date'  => 'Date',
		);

		$result = wp_presence_add_editors_column( $columns );
		$keys   = array_keys( $result );

		$this->assertSame( array( 'cb', 'title', 'presence_editors', 'date' ), $keys );
		$this->assertSame( 'Title', $result['title'] );
	}

	/**
	 * @covers ::wp_presence_add_editors_column
	 */
	public function test_add_editors_column_appends_when_no_date_column() {
		$columns = array(
			'cb'    => '<input type="checkbox" />',
			'title' => 'Title',
		);

		$result = wp_presence_add_editors_column( $columns );
		$keys   = array_keys( $result );

		$this->assertSame( 'presence_editors', end( $keys ) );
	}

	/**
	 * The column caches presence for the whole page load in a function-local
	 * static that is never reset, including between test methods in the same
	 * process. Every case — no presence, one editor, several editors,
	 * escaping, and the column-name guard — is exercised in one pass here,
	 * matching how the list table actually calls it: once per row within a
	 * single request.
	 *
	 * @covers ::wp_presence_render_editors_column
	 */
	public function test_render_editors_column() {
		$post_none = self::factory()->post->create();
		$post_one  = self::factory()->post->create();
		$post_many = self::factory()->post->create();

		$editor_2_id = self::factory()->user->create(
			array(
				'role'         => 'editor',
				'display_name' => 'Bob "><script>alert(1)</script>',
			)
		);

		wp_set_presence( wp_presence_post_room( $post_one ), 'lock-1', array(), self::$editor_id );
		wp_set_presence( wp_presence_post_room( $post_many ), 'lock-1', array(), self::$editor_id );
		wp_set_presence( wp_presence_post_room( $post_many ), 'lock-2', array(), $editor_2_id );

		$this->assertSame( '', $this->render_column( $post_none ) );

		$one_output = $this->render_column( $post_one );
		$this->assertSame( 1, substr_count( $one_output, '<img' ) );

		$many_output = $this->render_column( $post_many );
		$this->assertSame( 2, substr_count( $many_output, '<img' ) );
		$this->assertStringNotContainsString( '<script>', $many_output );

		ob_start();
		wp_presence_render_editors_column( 'some_other_column', $post_many );
		$this->assertSame( '', ob_get_clean() );
	}

	/**
	 * @covers ::wp_presence_editors_column_css
	 */
	public function test_editors_column_css_enqueues_only_on_edit_php() {
		wp_presence_editors_column_css( 'upload.php' );
		$this->assertFalse( wp_style_is( 'presence-post-list', 'enqueued' ) );

		wp_presence_editors_column_css( 'edit.php' );
		$this->assertTrue( wp_style_is( 'presence-post-list', 'enqueued' ) );
	}
}
