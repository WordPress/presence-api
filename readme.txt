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

Presence API gives WordPress a system-wide awareness layer. It tracks which users are logged in, which admin screen they are on, and which posts they are editing.

Data flows through the Heartbeat API and is stored in a dedicated `wp_presence` table with a 60-second TTL. No writes to `wp_postmeta` means no post-cache invalidation on every heartbeat.

= Features =

* Who's Online dashboard widget with idle detection
* Active Posts dashboard widget grouped by post
* Admin bar indicator showing other users on the same page
* Editors column in the post list
* Online filter in the Users list

= Try it =

[Test in WordPress Playground](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/WordPress/presence-api/main/blueprint.json) without installing anything.

[Watch the demo on YouTube](https://youtu.be/Xa5WkZdjBD4)

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
* Initial release.
