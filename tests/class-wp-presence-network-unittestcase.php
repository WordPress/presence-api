<?php
/**
 * Shared scaffolding for tests that exercise the network summary table.
 *
 * The table is network-wide and created with real, non-temporary DDL, so it
 * does not behave like the rest of the suite: rows survive a test's transaction
 * rollback, and a blog left behind by one test lands in the next test's totals.
 * Every network test needs the same setup and the same cleanup to work around
 * that, so it lives here rather than being copied per file.
 *
 * @package Presence_API
 */

abstract class WP_Presence_Network_UnitTestCase extends WP_Presence_UnitTestCase {

	/**
	 * Blogs created by the current test, deleted in tear_down().
	 *
	 * A network summary sums every site on the network, so a blog left behind
	 * by one test would otherwise leak into the next test's totals, unlike the
	 * rest of this suite, which only ever asserts against specific blog IDs and
	 * so doesn't care what else exists.
	 *
	 * @var int[]
	 */
	private $blog_ids = array();

	/**
	 * The network's active plugin list as it stood before the test.
	 *
	 * @var array|false
	 */
	private $network_plugins;

	public function set_up() {
		global $wpdb;

		parent::set_up();

		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires multisite.' );
		}

		// WP_UnitTestCase rewrites DDL to CREATE/DROP TEMPORARY TABLE. The
		// network summary table is created with real, non-temporary DDL (see
		// wp_maybe_create_presence_network_summary_table()), so this suite
		// needs that rewrite disabled to provision it at all.
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		// wp_presence_on_initialize_site() only provisions a new blog's table when
		// the plugin is network active, which the test bootstrap's mu-plugin-style
		// loading never makes true on its own. A network summary is only
		// meaningful when every site actually has a table, so fake that here the
		// same way test-table-creation.php does for its own network-active tests.
		$this->network_plugins = get_site_option( 'active_sitewide_plugins' );
		update_site_option( 'active_sitewide_plugins', array( 'presence-api/presence-api.php' => time() ) );

		wp_maybe_create_presence_network_summary_table();

		// Every admin-room write anywhere in the suite now pushes, and this
		// table is real rather than temporary, so rows can outlive the test that
		// wrote them. Start from empty rather than trusting the last tear_down.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DELETE FROM {$wpdb->presence_network_summary}" );

		wp_set_current_user( 0 );
	}

	public function tear_down() {
		global $wpdb;

		foreach ( $this->blog_ids as $blog_id ) {
			wp_delete_site( $blog_id );
		}
		$this->blog_ids = array();

		if ( false === $this->network_plugins ) {
			delete_site_option( 'active_sitewide_plugins' );
		} else {
			update_site_option( 'active_sitewide_plugins', $this->network_plugins );
		}

		// set_up() disables the temporary-table DDL rewrite (see above), so
		// writes to the real, non-temporary network summary table survive past
		// this test's own transaction rollback -- exactly why blog_ids and
		// active_sitewide_plugins above are cleaned up by hand instead of
		// relying on that rollback. Rows pushed mid-test need the same
		// treatment; the table itself stays (dbDelta is idempotent).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DELETE FROM {$wpdb->presence_network_summary}" );

		parent::tear_down();
	}

	/**
	 * Creates a blog and schedules it for deletion at the end of the test.
	 *
	 * @return int The new blog ID.
	 */
	protected function create_blog() {
		$blog_id          = self::factory()->blog->create();
		$this->blog_ids[] = $blog_id;

		return $blog_id;
	}

	/**
	 * Writes a presence entry on a given site, without leaving the site
	 * switched-to.
	 *
	 * No explicit push: wp_set_presence() fires wp_presence_admin_room_changed,
	 * which is what the push hangs off, so calling one by hand here would test
	 * a path production doesn't take.
	 *
	 * @param int $blog_id The site to write on.
	 * @param int $user_id The user the entry belongs to.
	 */
	protected function set_presence_on_site( $blog_id, $user_id ) {
		switch_to_blog( $blog_id );
		wp_set_presence( 'admin/online', 'user-' . $user_id, array( 'screen' => 'dashboard' ), $user_id );
		restore_current_blog();
	}

	/**
	 * Removes a user's presence entry on a given site, without leaving the site
	 * switched-to.
	 *
	 * @param int $blog_id The site to remove on.
	 * @param int $user_id The user whose entry to remove.
	 */
	protected function remove_presence_on_site( $blog_id, $user_id ) {
		switch_to_blog( $blog_id );
		wp_remove_presence( 'admin/online', 'user-' . $user_id );
		restore_current_blog();
	}

	/**
	 * Grants the current test a network-capable user, so capability-gated
	 * hooks (column registration, views, the query filter) actually run.
	 *
	 * @return int The admin's user ID.
	 */
	protected function become_network_admin() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		grant_super_admin( $admin_id );
		wp_set_current_user( $admin_id );

		return $admin_id;
	}

	/**
	 * Overwrites a site's pushed row in the network summary table directly,
	 * for tests that need to control its content or age precisely rather
	 * than going through a real presence write.
	 *
	 * @param int    $blog_id     The site whose row to overwrite.
	 * @param int[]  $user_ids    User IDs for the data column.
	 * @param string $updated_gmt Optional. Value for the updated_gmt column. Default now.
	 */
	protected function set_network_summary_row( $blog_id, array $user_ids, $updated_gmt = null ) {
		global $wpdb;

		$updated_gmt = $updated_gmt ?? gmdate( 'Y-m-d H:i:s' );

		$wpdb->replace(
			$wpdb->presence_network_summary,
			array(
				'blog_id'     => $blog_id,
				'data'        => wp_presence_encode_network_summary_row( $blog_id, $user_ids ),
				'updated_gmt' => $updated_gmt,
			)
		);

		// A real push also leaves a record of itself on the site that pushed,
		// which is what wp_presence_network_summary_needs_push() reads. Writing
		// only the row would leave the two disagreeing about when this site last
		// pushed, a state no push produces. Skipped for a blog_id with no site
		// behind it, which is a row left over from a deleted site and has no
		// options table to record anything in.
		if ( ! get_site( $blog_id ) ) {
			return;
		}

		sort( $user_ids );

		switch_to_blog( $blog_id );
		update_option(
			'wp_presence_network_pushed',
			array(
				'users' => implode( ',', $user_ids ),
				'time'  => strtotime( $updated_gmt . ' UTC' ),
			),
			true
		);
		restore_current_blog();
	}

	/**
	 * Counts the statements naming the summary table that a callback produces.
	 *
	 * @param callable $during Code to run while counting.
	 * @return int Statement count.
	 */
	protected function count_summary_table_statements( callable $during ) {
		global $wpdb;

		$count = 0;
		$table = $wpdb->presence_network_summary;

		$counter = static function ( $query ) use ( &$count, $table ) {
			if ( false !== strpos( $query, $table ) ) {
				++$count;
			}

			return $query;
		};

		add_filter( 'query', $counter );
		$during();
		remove_filter( 'query', $counter );

		return $count;
	}

	/**
	 * Returns a site's raw summary row.
	 *
	 * @param int $blog_id The site whose row to read.
	 * @return object|null Row with data and updated_gmt, null if the site never pushed.
	 */
	protected function get_network_summary_row( $blog_id ) {
		global $wpdb;

		return $wpdb->get_row(
			$wpdb->prepare( "SELECT data, updated_gmt FROM {$wpdb->presence_network_summary} WHERE blog_id = %d", $blog_id )
		);
	}
}
