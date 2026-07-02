=== Presence API ===
Contributors: joefusco
Tags: presence, awareness, heartbeat, real-time
Requires at least: 7.0
Tested up to: 7.0
Stable tag: 0.1.2
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
* Initial release.
