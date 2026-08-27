=== Presence API ===
Contributors: joefusco, intenzi, ashishjii, iamchitti, iqbal1hossain, wp24horas, aldorza, bejignesh
Tags: presence, awareness, heartbeat, real-time
Requires at least: 7.0
Tested up to: 7.0
Stable tag: 0.1.24
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: presence-api

System-wide presence and awareness for WordPress.

== Description ==

Presence API gives WordPress a system-wide awareness layer. It tracks which users are logged in, which admin screen they are on, and which posts they are editing.

Data flows through the Heartbeat API and is stored in a dedicated `wp_presence` table with a 150-second TTL. No writes to `wp_postmeta` means no post-cache invalidation on every heartbeat.

On a multisite network, Network Admin gets its own view of the same data: a Who's Online dashboard widget listing the busiest sites and who is on each, an Online column in the Sites list, and an Online view, filter, and column in the Users list. These require the `manage_network` capability.


= Features =

* Who's Online dashboard widget with idle detection
* Active Posts dashboard widget grouped by post
* Admin bar indicator showing other users on the same page
* Editors column in the post list
* Online filter in the Users list

= For Developers =

PHP functions, REST endpoints, WP-CLI commands, filters, and room conventions are documented in the [GitHub repository](https://github.com/WordPress/presence-api).

= Background =

An experimental feature plugin sponsored by the WordPress Core team, exploring what system-wide presence could look like for a future WordPress release. Follow development on [make.wordpress.org/core](https://make.wordpress.org/core/) with the tag `#presence-api`.

== Installation ==

1. In your WordPress admin, go to **Plugins → Add New Plugin** and search for "Presence API", then click **Install Now**.
2. Activate through the **Plugins** menu.

Or install manually:

1. Download the zip and upload the `presence-api` folder to `/wp-content/plugins/`.
2. Activate through the **Plugins** menu.

== Frequently Asked Questions ==

= Does it work on multisite? =

Yes. Network-activate it and Network Admin gains a Who's Online dashboard widget listing the busiest sites and who is on each, an Online column in the Sites list, and an Online view, filter, and column in the Users list. Every site keeps its own widgets and lists, counting only the people on that site.

= Who can see network-wide presence? =

Anyone with the `manage_network` capability, which on a default network means super admins. The `wp_presence_network_capability` filter changes what is required.

== Changelog ==

= 0.1.24 =
* Add idle backoff config for heartbeat presence ping.
* Back off heartbeat polling interval for idle rooms.
* Coalesce presence polling across tabs of the same user.

= 0.1.23 =
* Demo helper now saves post to bump revision ([b397845](https://github.com/WordPress/presence-api/commit/b397845145e71fcdbeac79e4e1b092f6adcdbd31)), closes [#287](https://github.com/WordPress/presence-api/issues/287).
* Cap heartbeat payload to visible rows only ([e7739ac](https://github.com/WordPress/presence-api/commit/e7739aced270df1ca462a491af56286a4b255f47)), closes [#291](https://github.com/WordPress/presence-api/issues/291).
* Paginate rooms before user hydration ([6929add](https://github.com/WordPress/presence-api/commit/6929addba615aa57c9e69ba09ef6611fd75028bb)), closes [#285](https://github.com/WordPress/presence-api/issues/285).

= 0.1.22 =
* Add usePresenceUsers React hook.
* Prevent unnecessary heartbeat re-subscription on param changes.
* Resolve ref-in-render and stale closure issues.

= 0.1.21 =
* Add RTC collaboration hooks and server authority.
* Eliminate race condition in timestamp test.
* Restore admin bar contrast in Light scheme.
* Set page parameter in REST controller tests.
* Set per_page parameter in REST controller tests.
* Skip Who's Online payload when room state is unchanged.

= 0.1.20 =
* Correct query cost and timezone bug in post revision lookup.
* Count people rather than rows in the Active Posts widget ([da77dfb](https://github.com/WordPress/presence-api/commit/da77dfb262e1b74d23defea4cb07905387abb5cf)), closes [#134](https://github.com/WordPress/presence-api/issues/134).
* Guard the presence table upgrade with a provisioning lock.
* Merge the post lock entry into the editor's presence entry ([b0d1a83](https://github.com/WordPress/presence-api/commit/b0d1a83165f64efdcf8b396f99ad0bb0cfb60420)), closes [#134](https://github.com/WordPress/presence-api/issues/134).
* Reindent get_metadata() case with tabs.
* Stop funneling screen-revision bumps through one shared option.
* Aggregate wp_get_presence_summary() in SQL.

= 0.1.19 =
* Keyboard users can't reach non-link items in the admin bar presence flyout.
* Let core's focus color show through admin bar group headers.
* Make non-link admin bar flyout items reachable by keyboard.
* Preserve focus across heartbeat re-renders in Active Posts widget.

= 0.1.18 =
* Check table availability in the CLI command and debug viewer.
* Delete expired presence rows by key in bounded passes.
* Guard the CLI cleanup command with a table availability check.
* Guard the debug DB viewer query and drop the unused row count.

= 0.1.17 =
* Bound presence keys to the column width and validate REST args.
* Provision the presence table per site instead of on admin_init only.
* Rebuild the presence table when the version option outlives it.

= 0.1.16 =
* Store presence data as longtext and compare schema version as an integer.

= 0.1.15 =
* Replace 404ing Playground badge with the one used in PR previews.
* Stop props bot echoing raw commit author emails in unlinked accounts.

= 0.1.14 =
* Add display_name and avatar_url to presence response.
* Add missing alt text to Who's Online widget avatar.
* Correct heartbeat function name in render test.
* Filter presence read paths by per-post capability.
* Restore wp_set_presence, fix assertion quote handling.
* Use heartbeat path in render test for reliable coverage.
* Use wp_presence_admin_room() after ROOM constant removed.
* Write presence via admin handler in render test.

= 0.1.13 =
* Move inline heartbeat JS to a standalone enqueued script.
* Replace GROUP_CONCAT session mutations with PHP aggregation.

= 0.1.12 =
* Add action links to the plugin list table.
* Add blueprints for plugin page preview button.

= 0.1.11 =
* Enforce per-room authorization checks for presence rooms.

= 0.1.10 =
* Credit every contributor in the release props comment.
* Move the admin/online write out of the Who's Online widget ([b7b500b](https://github.com/WordPress/presence-api/commit/b7b500bd2ca5eac2d2cc98485ea3ac4452c0a324)), closes [#141](https://github.com/WordPress/presence-api/issues/141).
* Render release props in a code block like props-bot.

= 0.1.9 =
* Aggregate props from merged PRs onto release PR.
* Default presence widgets to top of dashboard on fresh install.
* Remove top-level permissions block that broke release-please startup.
* Use inline script to load aggregate-props from workspace.

= 0.1.8 =
* Add AI Tools disclosure to automated contributor PR body.
* Add concurrency group, use default_branch instead of hardcoded main.
* Robot PR body for first contributions, suppress props-bot on contributor PRs.
* Suppress props-bot on release-please PRs.
* Use user.type for bot detection, wrap fetch in full try/catch.
* Remove AI disclosure from automated PR body.

= 0.1.7 =
* Add validate_callback validation check to REST screen_key.
* Use correct REST route in PHPUnit tests.

= 0.1.6 =
* Dispatch deploy workflow instead of calling as reusable to avoid startup failure.
* Flatten deploy workflow to remove reusable nesting causing startup failure.
* Use 10up action ASSETS_DIR instead of separate assets workflow.
* Use correct heading format in Unlinked Accounts regex.

= 0.1.5 =
* Check entry ownership before enforcing per-user presence limit ([5698d94](https://github.com/WordPress/presence-api/commit/5698d9425baa9a67561626c4ca8421a5daf64728)), closes [#88](https://github.com/WordPress/presence-api/issues/88).
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
