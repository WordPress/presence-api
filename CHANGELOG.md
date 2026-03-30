# Changelog

## 0.1.0

Initial release.

- Dedicated `wp_presence` table with `UNIQUE KEY (room, client_id)` for atomic upserts via `INSERT ... ON DUPLICATE KEY UPDATE`.
- 60-second TTL with batched cron cleanup.
- Public API: `wp_get_presence`, `wp_set_presence`, `wp_remove_presence`, `wp_remove_user_presence`, `wp_can_access_presence_room`, `wp_presence_post_room`.
- REST endpoints: `GET/POST/DELETE /wp-presence/v1/presence`, `GET /wp-presence/v1/presence/rooms` with SQL pagination and `Cache-Control: no-store`.
- Heartbeat integration for admin and editor presence pings.
- Post-lock bridge: translates `wp-refresh-post-lock` into presence entries.
- Login/logout lifecycle hooks gated on `edit_posts`.
- Dashboard widgets: Who's Online (with idle detection, overflow threshold, avatar stacks) and Active Posts (grouped by post with editor counts).
- Admin bar indicator: avatar stack for same-page users, dropdown grouped by "On this page" / "Elsewhere", alphabetically sorted.
- Post list "Editors" column with avatar stacks.
- Users list "Online" filter tab.
- WP-CLI: `set`, `list`, `summary`, `cleanup`, `demo` (with `--keep-alive` and `--cleanup`).
- Debugger widget (WP_DEBUG only): heartbeat monitor with live table viewer.
- `wp_presence_default_ttl` filter and `WP_PRESENCE_DEFAULT_TTL` constant.
- `wp_presence_user_can_access_room` filter for room access control.
- Multisite-aware `uninstall.php`.
- Full i18n with `.pot` file.
- WCAG AA accessibility: ARIA labels, `aria-live`, keyboard navigation.
- 60 PHPUnit tests, 119 assertions.
- Playwright e2e tests with screenshot artifacts.
