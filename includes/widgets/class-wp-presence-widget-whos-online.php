<?php
/**
 * Dashboard Widget: Who's Online
 *
 * @package Presence_API
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the "Who's Online" dashboard widget with Heartbeat integration.
 */
class WP_Presence_Widget_Whos_Online {

	/**
	 * Maximum number of users shown as full rows before collapsing.
	 *
	 * @var int
	 */
	const VISIBLE_ROWS = 3;

	/**
	 * Maximum overflow users before switching to summary mode.
	 *
	 * When overflow exceeds this threshold, the widget shows an avatar
	 * stack with a count linking to the Users page instead of rendering
	 * every user as an expandable list.
	 *
	 * @var int
	 */
	const OVERFLOW_THRESHOLD = 20;

	/**
	 * Seconds after which a user is considered idle (but still present).
	 *
	 * @var int
	 */
	const IDLE_THRESHOLD = 30;

	/**
	 * Maximum avatars in the overflow stack.
	 *
	 * @var int
	 */
	const AVATAR_STACK_MAX = 4;

	/**
	 * Returns the fixed overflow threshold.
	 *
	 * When the number of overflow users exceeds this value, the widget
	 * switches from an expandable list to a compact summary linking to
	 * the Users page.
	 *
	 * @return int The overflow threshold.
	 */
	public static function get_overflow_threshold() {
		return self::OVERFLOW_THRESHOLD;
	}

	/**
	 * Returns a map of pagenow slugs to translatable screen labels.
	 *
	 * @return array Associative array of slug => label.
	 */
	private static function get_screen_labels() {
		return array(
			'dashboard'          => __( 'Dashboard', 'presence-api' ),
			'edit'               => __( 'Posts', 'presence-api' ),
			'post'               => __( 'Editing post', 'presence-api' ),
			'edit-post'          => __( 'Editing post', 'presence-api' ),
			'post-new'           => __( 'Writing post', 'presence-api' ),
			'edit-page'          => __( 'Pages', 'presence-api' ),
			'page'               => __( 'Editing page', 'presence-api' ),
			'upload'             => __( 'Media', 'presence-api' ),
			'media'              => __( 'Media', 'presence-api' ),
			'edit-comments'      => __( 'Comments', 'presence-api' ),
			'comment'            => __( 'Comments', 'presence-api' ),
			'themes'             => __( 'Themes', 'presence-api' ),
			'widgets'            => __( 'Widgets', 'presence-api' ),
			'nav-menus'          => __( 'Menus', 'presence-api' ),
			'plugins'            => __( 'Plugins', 'presence-api' ),
			'users'              => __( 'Users', 'presence-api' ),
			'profile'            => __( 'Profile', 'presence-api' ),
			'user-edit'          => __( 'Users', 'presence-api' ),
			'tools'              => __( 'Tools', 'presence-api' ),
			'import'             => __( 'Import', 'presence-api' ),
			'export'             => __( 'Export', 'presence-api' ),
			'options-general'    => __( 'Settings', 'presence-api' ),
			'options-writing'    => __( 'Settings', 'presence-api' ),
			'options-reading'    => __( 'Settings', 'presence-api' ),
			'options-discussion' => __( 'Settings', 'presence-api' ),
			'options-media'      => __( 'Settings', 'presence-api' ),
			'options-permalink'  => __( 'Settings', 'presence-api' ),
			'front'              => __( 'Viewing site', 'presence-api' ),
			'login'              => __( 'Logging in', 'presence-api' ),
		);
	}

	/**
	 * Registers the dashboard widget.
	 */
	public static function register() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		wp_add_dashboard_widget(
			'presence_whos_online',
			__( "Who's Online", 'presence-api' ),
			array( __CLASS__, 'render' ),
			null,
			null,
			'normal',
			'default'
		);

		// Enqueue heartbeat and widget scripts.
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );
	}

	/**
	 * Enqueues the widget's JavaScript and CSS.
	 *
	 * @param string $hook_suffix The current admin page.
	 */
	public static function enqueue_scripts( $hook_suffix ) {
		if ( 'index.php' !== $hook_suffix ) {
			return;
		}

		wp_presence_enqueue_avatar_stack_script();

		wp_enqueue_script(
			'presence-dashboard-widget',
			WP_PRESENCE_PLUGIN_URL . 'assets/js/whos-online-widget.js',
			array( 'jquery', 'heartbeat', 'wp-presence-avatar-stack' ),
			WP_PRESENCE_VERSION,
			true
		);

		wp_add_inline_script(
			'presence-dashboard-widget',
			sprintf( 'window.wpPresenceWhosOnline = %s;', wp_json_encode( self::get_script_config() ) ),
			'before'
		);

		wp_presence_enqueue_avatar_stack_style();

		wp_register_style( 'presence-dashboard-widget', false, array(), WP_PRESENCE_VERSION );
		wp_enqueue_style( 'presence-dashboard-widget' );
		wp_add_inline_style( 'presence-dashboard-widget', self::get_inline_css() );
	}

	/**
	 * Returns the inline CSS for the dashboard widget.
	 *
	 * @return string CSS code.
	 */
	private static function get_inline_css() {
		return '#presence-whos-online-list p { margin: 0; padding: 6px 12px; color: #646970; }
			#presence-whos-online-list .presence-user-list { margin: 0; }
			#presence-whos-online-list .presence-user-item { display: flex; align-items: center; gap: 8px; padding: 6px 12px; border-bottom: 1px solid #f0f0f1; }
			#presence-whos-online-list .presence-user-item:last-child { border-bottom: none; }
			#presence-whos-online-list .presence-user-item img { border-radius: 50%; flex-shrink: 0; }
			#presence-whos-online-list .presence-user-info { flex: 1; min-width: 0; }
			#presence-whos-online-list .presence-name { font-weight: 400; }
			#presence-whos-online-list .presence-online-dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: #00a32a; flex-shrink: 0; margin-left: auto; }
			#presence-whos-online-list .presence-online-dot.is-idle { background: transparent; border: 1.5px solid #a7aaad; width: 5px; height: 5px; }
			#presence-whos-online-list .presence-screen { display: block; color: #646970; font-size: 12px; line-height: 1.4; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
			#presence-whos-online-list .presence-screen a { color: inherit; text-decoration: none; }
			#presence-whos-online-list .presence-screen a:hover { text-decoration: underline; }
			#presence-whos-online-list .presence-overflow-toggle { background: none; border: none; padding: 6px 12px; color: var(--wp-admin-theme-color, #2271b1); font-size: 13px; cursor: pointer; width: 100%; text-align: left; display: flex; align-items: center; gap: 4px; }
			#presence-whos-online-list .presence-overflow-toggle:hover .presence-overflow-text { text-decoration: underline; }
			#presence-whos-online-list .presence-overflow-toggle:focus { outline: 2px solid var(--wp-admin-theme-color, #2271b1); outline-offset: -2px; }
			#presence-whos-online-list .presence-overflow-expanded { margin: 0; }
			#presence-whos-online-list .presence-overflow-expanded .presence-user-item:first-child { border-top: 1px solid #f0f0f1; }
			#presence-whos-online-list .screen-reader-text { border: 0; clip: rect(1px, 1px, 1px, 1px); clip-path: inset(50%); height: 1px; margin: -1px; overflow: hidden; padding: 0; position: absolute; width: 1px; word-wrap: normal !important; }';
	}

	/**
	 * Returns the admin URL for a pagenow screen slug, if linkable.
	 *
	 * @param string $screen The pagenow slug.
	 * @return string|false The admin URL, or false if not linkable.
	 */
	public static function get_screen_url( $screen ) {
		$map = array(
			'dashboard'          => '',
			'edit'               => 'edit.php',
			'post'               => 'edit.php',
			'edit-post'          => 'edit.php',
			'post-new'           => 'post-new.php',
			'edit-page'          => 'edit.php?post_type=page',
			'page'               => 'edit.php?post_type=page',
			'upload'             => 'upload.php',
			'media'              => 'upload.php',
			'edit-comments'      => 'edit-comments.php',
			'comment'            => 'edit-comments.php',
			'themes'             => 'themes.php',
			'widgets'            => 'widgets.php',
			'nav-menus'          => 'nav-menus.php',
			'plugins'            => 'plugins.php',
			'users'              => 'users.php',
			'profile'            => 'profile.php',
			'user-edit'          => 'users.php',
			'tools'              => 'tools.php',
			'import'             => 'import.php',
			'export'             => 'export.php',
			'options-general'    => 'options-general.php',
			'options-writing'    => 'options-writing.php',
			'options-reading'    => 'options-reading.php',
			'options-discussion' => 'options-discussion.php',
			'options-media'      => 'options-media.php',
			'options-permalink'  => 'options-permalink.php',
		);

		if ( isset( $map[ $screen ] ) ) {
			return admin_url( $map[ $screen ] );
		}

		return false;
	}

	/**
	 * Returns a context-aware screen label using post status when available.
	 *
	 * @param string $screen      The pagenow slug.
	 * @param string $post_status Optional. The post status (draft, publish, etc.).
	 * @return string The friendly label.
	 */
	public static function get_rich_screen_label( $screen, $post_status = '' ) {
		if ( $post_status && in_array( $screen, array( 'post', 'edit-post', 'page' ), true ) ) {
			$type = in_array( $screen, array( 'page' ), true ) ? 'page' : 'post';
			switch ( $post_status ) {
				case 'draft':
				case 'auto-draft':
					return 'page' === $type ? __( 'Drafting page', 'presence-api' ) : __( 'Drafting post', 'presence-api' );
				case 'pending':
					return 'page' === $type ? __( 'Pending page', 'presence-api' ) : __( 'Pending post', 'presence-api' );
				case 'private':
					return 'page' === $type ? __( 'Editing private page', 'presence-api' ) : __( 'Editing private post', 'presence-api' );
				case 'future':
					return 'page' === $type ? __( 'Editing scheduled page', 'presence-api' ) : __( 'Editing scheduled post', 'presence-api' );
				default:
					return 'page' === $type ? __( 'Editing page', 'presence-api' ) : __( 'Editing post', 'presence-api' );
			}
		}

		return self::get_screen_label( $screen );
	}

	/**
	 * Returns a human-readable label for a pagenow screen slug.
	 *
	 * @param string $screen The pagenow slug.
	 * @return string The friendly label.
	 */
	public static function get_screen_label( $screen ) {
		$labels = self::get_screen_labels();
		if ( isset( $labels[ $screen ] ) ) {
			return $labels[ $screen ];
		}

		// Fallback: title-case and strip hyphens.
		return ucwords( str_replace( array( '-', '_' ), ' ', $screen ) );
	}

	/**
	 * Returns the configuration the widget's client script reads.
	 *
	 * @return array Script configuration.
	 */
	private static function get_script_config() {
		// Build URL map for linkable screens.
		$screen_urls = array();
		foreach ( array_keys( self::get_screen_labels() ) as $slug ) {
			$url = self::get_screen_url( $slug );
			if ( $url ) {
				$screen_urls[ $slug ] = $url;
			}
		}

		return array(
			'screenLabels'      => self::get_screen_labels(),
			'screenUrls'        => $screen_urls,
			'idleThreshold'     => self::IDLE_THRESHOLD,
			'overflowThreshold' => self::get_overflow_threshold(),
			'usersUrl'          => admin_url( 'users.php?presence_status=online' ),
			'avatarMax'         => self::AVATAR_STACK_MAX,
			'maxRows'           => self::VISIBLE_ROWS,
			'i18n'              => array(
				'noUsersOnline'   => __( 'No users are online.', 'presence-api' ),
				'onlineNow'       => __( 'Online now', 'presence-api' ),
				'usersOnline'     => __( 'Users currently online', 'presence-api' ),
				'additionalUsers' => __( 'Additional online users', 'presence-api' ),
				'showLess'        => __( 'Show less', 'presence-api' ),
				/* translators: %d: Number of additional online users. */
				'moreCount'       => __( '+%d more', 'presence-api' ),
				/* translators: %d: Number of additional online users. */
				'moreCountLink'   => __( '+%d more — view all users', 'presence-api' ),
				/* translators: %d: Number of seconds. */
				'secondsAgo'      => __( '%d seconds ago', 'presence-api' ),
				/* translators: %d: Number of minutes. */
				'minutesAgo'      => __( '%d min ago', 'presence-api' ),
				/* translators: %d: Number of hours (singular). */
				'hourAgo'         => __( '%d hour ago', 'presence-api' ),
				/* translators: %d: Number of hours (plural). */
				'hoursAgo'        => __( '%d hours ago', 'presence-api' ),
			),
		);
	}

	/**
	 * Renders a single user row in the presence list.
	 *
	 * @param object  $entry The presence entry object.
	 * @param WP_User $user  The user object.
	 */
	private static function render_user_row( $entry, $user ) {
		$screen  = isset( $entry->data['screen'] ) ? $entry->data['screen'] : '';
		$elapsed = time() - strtotime( $entry->date_gmt . ' +0000' );

		if ( $elapsed < self::IDLE_THRESHOLD ) {
			$dot_label = __( 'Online now', 'presence-api' );
		} else {
			/* translators: %s: Human-readable time difference. */
			$dot_label = sprintf( __( '%s ago', 'presence-api' ), human_time_diff( strtotime( $entry->date_gmt . ' +0000' ), time() ) );
		}

		$idle_class = $elapsed >= self::IDLE_THRESHOLD ? ' is-idle' : '';

		echo '<li class="presence-user-item" data-user-id="' . (int) $entry->user_id . '">';
		echo wp_kses_post( get_avatar( $user->ID, 24, '', $user->display_name ) );
		echo '<div class="presence-user-info">';
		echo '<span class="presence-name">' . esc_html( $user->display_name ) . '</span>';

		if ( $screen ) {
			$screen_url  = self::get_screen_url( $screen );
			$post_status = isset( $entry->data['post_status'] ) ? $entry->data['post_status'] : '';
			$screen_text = self::get_rich_screen_label( $screen, $post_status );

			// Use frontend post title when available.
			if ( 'front' === $screen && ! empty( $entry->data['title'] ) ) {
				$screen_text = $entry->data['title'];
				if ( ! empty( $entry->data['post_id'] ) ) {
					$screen_url = get_permalink( (int) $entry->data['post_id'] );
				}
			}

			// Italicize the verb (first word).
			$parts     = explode( ' ', $screen_text, 2 );
			$formatted = count( $parts ) > 1
				? '<em>' . esc_html( $parts[0] ) . '</em> ' . esc_html( $parts[1] )
				: esc_html( $screen_text );

			$allowed = array( 'em' => array() );
			if ( $screen_url ) {
				echo '<span class="presence-screen"><a href="' . esc_url( $screen_url ) . '">' . wp_kses( $formatted, $allowed ) . '</a></span>';
			} else {
				echo '<span class="presence-screen">' . wp_kses( $formatted, $allowed ) . '</span>';
			}
		}

		echo '</div>';
		echo '<span class="presence-online-dot' . esc_attr( $idle_class ) . '" role="img" aria-label="' . esc_attr( $dot_label ) . '" title="' . esc_attr( $dot_label ) . '"></span>';
		echo '</li>';
	}

	/**
	 * Renders the dashboard widget.
	 */
	public static function render() {
		// Lists everyone present, you included, so the rows and the count agree
		// with the admin bar and the users list. You are always one of them.
		$entries = array_values( wp_presence_with_current_user( wp_get_presence( wp_presence_admin_room() ) ) );

		echo '<div id="presence-whos-online-list" aria-live="polite" tabindex="-1">';

		cache_users( wp_list_pluck( $entries, 'user_id' ) );

		$visible  = array_slice( $entries, 0, self::VISIBLE_ROWS );
		$overflow = array_slice( $entries, self::VISIBLE_ROWS );

		echo '<ul class="presence-user-list" aria-label="' . esc_attr__( 'Users currently online', 'presence-api' ) . '">';

		foreach ( $visible as $entry ) {
			$user = get_userdata( $entry->user_id );

			if ( ! $user ) {
				continue;
			}

			self::render_user_row( $entry, $user );
		}

		echo '</ul>';

		if ( ! empty( $overflow ) ) {
			$stack_max   = min( count( $overflow ), self::AVATAR_STACK_MAX );
			$stack_users = array();

			foreach ( array_slice( $overflow, 0, $stack_max ) as $oentry ) {
				$ouser = get_userdata( $oentry->user_id );

				if ( ! $ouser ) {
					continue;
				}

				$stack_users[] = array(
					'display_name' => $ouser->display_name,
					'avatar_url'   => get_avatar_url( $ouser->ID, array( 'size' => 24 ) ),
				);
			}

			if ( count( $overflow ) > self::get_overflow_threshold() ) {
				// Summary mode: avatar stack + count linking to Users page.
				echo '<a href="' . esc_url( admin_url( 'users.php?presence_status=online' ) ) . '" class="presence-overflow-toggle">';
				echo wp_kses_post( wp_presence_render_avatar_stack( $stack_users, self::AVATAR_STACK_MAX ) );
				echo '<span class="presence-overflow-text">';
				/* translators: %d: Number of additional online users. */
				echo esc_html( sprintf( __( '+%d more — view all users', 'presence-api' ), count( $overflow ) ) );
				echo '</span></a>';
			} else {
				// Expandable list mode.
				echo '<button type="button" class="presence-overflow-toggle" data-action="expand" aria-expanded="false" aria-controls="presence-overflow-list">';
				echo wp_kses_post( wp_presence_render_avatar_stack( $stack_users, self::AVATAR_STACK_MAX ) );
				echo '<span class="presence-overflow-text">';
				/* translators: %d: Number of additional online users. */
				echo esc_html( sprintf( __( '+%d more', 'presence-api' ), count( $overflow ) ) );
				echo '</span></button>';

				echo '<ul id="presence-overflow-list" class="presence-overflow-expanded" aria-label="' . esc_attr__( 'Additional online users', 'presence-api' ) . '" style="display:none">';

				foreach ( $overflow as $entry ) {
					$user = get_userdata( $entry->user_id );

					if ( ! $user ) {
						continue;
					}

					self::render_user_row( $entry, $user );
				}

				echo '</ul>';
				echo '<button type="button" class="presence-overflow-toggle" data-action="collapse" aria-expanded="false" aria-controls="presence-overflow-list" style="display:none">';
				echo esc_html__( 'Show less', 'presence-api' );
				echo '</button>';
			}
		}

		echo '</div>';
	}

	/**
	 * Handles the heartbeat received event for presence updates.
	 *
	 * Returns avatar URLs and timestamps rather than pre-rendered HTML, or only
	 * last-seen times when the client's hash still matches the room.
	 *
	 * The current user's own entry is written by
	 * wp_presence_admin_heartbeat_received(), which runs at an earlier
	 * priority on this same filter.
	 *
	 * @param array  $response  The Heartbeat response.
	 * @param array  $data      The $_POST data sent.
	 * @param string $screen_id The screen ID.
	 * Nonce verification is handled by WordPress in wp_ajax_heartbeat().
	 *
	 * @return array The Heartbeat response.
	 */
	public static function heartbeat_received( $response, $data, $screen_id ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Required by filter signature.
		if ( empty( $data['presence-ping'] ) ) {
			return $response;
		}

		if ( ! current_user_can( 'edit_posts' ) ) {
			return $response;
		}

		$entries = wp_get_presence( wp_presence_admin_room() );
		$hash    = self::hash_online_state( $entries );

		$client_hash = isset( $data['presence-online-hash'] ) ? sanitize_text_field( $data['presence-online-hash'] ) : '';

		if ( $client_hash && $client_hash === $hash ) {
			$response['presence-online-unchanged'] = self::build_seen_timestamps( $entries );

			return $response;
		}

		// Cap to visible rows plus overflow threshold (expandable list max).
		$cap                               = self::VISIBLE_ROWS + self::get_overflow_threshold();
		$response['presence-online']       = self::build_online_entries(
			array_slice( $entries, 0, $cap )
		);
		$response['presence-online-total'] = count( $entries );
		$response['presence-online-hash']  = $hash;

		return $response;
	}

	/**
	 * Hashes the meaningful state of a room's presence entries.
	 *
	 * Excludes date_gmt, which every tick rewrites for the pinging user and so
	 * would flip the hash on every tick.
	 *
	 * @param array $entries Presence entry objects from wp_get_presence().
	 * @return string The state hash.
	 */
	private static function hash_online_state( $entries ) {
		$state = array();

		foreach ( $entries as $entry ) {
			$state[] = array(
				(int) $entry->user_id,
				isset( $entry->data['screen'] ) ? $entry->data['screen'] : '',
				isset( $entry->data['post_status'] ) ? $entry->data['post_status'] : '',
				isset( $entry->data['title'] ) ? $entry->data['title'] : '',
				isset( $entry->data['post_id'] ) ? (int) $entry->data['post_id'] : 0,
			);
		}

		// wp_get_presence() orders by date_gmt, which reshuffles as clients ping.
		sort( $state );

		return md5( (string) wp_json_encode( $state ) );
	}

	/**
	 * Maps each present user to the time they were last seen.
	 *
	 * Replaces the full payload when the room is unchanged, so the client can
	 * still tell a user who is ticking from one whose tab went hidden.
	 *
	 * @param array $entries Presence entry objects from wp_get_presence().
	 * @return object User ID to last-seen GMT datetime.
	 */
	private static function build_seen_timestamps( $entries ) {
		$seen = array();

		foreach ( $entries as $entry ) {
			$seen[ (int) $entry->user_id ] = $entry->date_gmt;
		}

		// Cast so an empty room encodes as {} rather than [].
		return (object) $seen;
	}

	/**
	 * Builds the structured presence payload for a room's entries.
	 *
	 * @param array $entries Presence entry objects from wp_get_presence().
	 * @return array Presence data for client consumption.
	 */
	private static function build_online_entries( $entries ) {
		$online = array();

		cache_users( wp_list_pluck( $entries, 'user_id' ) );

		foreach ( $entries as $entry ) {
			$user = get_userdata( $entry->user_id );

			if ( ! $user ) {
				continue;
			}

			$screen     = isset( $entry->data['screen'] ) ? $entry->data['screen'] : '';
			$entry_ps   = isset( $entry->data['post_status'] ) ? $entry->data['post_status'] : '';
			$rich_label = $screen ? self::get_rich_screen_label( $screen, $entry_ps ) : '';

			// Use the post title as the label for frontend singular views.
			if ( 'front' === $screen && ! empty( $entry->data['title'] ) ) {
				$rich_label = $entry->data['title'];
			}

			$online[] = array(
				'user_id'      => (int) $entry->user_id,
				'display_name' => $user->display_name,
				'avatar_url'   => get_avatar_url( $user->ID, array( 'size' => 32 ) ),
				'screen'       => $screen,
				'screen_label' => $rich_label,
				'date_gmt'     => $entry->date_gmt,
			);
		}

		return $online;
	}
}
