=== Presence API ===
Contributors: joefusco
Tags: presence, awareness, heartbeat, real-time
Requires at least: 7.0
Tested up to: 7.0
Stable tag: 0.1.5
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: presence-api

System-wide presence and awareness for WordPress.

== Description ==

Presence API tells you who is logged in to your WordPress site, what admin screen they are on, and which posts they are editing — in real time.

Data flows through the existing Heartbeat API and is stored in a dedicated `wp_presence` table with a 60-second TTL. No writes to `wp_postmeta` means no post-cache invalidation on every heartbeat.

= Features =

* **Who's Online widget** — avatar stack of logged-in users with idle detection and an overflow threshold for large teams
* **Active Posts widget** — posts grouped by title showing which editors are currently inside them
* **Admin bar indicator** — avatar stack for other users on the same page, with a dropdown grouped by "On this page" and "Elsewhere"
* **Post list Editors column** — see who is in each post without opening it
* **Users list Online filter** — filter the Users screen to show only currently active users

= Try it =

[Test in WordPress Playground](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/WordPress/presence-api/main/blueprint.json) — no install required, seeds multiple users automatically.

[Watch the demo on YouTube](https://youtu.be/Xa5WkZdjBD4)

= For Developers =

For the full API reference — PHP functions, REST endpoints, WP-CLI commands, filters, and room conventions — see the [GitHub repository](https://github.com/WordPress/presence-api).

= Background =

This is an experimental feature plugin sponsored by the WordPress Core team, exploring what system-wide presence could look like for a future WordPress release. Follow development on [make.wordpress.org/core](https://make.wordpress.org/core/) with the tag `#presence-api`.

== Installation ==

1. In your WordPress admin, go to **Plugins → Add New Plugin** and search for "Presence API", then click **Install Now**.
2. Activate through the **Plugins** menu.

Or install manually:

1. Download the zip and upload the `presence-api` folder to `/wp-content/plugins/`.
2. Activate through the **Plugins** menu.

Or [try it in WordPress Playground](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/WordPress/presence-api/main/blueprint.json) without installing anything.

== Changelog ==

= 0.1.5 =
* Check entry ownership before enforcing per-user presence limit.
* Exclude expired entries from ownership check to keep cap exact.
* Pass VERSION env var to deploy action so SVN tag matches git tag.
* Preserve version headings in sync script and correct wp_options claim.

= 0.1.4 =
* Maintenance release.

= 0.1.3 =
* Add 40-user Playground blueprint.
* Address stale-screen review feedback.
* Address WordPress.org plugin review feedback.

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
