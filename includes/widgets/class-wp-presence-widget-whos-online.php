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
	 * The presence room used by this widget.
	 *
	 * @var string
	 */
	const ROOM = 'admin/online';

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
	 * Returns the overflow threshold, filtered for customization.
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

		wp_add_inline_script(
			'heartbeat',
			self::get_inline_script()
		);

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
			#presence-whos-online-list .presence-avatar-stack { display: flex; align-items: center; }
			#presence-whos-online-list .presence-avatar-stack img { border-radius: 50%; width: 20px; height: 20px; margin-inline-start: -6px; box-shadow: 0 0 0 2px #fff; position: relative; }
			#presence-whos-online-list .presence-avatar-stack img:first-child { margin-inline-start: 0; }
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
	 * Returns the inline JavaScript for Heartbeat integration.
	 *
	 * @return string JavaScript code.
	 */
	private static function get_inline_script() {
		$labels_json = wp_json_encode( self::get_screen_labels() );

		// Build URL map for linkable screens.
		$screen_urls = array();
		foreach ( array_keys( self::get_screen_labels() ) as $slug ) {
			$url = self::get_screen_url( $slug );
			if ( $url ) {
				$screen_urls[ $slug ] = $url;
			}
		}
		$urls_json = wp_json_encode( $screen_urls );
		$i18n_json = wp_json_encode(
			array(
				'noUsersOnline'   => __( 'No other users are online.', 'presence-api' ),
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
			)
		);

		return sprintf(
			<<<'JS'
(function($) {
	if (typeof wp === 'undefined' || typeof wp.heartbeat === 'undefined') {
		return;
	}

	var screenLabels = %s;
	var screenUrls = %s;
	var i18n = %s;
	var currentUserId = %d;
	var idleThreshold = %d;
	var overflowThreshold = %d;
	var usersUrl = %s;

	function esc(str) {
		var el = document.createElement('span');
		el.textContent = str;
		return el.innerHTML;
	}

	function friendlyScreen(slug) {
		if (screenLabels[slug]) {
			return screenLabels[slug];
		}
		return slug.replace(/[-_]/g, ' ').replace(/\b\w/g, function(c) { return c.toUpperCase(); });
	}

	function relativeTime(dateGmt) {
		var seconds = Math.floor(Date.now() / 1000 - new Date(dateGmt + 'Z').getTime() / 1000);
		if (seconds < idleThreshold) {
			return '';
		}
		if (seconds < 60) {
			return i18n.secondsAgo.replace('%%d', seconds);
		}
		var minutes = Math.floor(seconds / 60);
		if (minutes < 60) {
			return i18n.minutesAgo.replace('%%d', minutes);
		}
		var hours = Math.floor(minutes / 60);
		return (hours > 1 ? i18n.hoursAgo : i18n.hourAgo).replace('%%d', hours);
	}

	var isExpanded = false;
	var lastSignature = '';
	var lastEntries = [];

	function buildRowHtml(entry) {
		var html = '';
		if (entry.avatar_url) {
			html += '<img src="' + esc(entry.avatar_url) + '" width="24" height="24" alt="' + esc(entry.display_name) + '" />';
		}
		var timeStr = entry.date_gmt ? relativeTime(entry.date_gmt) : '';
		var dotTitle = timeStr || i18n.onlineNow;
		var elapsed = entry.date_gmt ? Math.floor(Date.now() / 1000 - new Date(entry.date_gmt + 'Z').getTime() / 1000) : 0;
		var idleClass = elapsed >= idleThreshold ? ' is-idle' : '';
		html += '<div class="presence-user-info">';
		html += '<span class="presence-name">' + esc(entry.display_name) + '</span>';
		if (entry.screen) {
			var screenText = entry.screen_label || friendlyScreen(entry.screen);
			var parts = screenText.split(' ');
			var formatted = parts.length > 1 ? '<em>' + esc(parts[0]) + '</em> ' + esc(parts.slice(1).join(' ')) : esc(screenText);
			if (screenUrls[entry.screen]) {
				html += '<span class="presence-screen"><a href="' + esc(screenUrls[entry.screen]) + '">' + formatted + '</a></span>';
			} else {
				html += '<span class="presence-screen">' + formatted + '</span>';
			}
		}
		html += '</div>';
		html += '<span class="presence-online-dot' + idleClass + '" role="img" aria-label="' + esc(dotTitle) + '" title="' + esc(dotTitle) + '"></span>';
		return html;
	}

	function buildAvatarStack(overflow) {
		var stackMax = Math.min(overflow.length, 4);
		var html = '<span class="presence-avatar-stack">';
		overflow.slice(0, stackMax).forEach(function(entry, idx) {
			if (entry.avatar_url) {
				html += '<img src="' + esc(entry.avatar_url) + '" width="24" height="24" style="z-index:' + (stackMax - idx) + '" alt="' + esc(entry.display_name) + '" />';
			}
		});
		html += '</span>';
		return html;
	}

	function buildFullHtml(visible, overflow) {
		var html = '<ul class="presence-user-list" aria-label="' + esc(i18n.usersOnline) + '">';
		visible.forEach(function(entry) {
			html += '<li class="presence-user-item" data-user-id="' + entry.user_id + '">' + buildRowHtml(entry) + '</li>';
		});
		html += '</ul>';
		if (overflow.length) {
			if (overflow.length > overflowThreshold) {
				// Summary mode: avatar stack + count linking to Users page.
				html += '<a href="' + esc(usersUrl) + '" class="presence-overflow-toggle">';
				html += buildAvatarStack(overflow);
				html += '<span class="presence-overflow-text">' + esc(i18n.moreCountLink.replace('%%d', overflow.length)) + '</span></a>';
			} else {
				// Expandable list mode.
				html += '<button type="button" class="presence-overflow-toggle" data-action="expand" aria-expanded="' + (isExpanded ? 'true' : 'false') + '" aria-controls="presence-overflow-list"';
				if (isExpanded) html += ' style="display:none"';
				html += '>';
				html += buildAvatarStack(overflow);
				html += '<span class="presence-overflow-text">' + esc(i18n.moreCount.replace('%%d', overflow.length)) + '</span></button>';
				html += '<ul id="presence-overflow-list" class="presence-overflow-expanded" aria-label="' + esc(i18n.additionalUsers) + '"';
				if (!isExpanded) html += ' style="display:none"';
				html += '>';
				overflow.forEach(function(entry) {
					html += '<li class="presence-user-item" data-user-id="' + entry.user_id + '">' + buildRowHtml(entry) + '</li>';
				});
				html += '</ul>';
				html += '<button type="button" class="presence-overflow-toggle" data-action="collapse" aria-controls="presence-overflow-list"';
				if (!isExpanded) html += ' style="display:none"';
				html += '>' + esc(i18n.showLess) + '</button>';
			}
		}
		return html;
	}

	// Update the widget when heartbeat response comes back.
	$(document).on('heartbeat-tick', function(event, data) {
		if (!data['presence-online']) {
			return;
		}

		var container = $('#presence-whos-online-list');
		if (!container.length) {
			return;
		}

		var entries = data['presence-online'].filter(function(e) {
			return e.user_id !== currentUserId;
		});
		lastEntries = entries;

		if (!entries.length) {
			if (lastSignature !== '') {
				container.html('<p>' + esc(i18n.noUsersOnline) + '</p>');
				lastSignature = '';
			}
			return;
		}

		// Sort by most recently active first.
		entries.sort(function(a, b) {
			return (b.date_gmt || '').localeCompare(a.date_gmt || '');
		});

		var maxRows = %d;
		var visible = entries.slice(0, maxRows);
		var overflow = entries.slice(maxRows);

		// Build a signature of user IDs + screens to detect real changes.
		var sig = entries.map(function(e) { return e.user_id + ':' + (e.screen || ''); }).join(',');

		if (sig !== lastSignature) {
			// Content changed — swap HTML instantly.
			container.html(buildFullHtml(visible, overflow));
			lastSignature = sig;
		} else {
			// Same users, same screens — update only dot tooltips.
			container.find('.presence-user-item').each(function() {
				var uid = $(this).data('user-id');
				for (var i = 0; i < entries.length; i++) {
					if (entries[i].user_id === uid) {
						var timeStr = entries[i].date_gmt ? relativeTime(entries[i].date_gmt) : '';
						var dotTitle = timeStr || i18n.onlineNow;
						$(this).find('.presence-online-dot').attr('title', dotTitle).attr('aria-label', dotTitle);
						break;
					}
				}
			});
		}
	});

	// Re-evaluate idle dots between heartbeat ticks.
	setInterval(function() {
		if (!lastEntries.length) return;
		$('#presence-whos-online-list .presence-user-item').each(function() {
			var uid = $(this).data('user-id');
			for (var i = 0; i < lastEntries.length; i++) {
				if (lastEntries[i].user_id === uid && lastEntries[i].date_gmt) {
					var elapsed = Math.floor(Date.now() / 1000 - new Date(lastEntries[i].date_gmt + 'Z').getTime() / 1000);
					var dot = $(this).find('.presence-online-dot');
					dot.toggleClass('is-idle', elapsed >= idleThreshold);
					var timeStr = relativeTime(lastEntries[i].date_gmt);
					var dotTitle = timeStr || i18n.onlineNow;
					dot.attr('title', dotTitle).attr('aria-label', dotTitle);
					break;
				}
			}
		});
	}, 5000);

	// Toggle expand/collapse.
	$('#presence-whos-online-list').on('click', '.presence-overflow-toggle', function() {
		isExpanded = $(this).data('action') === 'expand';
		var wrap = $('#presence-whos-online-list');
		wrap.find('[data-action="expand"]').toggle(!isExpanded).attr('aria-expanded', String(!isExpanded));
		wrap.find('#presence-overflow-list').toggle(isExpanded);
		wrap.find('[data-action="collapse"]').toggle(isExpanded).attr('aria-expanded', String(isExpanded));
	});
})(jQuery);
JS,
			$labels_json,
			$urls_json,
			$i18n_json,
			get_current_user_id(),
			self::IDLE_THRESHOLD,
			self::get_overflow_threshold(),
			wp_json_encode( admin_url( 'users.php?presence_status=online' ) ),
			self::VISIBLE_ROWS
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
		echo wp_kses_post( get_avatar( $user->ID, 24 ) );
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
		$entries     = wp_get_presence( self::ROOM );
		$current_uid = get_current_user_id();
		$entries     = array_values(
			array_filter(
				$entries,
				function ( $e ) use ( $current_uid ) {
					return (int) $e->user_id !== $current_uid;
				}
			)
		);

		echo '<div id="presence-whos-online-list" aria-live="polite">';

		if ( empty( $entries ) ) {
			echo '<p>' . esc_html__( 'No other users are online.', 'presence-api' ) . '</p>';
		} else {
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
				$stack_max = min( count( $overflow ), 4 );

				if ( count( $overflow ) > self::get_overflow_threshold() ) {
					// Summary mode: avatar stack + count linking to Users page.
					echo '<a href="' . esc_url( admin_url( 'users.php?presence_status=online' ) ) . '" class="presence-overflow-toggle">';
					echo '<span class="presence-avatar-stack">';

					foreach ( array_slice( $overflow, 0, $stack_max ) as $index => $oentry ) {
						$ouser = get_userdata( $oentry->user_id );

						if ( ! $ouser ) {
							continue;
						}

						$z = $stack_max - $index;
						echo '<img src="' . esc_url( get_avatar_url( $ouser->ID, array( 'size' => 24 ) ) ) . '" width="24" height="24" style="z-index:' . (int) $z . '" alt="' . esc_attr( $ouser->display_name ) . '" />';
					}

					echo '</span><span class="presence-overflow-text">';
					/* translators: %d: Number of additional online users. */
					echo esc_html( sprintf( __( '+%d more — view all users', 'presence-api' ), count( $overflow ) ) );
					echo '</span></a>';
				} else {
					// Expandable list mode.
					echo '<button type="button" class="presence-overflow-toggle" data-action="expand" aria-expanded="false" aria-controls="presence-overflow-list">';
					echo '<span class="presence-avatar-stack">';

					foreach ( array_slice( $overflow, 0, $stack_max ) as $index => $oentry ) {
						$ouser = get_userdata( $oentry->user_id );

						if ( ! $ouser ) {
							continue;
						}

						$z = $stack_max - $index;
						echo '<img src="' . esc_url( get_avatar_url( $ouser->ID, array( 'size' => 24 ) ) ) . '" width="24" height="24" style="z-index:' . (int) $z . '" alt="' . esc_attr( $ouser->display_name ) . '" />';
					}

					echo '</span><span class="presence-overflow-text">';
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
		}

		echo '</div>';
	}

	/**
	 * Handles the heartbeat received event for presence updates.
	 *
	 * Sets the current user's presence and returns structured presence
	 * data for all users in the room. Returns avatar URLs and timestamps
	 * rather than pre-rendered HTML, allowing clients to render as needed.
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

		$user_id   = get_current_user_id();
		$client_id = 'user-' . $user_id;
		$screen    = isset( $data['presence-ping']['screen'] ) ? sanitize_text_field( $data['presence-ping']['screen'] ) : '';

		// Enrich post-editing screens with the post status.
		$post_status = '';
		if ( in_array( $screen, array( 'post', 'edit-post', 'page' ), true ) ) {
			// The editor heartbeat includes the post ID in wp-refresh-post-lock.
			$post_id = 0;
			if ( ! empty( $data['wp-refresh-post-lock']['post_id'] ) ) {
				$post_id = absint( $data['wp-refresh-post-lock']['post_id'] );
			} elseif ( ! empty( $data['presence-editor-ping']['post_id'] ) ) {
				$post_id = absint( $data['presence-editor-ping']['post_id'] );
			}
			if ( $post_id ) {
				$post = get_post( $post_id );
				if ( $post && current_user_can( 'edit_post', $post_id ) && isset( get_post_stati()[ $post->post_status ] ) ) {
					$post_status = $post->post_status;
				}
			}
		}

		$state = array( 'screen' => $screen );
		if ( $post_status ) {
			$state['post_status'] = $post_status;
		}

		// Include frontend post context when viewing a singular page/post.
		if ( 'front' === $screen && ! empty( $data['presence-ping']['post_id'] ) ) {
			$front_post_id = absint( $data['presence-ping']['post_id'] );
			if ( $front_post_id ) {
				$state['post_id']   = $front_post_id;
				$state['post_type'] = isset( $data['presence-ping']['post_type'] ) ? sanitize_key( $data['presence-ping']['post_type'] ) : 'post';
				$state['title']     = isset( $data['presence-ping']['title'] ) ? sanitize_text_field( $data['presence-ping']['title'] ) : '';
			}
		}

		wp_set_presence(
			self::ROOM,
			$client_id,
			$state,
			$user_id
		);

		// Return the updated presence list.
		$entries = wp_get_presence( self::ROOM );
		$online  = array();

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

		$response['presence-online'] = $online;

		return $response;
	}
}
