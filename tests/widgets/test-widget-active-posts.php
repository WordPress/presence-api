<?php
/**
 * Tests for the Active Posts dashboard widget.
 *
 * @package Presence_API
 *
 * @group presence
 *
 * @covers WP_Presence_Widget_Active_Posts
 */
class WP_Test_Presence_Widget_Active_Posts extends WP_Presence_UnitTestCase {

	private static $editor_id;
	private static $editor2_id;
	private static $contributor_id;
	private static $post_id;

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$editor_id      = $factory->user->create( array( 'role' => 'editor' ) );
		self::$editor2_id     = $factory->user->create( array( 'role' => 'editor' ) );
		self::$contributor_id = $factory->user->create( array( 'role' => 'contributor' ) );
		self::$post_id        = $factory->post->create(
			array(
				'post_title' => 'Test Post',
				'post_type'  => 'post',
			)
		);
	}

	/**
	 * Runs the widget's data query.
	 *
	 * @return array The posts people have open, as the widget draws them.
	 */
	private function active_posts() {
		$method = new ReflectionMethod( WP_Presence_Widget_Active_Posts::class, 'build_active_posts_data' );
		$method->setAccessible( true );

		return $method->invoke( null );
	}

	/**
	 * @covers WP_Presence_Widget_Active_Posts::heartbeat_received
	 */
	public function test_heartbeat_sends_the_list_it_renders() {
		wp_set_current_user( self::$editor_id );

		$room = wp_presence_post_room( self::$post_id );
		wp_set_presence( $room, 'lock-' . self::$editor_id, array(), self::$editor_id );

		$response = WP_Presence_Widget_Active_Posts::heartbeat_received(
			array(),
			array( 'presence-fragments' => array( 'active-posts' => true ) ),
			'dashboard'
		);

		ob_start();
		WP_Presence_Widget_Active_Posts::render();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Test Post', $response['presence-fragments']['active-posts'] );
		$this->assertStringContainsString( $response['presence-fragments']['active-posts'], $html );
	}

	/**
	 * @dataProvider data_untitled_posts
	 */
	public function test_rows_name_the_post_type_and_untitled_posts( $post_title, $post_status ) {
		wp_set_current_user( self::$editor_id );

		$page_id = self::factory()->post->create(
			array(
				'post_title'  => $post_title,
				'post_type'   => 'page',
				'post_status' => $post_status,
			)
		);
		wp_set_presence( wp_presence_post_room( $page_id ), 'lock-' . self::$editor_id, array(), self::$editor_id );

		$response = WP_Presence_Widget_Active_Posts::heartbeat_received(
			array(),
			array( 'presence-fragments' => array( 'active-posts' => true ) ),
			'dashboard'
		);

		$html = $response['presence-fragments']['active-posts'];
		$this->assertStringContainsString( '(no title)', $html );
		$this->assertStringContainsString( esc_html( get_userdata( self::$editor_id )->display_name . ' viewing · Page' ), $html );
	}

	public function data_untitled_posts() {
		return array(
			'draft without a title' => array( '', 'draft' ),
			'unsaved new post'      => array( 'Auto Draft', 'auto-draft' ),
		);
	}

	/**
	 * @covers WP_Presence_Widget_Active_Posts::heartbeat_received
	 */
	public function test_heartbeat_received_ignores_without_ping() {
		$response = WP_Presence_Widget_Active_Posts::heartbeat_received(
			array( 'existing' => true ),
			array(),
			'dashboard'
		);

		$this->assertArrayNotHasKey( 'presence-fragments', $response );
		$this->assertArrayHasKey( 'existing', $response );
	}

	/**
	 * @covers WP_Presence_Widget_Active_Posts::build_active_posts_data
	 */
	public function test_active_status() {
		wp_set_current_user( self::$editor_id );

		$room = wp_presence_post_room( self::$post_id );
		wp_set_presence( $room, 'lock-' . self::$editor_id, array(), self::$editor_id );

		$posts = $this->active_posts();

		$this->assertSame( 'active', $posts[0]['editors'][0]['status'] );
	}

	/**
	 * @covers WP_Presence_Widget_Active_Posts::build_active_posts_data
	 */
	public function test_idle_status() {
		global $wpdb;

		wp_set_current_user( self::$editor_id );

		$room = wp_presence_post_room( self::$post_id );
		wp_set_presence( $room, 'lock-' . self::$editor_id, array(), self::$editor_id );

		// Backdate the entry to exceed idle threshold.
		$wpdb->update(
			$wpdb->presence,
			array( 'date_gmt' => gmdate( 'Y-m-d H:i:s', time() - ( wp_presence_idle_threshold() + 1 ) ) ),
			array( 'client_id' => 'lock-' . self::$editor_id ),
			array( '%s' ),
			array( '%s' )
		);

		$posts = $this->active_posts();

		$this->assertSame( 'idle', $posts[0]['editors'][0]['status'] );
	}

	/**
	 * @covers WP_Presence_Widget_Active_Posts::build_active_posts_data
	 */
	public function test_multiple_users_editing() {
		wp_set_current_user( self::$editor_id );

		$post2_id = self::factory()->post->create( array( 'post_title' => 'Second Post' ) );

		$room1 = wp_presence_post_room( self::$post_id );
		$room2 = wp_presence_post_room( $post2_id );

		wp_set_presence( $room1, 'lock-' . self::$editor_id, array(), self::$editor_id );
		wp_set_presence( $room2, 'lock-' . self::$editor2_id, array(), self::$editor2_id );

		$posts = $this->active_posts();

		$this->assertCount( 2, $posts );
	}

	/**
	 * @covers WP_Presence_Widget_Active_Posts::build_active_posts_data
	 */
	public function test_excludes_non_post_rooms() {
		wp_set_current_user( self::$editor_id );

		wp_set_presence( 'admin/online', 'user-' . self::$editor_id, array(), self::$editor_id );

		$posts = $this->active_posts();

		$this->assertCount( 0, $posts );
	}

	/**
	 * A contributor has `edit_posts`, which is all the widget itself requires,
	 * but cannot edit someone else's post and must not learn it is being
	 * worked on.
	 *
	 * @covers WP_Presence_Widget_Active_Posts::build_active_posts_data
	 */
	public function test_heartbeat_excludes_posts_the_user_cannot_edit() {
		$room = wp_presence_post_room( self::$post_id );
		wp_set_presence( $room, 'lock-' . self::$editor_id, array(), self::$editor_id );

		wp_set_current_user( self::$contributor_id );

		$posts = $this->active_posts();

		$this->assertCount( 0, $posts );
	}

	/**
	 * @covers WP_Presence_Widget_Active_Posts::build_active_posts_data
	 */
	public function test_heartbeat_includes_posts_the_user_can_edit() {
		$draft_id = self::factory()->post->create(
			array(
				'post_author' => self::$contributor_id,
				'post_status' => 'draft',
				'post_title'  => 'Contributor Draft',
			)
		);

		$room = wp_presence_post_room( $draft_id );
		wp_set_presence( $room, 'lock-' . self::$contributor_id, array(), self::$contributor_id );

		wp_set_current_user( self::$contributor_id );

		$posts = $this->active_posts();

		$this->assertCount( 1, $posts );
		$this->assertSame( $draft_id, $posts[0]['post_id'] );
	}

	/**
	 * Only the rooms the user cannot reach are dropped, not the whole response.
	 *
	 * @covers WP_Presence_Widget_Active_Posts::build_active_posts_data
	 */
	public function test_heartbeat_filters_per_post_rather_than_all_or_nothing() {
		$draft_id = self::factory()->post->create(
			array(
				'post_author' => self::$contributor_id,
				'post_status' => 'draft',
			)
		);

		wp_set_presence( wp_presence_post_room( self::$post_id ), 'lock-a', array(), self::$editor_id );
		wp_set_presence( wp_presence_post_room( $draft_id ), 'lock-b', array(), self::$contributor_id );

		wp_set_current_user( self::$contributor_id );

		$posts = $this->active_posts();

		$this->assertCount( 1, $posts );
		$this->assertSame( $draft_id, $posts[0]['post_id'] );
	}

	/**
	 * Nothing guarantees one row per user in a room, so the widget counts
	 * people rather than rows.
	 *
	 * @covers WP_Presence_Widget_Active_Posts::build_active_posts_data
	 */
	public function test_a_user_holding_two_entries_is_counted_once() {
		wp_set_current_user( self::$editor_id );

		$room = wp_presence_post_room( self::$post_id );
		wp_set_presence( $room, 'editor-' . self::$editor_id, array(), self::$editor_id );
		wp_set_presence( $room, 'other-' . self::$editor_id, array(), self::$editor_id );

		$posts = $this->active_posts();

		$this->assertCount( 1, $posts[0]['editors'] );
		$this->assertSame( self::$editor_id, $posts[0]['editors'][0]['user_id'] );
	}

	/**
	 * @covers WP_Presence_Widget_Active_Posts::build_active_posts_data
	 */
	public function test_a_users_freshest_entry_decides_their_status() {
		global $wpdb;

		wp_set_current_user( self::$editor_id );

		$room = wp_presence_post_room( self::$post_id );

		wp_set_presence( $room, 'stale-' . self::$editor_id, array(), self::$editor_id );
		$wpdb->update(
			$wpdb->presence,
			array( 'date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 45 ) ),
			array( 'client_id' => 'stale-' . self::$editor_id ),
			array( '%s' ),
			array( '%s' )
		);

		wp_set_presence( $room, 'editor-' . self::$editor_id, array(), self::$editor_id );

		$posts = $this->active_posts();

		$editors = $posts[0]['editors'];

		$this->assertCount( 1, $editors );
		$this->assertSame( 'active', $editors[0]['status'] );
	}

	/**
	 * @covers WP_Presence_Widget_Active_Posts::enqueue_scripts
	 */
	public function test_enqueue_scripts_only_styles_the_dashboard() {
		WP_Presence_Widget_Active_Posts::enqueue_scripts( 'edit.php' );

		$this->assertFalse( wp_style_is( 'presence-active-posts-widget', 'enqueued' ) );

		WP_Presence_Widget_Active_Posts::enqueue_scripts( 'index.php' );

		$this->assertTrue( wp_style_is( 'presence-active-posts-widget', 'enqueued' ) );
	}

	/**
	 * @covers WP_Presence_Widget_Active_Posts::register
	 */
	public function test_register_adds_the_widget_for_a_user_who_can_edit_posts() {
		global $wp_meta_boxes;

		require_once ABSPATH . 'wp-admin/includes/dashboard.php';

		wp_set_current_user( self::$editor_id );
		set_current_screen( 'dashboard' );

		WP_Presence_Widget_Active_Posts::register();

		$this->assertArrayHasKey( 'presence_active_posts', $wp_meta_boxes['dashboard']['normal']['default'] );
	}

	/**
	 * @covers WP_Presence_Widget_Active_Posts::register
	 */
	public function test_register_skips_a_user_who_cannot_edit_posts() {
		global $wp_meta_boxes;

		require_once ABSPATH . 'wp-admin/includes/dashboard.php';

		$wp_meta_boxes = array();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		set_current_screen( 'dashboard' );

		WP_Presence_Widget_Active_Posts::register();

		$this->assertArrayNotHasKey( 'presence_active_posts', $wp_meta_boxes['dashboard']['normal']['default'] ?? array() );
	}

	/**
	 * @covers WP_Presence_Widget_Active_Posts::render
	 */
	public function test_render_says_all_quiet_when_nothing_is_being_edited() {
		wp_set_current_user( self::$editor_id );

		ob_start();
		WP_Presence_Widget_Active_Posts::render();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'All quiet.', $html );
		$this->assertStringNotContainsString( '<ul', $html );
	}

	/**
	 * @covers WP_Presence_Widget_Active_Posts::render
	 */
	public function test_render_names_a_lone_editor_and_links_the_post() {
		wp_set_current_user( self::$editor_id );

		$room = wp_presence_post_room( self::$post_id );
		wp_set_presence( $room, 'lock-' . self::$editor_id, array(), self::$editor_id );

		ob_start();
		WP_Presence_Widget_Active_Posts::render();
		$html = ob_get_clean();

		$user = get_userdata( self::$editor_id );
		$this->assertStringContainsString( esc_html( $user->display_name ), $html );
		$this->assertStringContainsString( 'Test Post', $html );
		$this->assertStringContainsString( 'post=' . self::$post_id, $html );
		// An active editor is the default state, so no status text is rendered.
		$this->assertStringContainsString( '<span class="presence-status-text"></span>', $html );
	}

	/**
	 * @covers WP_Presence_Widget_Active_Posts::render
	 */
	public function test_render_counts_a_crowd_and_labels_it_idle_once_everyone_goes_quiet() {
		global $wpdb;

		wp_set_current_user( self::$editor_id );

		$room = wp_presence_post_room( self::$post_id );
		wp_set_presence( $room, 'lock-' . self::$editor_id, array(), self::$editor_id );
		wp_set_presence( $room, 'lock-' . self::$editor2_id, array(), self::$editor2_id );

		$wpdb->update(
			$wpdb->presence,
			array( 'date_gmt' => gmdate( 'Y-m-d H:i:s', time() - ( wp_presence_idle_threshold() + 1 ) ) ),
			array( 'room' => $room ),
			array( '%s' ),
			array( '%s' )
		);

		ob_start();
		WP_Presence_Widget_Active_Posts::render();
		$html = ob_get_clean();

		$this->assertStringContainsString( '2 viewing', $html );
		$this->assertStringContainsString( '<span class="presence-status-text">Idle</span>', $html );
	}

	/**
	 * @covers WP_Presence_Widget_Active_Posts::build_active_posts_data
	 */
	public function test_only_the_lock_holder_is_editing() {
		wp_set_current_user( self::$editor_id );

		$room = wp_presence_post_room( self::$post_id );
		wp_set_presence( $room, 'editor-' . self::$editor2_id, array(), self::$editor2_id );
		wp_set_presence( $room, 'editor-' . self::$editor_id, array(), self::$editor_id );
		wp_set_post_lock( self::$post_id );

		$posts = $this->active_posts();

		$this->assertSame( get_userdata( self::$editor_id )->display_name . ' editing, 1 viewing · Post', $posts[0]['editor_label'] );
		$this->assertSame( self::$editor_id, $posts[0]['editors'][0]['user_id'] );
	}
}
