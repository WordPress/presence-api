# Changelog

## 0.1.2

- Add WordPress Playground blueprint for one-click testing.
- Remove demo CLI command from production builds.
- Split CI into separate PHPCS, PHPUnit, and Multisite workflows.
- Exclude vendor directory from release zip.
- Add readme.txt for WordPress.org directory submission.
- Add WordPress.org repository compliance files (CONTRIBUTING, CODEOWNERS, CODE_OF_CONDUCT).
- Move community health files to .github/.
- Replace deprecated get_page_by_title() with WP_Query.
- Add ABSPATH guards to db-viewer.php and demo-seeder.php.
- Exclude .claude directory from release zip.

## 0.1.1

- Fix Plugin Check errors for directory submission.

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
- WP-CLI: `set`, `list`, `summary`, `cleanup`.
- Debugger widget (WP_DEBUG only): heartbeat monitor with live table viewer.
- `wp_presence_default_ttl` filter and `WP_PRESENCE_DEFAULT_TTL` constant.
- Multisite-aware `uninstall.php`.
- Full i18n with `.pot` file.
- WCAG AA accessibility: ARIA labels, `aria-live`, keyboard navigation.
- 59 PHPUnit tests, 118 assertions.
- Playwright e2e tests with screenshot artifacts.
