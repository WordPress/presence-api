=== Presence API ===
Contributors: joefusco
Tags: presence, awareness, heartbeat, real-time
Requires at least: 7.0
Tested up to: 7.0
Stable tag: 0.1.4
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: presence-api

System-wide presence and awareness for WordPress.

== Description ==

Tracks which users are logged in, what admin screen they are viewing, and which posts are being edited. Uses a dedicated database table with a 60-second TTL. Data flows through the existing Heartbeat API.

For full details, see the [GitHub repository](https://github.com/WordPress/presence-api).

== Installation ==

1. Upload the plugin to the `/wp-content/plugins/` directory.
2. Activate through the "Plugins" menu in WordPress.

== Changelog ==

= 0.1.4 =
* Auto-sync readme.txt changelog from CHANGELOG.md in sync-versions.sh.

= 0.1.3 =
* Add 40-user Playground blueprint.
* Add 40-user Playground blueprint (down from 100).
* Address stale-screen review feedback.
* Address WordPress.org plugin review feedback.
* Close wp_presence_current_screen_key() brace dropped by autofix.

= 0.1.2 =
* Add WordPress Playground blueprint for one-click testing.
* Remove demo CLI command from production builds.
* Split CI into separate PHPCS, PHPUnit, and Multisite workflows.
* Exclude vendor directory from release zip.
* Add readme.txt for WordPress.org directory submission.
* Add WordPress.org repository compliance files (CONTRIBUTING, CODEOWNERS, CODE_OF_CONDUCT).
* Move community health files to .github/.
* Replace deprecated get_page_by_title() with WP_Query.
* Add ABSPATH guards to db-viewer.php and demo-seeder.php.
* Exclude .claude directory from release zip.

= 0.1.1 =
* Fix Plugin Check errors for directory submission.

= 0.1.0 =
* Dedicated `wp_presence` table with `UNIQUE KEY (room, client_id)` for atomic upserts via `INSERT ... ON DUPLICATE KEY UPDATE`.
* 60-second TTL with batched cron cleanup.
* Public API: `wp_get_presence`, `wp_set_presence`, `wp_remove_presence`, `wp_remove_user_presence`, `wp_can_access_presence_room`, `wp_presence_post_room`.
* REST endpoints: `GET/POST/DELETE /wp-presence/v1/presence`, `GET /wp-presence/v1/presence/rooms` with SQL pagination and `Cache-Control: no-store`.
* Heartbeat integration for admin and editor presence pings.
* Post-lock bridge: translates `wp-refresh-post-lock` into presence entries.
* Login/logout lifecycle hooks gated on `edit_posts`.
* Dashboard widgets: Who's Online (with idle detection, overflow threshold, avatar stacks) and Active Posts (grouped by post with editor counts).
* Admin bar indicator: avatar stack for same-page users, dropdown grouped by "On this page" / "Elsewhere", alphabetically sorted.
* Post list "Editors" column with avatar stacks.
* Users list "Online" filter tab.
* WP-CLI: `set`, `list`, `summary`, `cleanup`.
* Debugger widget (WP_DEBUG only): heartbeat monitor with live table viewer.
* `wp_presence_default_ttl` filter and `WP_PRESENCE_DEFAULT_TTL` constant.
* Multisite-aware `uninstall.php`.
* Full i18n with `.pot` file.
* WCAG AA accessibility: ARIA labels, `aria-live`, keyboard navigation.
* 59 PHPUnit tests, 118 assertions.
* Playwright e2e tests with screenshot artifacts.
