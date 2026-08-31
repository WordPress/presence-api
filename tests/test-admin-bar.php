<?php
/**
 * Tests for the admin bar presence node.
 *
 * @package Presence_API
 *
 * @group presence
 *
 * @covers ::wp_presence_admin_bar_node
 * @covers ::wp_presence_admin_bar_assets
 */
class WP_Test_Presence_Admin_Bar extends WP_Presence_UnitTestCase {

	private static $editor_id;
	private static $contributor_id;
	private static $post_id;

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$editor_id      = $factory->user->create( array( 'role' => 'editor' ) );
		self::$contributor_id = $factory->user->create( array( 'role' => 'contributor' ) );
		self::$post_id        = $factory->post->create(
			array(
				'post_title'  => 'Secret Draft',
				'post_status' => 'draft',
				'post_author' => self::$editor_id,
			)
		);
	}

	public function set_up() {
		parent::set_up();
		// Only loaded on requests that actually render the bar.
		require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';
	}

	public function tear_down() {
		unset( $GLOBALS['pagenow'] );
		// is_admin_bar_showing() memoizes into this global, so removing the
		// filter that set it is not enough to undo it.
		$GLOBALS['show_admin_bar'] = null;
		// is_admin() reads $current_screen, which outlives the test that set it.
		set_current_screen( 'dashboard' );
		parent::tear_down();
	}

	/**
	 * Puts the editor online on the post editing screen for a given post.
	 *
	 * @param int $post_id The post the editor is working on.
	 */
	private function put_editor_on_post( $post_id ) {
		wp_set_presence(
			'admin/online',
			'user-' . self::$editor_id,
			array( 'screen' => 'post' ),
			self::$editor_id
		);
		wp_set_presence(
			wp_presence_post_room( $post_id ),
			'lock-' . self::$editor_id,
			array(),
			self::$editor_id
		);
	}

	/**
	 * Renders the node and returns every node title and link as one string.
	 *
	 * @return string Concatenated node titles and hrefs.
	 */
	private function render_node_markup() {
		$markup = '';
		foreach ( $this->render_nodes() as $node ) {
			$markup .= $node->title;
			if ( is_string( $node->href ) ) {
				$markup .= $node->href;
			}
		}

		return $markup;
	}

	/**
	 * Renders the node and returns the admin bar's nodes, keyed by id.
	 *
	 * @return array Node objects.
	 */
	private function render_nodes() {
		$bar = new WP_Admin_Bar();
		wp_presence_admin_bar_node( $bar );

		return $bar->get_nodes() ?? array();
	}

	/**
	 * Puts a user online on a given screen.
	 *
	 * @param string $screen The screen slug to record.
	 * @param array  $data   Extra presence data to record alongside it.
	 * @return int The new user's ID.
	 */
	private function put_user_on_screen( $screen, $data = array() ) {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		wp_set_presence(
			'admin/online',
			'user-' . $user_id,
			array_merge( array( 'screen' => $screen ), $data ),
			$user_id
		);

		return $user_id;
	}

	/**
	 * Backdates a user's entry so it falls outside the TTL window.
	 *
	 * @param int $user_id     The user whose entry to backdate.
	 * @param int $seconds_ago How far in the past to date the entry.
	 */
	private function age_entry( $user_id, $seconds_ago ) {
		global $wpdb;

		$wpdb->update(
			$wpdb->presence,
			array( 'date_gmt' => gmdate( 'Y-m-d H:i:s', time() - $seconds_ago ) ),
			array( 'client_id' => 'user-' . $user_id ),
			array( '%s' ),
			array( '%s' )
		);
	}

	/**
	 * The menu labels each online user with the post they are editing. A
	 * contributor has `edit_posts`, which is all the node itself requires, but
	 * must not learn the title of a draft they cannot edit.
	 */
	public function test_hides_post_titles_the_user_cannot_edit() {
		$this->put_editor_on_post( self::$post_id );

		wp_set_current_user( self::$contributor_id );
		$markup = $this->render_node_markup();

		$this->assertStringNotContainsString( 'Secret Draft', $markup );
		$this->assertStringNotContainsString( 'post=' . self::$post_id, $markup );
	}

	/**
	 * The user is still listed, only the post they are on is withheld.
	 */
	public function test_still_lists_the_user_without_the_post_title() {
		$this->put_editor_on_post( self::$post_id );

		wp_set_current_user( self::$contributor_id );
		$markup = $this->render_node_markup();

		$editor = get_userdata( self::$editor_id );
		$this->assertStringContainsString( $editor->display_name, $markup );
	}

	/**
	 * A user who can edit the post still sees its title.
	 */
	public function test_shows_post_titles_the_user_can_edit() {
		$this->put_editor_on_post( self::$post_id );

		$other_editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $other_editor_id );
		$markup = $this->render_node_markup();

		$this->assertStringContainsString( 'Secret Draft', $markup );
	}

	/**
	 * WP_Admin_Bar only renders a `tabindex` attribute on a non-link node
	 * when `meta.tabindex` is explicitly set — otherwise it's a `<div>`
	 * outside the tab order entirely.
	 */
	public function test_non_link_nodes_are_keyboard_reachable() {
		$this->put_editor_on_post( self::$post_id );

		wp_set_current_user( self::$contributor_id );

		$bar = new WP_Admin_Bar();
		wp_presence_admin_bar_node( $bar );

		foreach ( $bar->get_nodes() as $node ) {
			if ( ! empty( $node->href ) ) {
				continue;
			}

			$this->assertSame(
				0,
				$node->meta['tabindex'] ?? null,
				"Node '{$node->id}' has no href and needs meta.tabindex => 0 to stay reachable by Tab."
			);
		}
	}

	/**
	 * The count includes you, but the node still hides on your own rather than
	 * reporting a room of one.
	 */
	public function test_no_indicator_when_you_are_the_only_one_online() {
		wp_set_current_user( self::$editor_id );
		wp_set_presence( 'admin/online', 'user-' . self::$editor_id, array( 'screen' => 'dashboard' ), self::$editor_id );

		$this->assertSame( array(), $this->render_nodes() );
	}

	public function test_no_indicator_for_a_user_without_edit_posts() {
		$this->put_user_on_screen( 'dashboard' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->assertSame( array(), $this->render_nodes() );
	}

	/**
	 * Puts the current request on an admin screen so presence recorded against
	 * the same slug counts as "on this page".
	 *
	 * @param string $pagenow The admin file being requested.
	 * @param string $base    The screen base to register it under.
	 */
	private function view_admin_page( $pagenow, $base ) {
		set_current_screen( $base );
		$GLOBALS['pagenow'] = $pagenow;
	}

	public function test_users_on_the_same_admin_page_are_grouped_here() {
		$this->view_admin_page( 'upload.php', 'upload' );

		$here = get_userdata( $this->put_user_on_screen( 'upload' ) );
		$this->put_user_on_screen( 'edit-comments' );

		wp_set_current_user( self::$editor_id );
		$nodes = $this->render_nodes();

		$this->assertArrayHasKey( 'presence-group-here', $nodes );
		$this->assertArrayHasKey( 'presence-group-elsewhere', $nodes );
		$this->assertArrayHasKey( 'presence-user-' . $here->ID, $nodes );
		// The avatar stack is built from the people on this page, you included.
		$this->assertStringContainsString( 'alt="' . esc_attr( $here->display_name ) . '"', $nodes['presence-online']->title );
		$this->assertStringContainsString(
			'alt="' . esc_attr( get_userdata( self::$editor_id )->display_name ) . '"',
			$nodes['presence-online']->title
		);
	}

	/**
	 * The count agrees with the users list and the network Sites column, all of
	 * which count everyone present rather than everyone but you.
	 */
	public function test_the_count_includes_you() {
		$this->put_user_on_screen( 'dashboard' );
		$this->put_user_on_screen( 'edit' );

		wp_set_current_user( self::$editor_id );
		wp_set_presence( 'admin/online', 'user-' . self::$editor_id, array( 'screen' => 'dashboard' ), self::$editor_id );

		$nodes = $this->render_nodes();

		$this->assertStringContainsString( '3 online', $nodes['presence-online']->title );
		$this->assertSame( '3 users online', $nodes['presence-online']->meta['aria-label'] );
	}

	/**
	 * Your own row ages past the TTL while you are still on the screen, so the
	 * count has to add you back by identity.
	 */
	public function test_the_count_includes_you_when_your_own_row_has_expired() {
		$this->put_user_on_screen( 'dashboard' );

		wp_set_current_user( self::$editor_id );
		wp_set_presence( 'admin/online', 'user-' . self::$editor_id, array( 'screen' => 'dashboard' ), self::$editor_id );
		$this->age_entry( self::$editor_id, WP_PRESENCE_DEFAULT_TTL + 1 );

		$nodes = $this->render_nodes();

		$this->assertStringContainsString( '2 online', $nodes['presence-online']->title );
	}

	/**
	 * The three surfaces that report a number have to report the same one. Each
	 * assembles its own set, so a fix to any single one can drift from the rest.
	 *
	 * @covers ::wp_presence_users_views
	 * @covers WP_Presence_Widget_Whos_Online::render
	 */
	public function test_every_surface_reports_the_same_number_when_your_row_is_absent() {
		$this->put_user_on_screen( 'dashboard' );
		$this->put_user_on_screen( 'edit' );

		wp_set_current_user( self::$editor_id );

		$nodes = $this->render_nodes();

		ob_start();
		WP_Presence_Widget_Whos_Online::render();
		$widget = ob_get_clean();

		$views = wp_presence_users_views( array() );

		$this->assertStringContainsString( '3 online', $nodes['presence-online']->title );
		$this->assertStringContainsString( '(3)', $views['presence_online'] );
		$this->assertSame( 3, substr_count( $widget, 'class="presence-user-item"' ) );
	}

	/**
	 * An admin page with no entry in the map still groups correctly, keyed on
	 * its filename, which is what the client reports as window.pagenow.
	 */
	public function test_an_unmapped_admin_page_is_keyed_by_its_filename() {
		$this->view_admin_page( 'site-health.php', 'site-health' );

		$user_id = $this->put_user_on_screen( 'site-health' );

		wp_set_current_user( self::$editor_id );
		$nodes = $this->render_nodes();

		$this->assertArrayHasKey( 'presence-user-' . $user_id, $nodes );
		$this->assertArrayNotHasKey( 'presence-group-elsewhere', $nodes );
	}

	/**
	 * Both groups are capped so a busy site cannot grow the dropdown past the
	 * height of the screen.
	 */
	public function test_each_group_is_capped_at_ten_with_a_count_for_the_rest() {
		$this->view_admin_page( 'upload.php', 'upload' );

		for ( $i = 0; $i < 11; $i++ ) {
			$this->put_user_on_screen( 'upload' );
			$this->put_user_on_screen( 'edit-comments' );
		}

		wp_set_current_user( self::$editor_id );
		$nodes = $this->render_nodes();

		$this->assertStringContainsString( '+1 more', $nodes['presence-here-overflow']->title );
		$this->assertStringContainsString( '+1 more', $nodes['presence-elsewhere-overflow']->title );
		// The "elsewhere" overflow is the one that can be acted on.
		$this->assertSame( admin_url( 'users.php?presence_status=online' ), $nodes['presence-elsewhere-overflow']->href );
	}

	/**
	 * Someone reading the site is shown the page they are on, linked to it,
	 * rather than the generic "Front end" label.
	 */
	public function test_a_user_reading_the_site_is_shown_the_page_they_are_on() {
		$this->view_admin_page( 'upload.php', 'upload' );

		$user_id = $this->put_user_on_screen(
			'front',
			array(
				'title'   => 'Hello World',
				'post_id' => self::$post_id,
			)
		);

		wp_set_current_user( self::$editor_id );
		$nodes = $this->render_nodes();

		$node = $nodes[ 'presence-user-' . $user_id ];
		$this->assertStringContainsString( 'Hello World', $node->title );
		$this->assertSame( get_permalink( self::$post_id ), $node->href );
	}

	/**
	 * The label's leading verb is italicised to separate it from the object it
	 * acts on, which leaves nothing to italicise in a one-word label.
	 */
	public function test_a_one_word_screen_label_is_left_unstyled() {
		$this->view_admin_page( 'upload.php', 'upload' );

		$user_id = $this->put_user_on_screen( 'plugins' );

		wp_set_current_user( self::$editor_id );
		$nodes = $this->render_nodes();

		$title = $nodes[ 'presence-user-' . $user_id ]->title;
		$this->assertStringContainsString( 'Plugins', $title );
		$this->assertStringNotContainsString( '<em>', $title );
	}

	/**
	 * A presence row outlives the user record it points at when an account is
	 * deleted mid-session, and every list here has to survive that.
	 */
	public function test_an_entry_for_a_user_who_no_longer_exists_is_skipped() {
		$this->view_admin_page( 'upload.php', 'upload' );

		wp_set_presence( 'admin/online', 'user-999901', array( 'screen' => 'upload' ), 999901 );
		wp_set_presence( 'admin/online', 'user-999902', array( 'screen' => 'plugins' ), 999902 );
		$real = get_userdata( $this->put_user_on_screen( 'upload' ) );

		wp_set_current_user( self::$editor_id );
		$nodes = $this->render_nodes();

		$this->assertArrayNotHasKey( 'presence-user-999901', $nodes );
		$this->assertArrayNotHasKey( 'presence-user-999902', $nodes );
		$this->assertArrayHasKey( 'presence-user-' . $real->ID, $nodes );
	}

	public function test_the_indicator_styles_load_for_a_user_who_can_see_it() {
		wp_set_current_user( self::$editor_id );
		add_filter( 'show_admin_bar', '__return_true' );

		wp_presence_admin_bar_assets();

		$this->assertTrue( wp_style_is( 'presence-admin-bar', 'enqueued' ) );
		$this->assertStringContainsString(
			'#wp-admin-bar-presence-online',
			implode( '', (array) wp_styles()->get_data( 'presence-admin-bar', 'after' ) )
		);

		remove_filter( 'show_admin_bar', '__return_true' );
	}

	public function test_the_indicator_styles_stay_off_when_the_bar_is_hidden() {
		wp_deregister_style( 'presence-admin-bar' );
		$wp_styles        = wp_styles();
		$wp_styles->queue = array();
		$wp_styles->done  = array();

		wp_set_current_user( self::$editor_id );
		add_filter( 'show_admin_bar', '__return_false' );

		wp_presence_admin_bar_assets();

		$this->assertFalse( wp_style_is( 'presence-admin-bar', 'enqueued' ) );

		remove_filter( 'show_admin_bar', '__return_false' );
	}
}
