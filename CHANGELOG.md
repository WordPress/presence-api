# Changelog

## 0.4.0

### Added
- REST pagination: `per_page`/`page` params on `GET /presence` and `GET /presence/rooms` with `X-WP-Total` and `X-WP-TotalPages` response headers.
- `Cache-Control: no-store` header on all REST GET responses.
- Composite index `KEY room_date (room(40), date_gmt)` for efficient room prefix queries.
- `GROUP_CONCAT` session limit to prevent silent truncation in `wp_get_active_rooms()` and `wp_get_presence_summary()`.
- Who's Online summary mode: when overflow exceeds `OVERFLOW_THRESHOLD` (20), shows avatar stack with "+N more — view all users" link instead of expandable list.
- Active Posts grouped by post with avatar stacks and editor counts. Single-editor posts show the editor's name.
- Admin bar "On this page" section capped at 10 users with "+N more" overflow count.
- Explicit `edit_posts` capability gates on users list Online filter and post list Editors column.
- `wp presence demo` WP-CLI command: seeds N users with realistic names, `--keep-alive` for persistent demos, `--cleanup` for teardown.
- Self-playing Playwright demo covering every visual state: empty, single user, overflow, post collaboration, idle detection, scale burst, wind-down.

### Changed
- Cron cleanup uses batched `DELETE ... LIMIT 1000` loop to prevent table locks on large datasets.
- Admin bar "Elsewhere" section capped at 10 users with "+N more" overflow link.
- Who's Online widget JS idle threshold reads from PHP `IDLE_THRESHOLD` constant instead of hardcoded value.
- `wp_get_presence_summary()` computes total distinct users from grouped results in a single query instead of two.
- Extracted `render_user_row()` helper to deduplicate visible/overflow row rendering.
- Demo uses globally diverse name pools (50x50 first/last with coprime offset pairing for 2,500 unique combinations).
- Default WordPress Gravatars instead of external avatar service.

## 0.3.0

### Added
- Public API: `wp_get_presence`, `wp_set_presence`, `wp_remove_presence`, `wp_remove_user_presence`, `wp_can_access_presence_room`, `wp_presence_post_room`.
- REST endpoints: `GET /rooms` for listing all active rooms.
- Post type presence support via `add_post_type_support()`.
- Editor heartbeat tracking for per-post presence rooms.
- Admin bar presence indicator (avatar stack + online count).
- Dashboard widgets: Who's Online, Active Posts.
- User list "Online" filter view.
- Post list "Editors" column with avatar stack.
- `wp_presence_default_ttl` filter for tunable timeout.
- `wp_presence_user_can_access_room` filter for room access control.
- Full i18n with `.pot` file and `load_plugin_textdomain()`.
- WCAG AA accessibility: ARIA labels, `aria-live`, keyboard navigation.
- `uninstall.php` for clean plugin removal (multisite-aware).
- WP-CLI commands: `set`, `list`, `summary`.

### Changed
- Require WordPress 7.0+.
- Who's Online excludes current user, sorts newest-first.
- Heartbeat handlers gated behind `edit_posts` capability.
- Widget CSS uses `var(--wp-admin-theme-color)` for accent color.
- Replace MySQL-specific `SUBSTRING_INDEX` with PHP-side grouping.

### Removed
- Site Overview widget.

## 0.2.0

- Initial public release with core API, REST endpoints, and dashboard widgets.
