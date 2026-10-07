=== Presence API ===
Contributors: joefusco, intenzi, ashishjii, iamchitti, iqbal1hossain, wp24horas, aldorza, bejignesh, stfulldev, obenland, moriikuri, ishitaj34, theaminuldev, muneebashraf, mindctrl, zahidui, mitgiselle, jaredrethman, jooahmed
Tags: presence, awareness, heartbeat, real-time
Requires at least: 7.0
Tested up to: 7.1
Stable tag: 0.15.0
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

* Admin bar indicator showing who's online and who's on this page
* Active Posts dashboard widget grouped by post
* Editors column in the post list
* A notice on synced patterns in the block editor while someone else is editing them
* Online filter in the Users list
* AI agents labelled in the admin bar, the Active Posts widget, and the Editors column, once a plugin such as Agent Users marks them

= For Developers =

PHP functions, REST endpoints, WP-CLI commands, filters, and room conventions are documented in the [GitHub repository](https://github.com/WordPress/presence-api).

= Background =

A feature plugin sponsored by the WordPress Core team, exploring what system-wide presence could look like for a future WordPress release. Follow development on [make.wordpress.org/core](https://make.wordpress.org/core/) with the tag `#presence-api`.

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

= Can a site stop recording presence? =

Yes. Clear the **Presence** checkbox on Settings > General, or run `wp presence recording set off`. Every screen empties as the rows already stored expire. On multisite, Network Admin > Settings has the same checkbox for every site at once; whichever switch is off decides.

For code, the `wp_presence_recording_enabled` and `wp_presence_network_recording_enabled` filters take the checkboxes as their defaults, so a filter always has the last word.

== Screenshots ==

1. The dashboard with 101 people online, the admin bar's presence menu open, and Active Posts listing who is editing each post and page.
2. The Posts list, with an Editors column showing who has each post open.
3. The Users list filtered to the people online right now.
4. The notice someone sees when another person saves the settings screen they have open.
5. Settings > Presence API, where a site switches features off.
6. Network Admin's Who's Online widget, listing the busiest sites and how many people are on each.

== Changelog ==

Only the most recent releases are listed here. For the full history, see https://github.com/WordPress/presence-api/blob/main/CHANGELOG.md

= 0.15.0 =
* Fire an action when a presence row is set or removed ([#756](https://github.com/WordPress/presence-api/issues/756)).
* Let a site switch off the pieces it does not want, starting with post locks ([#724](https://github.com/WordPress/presence-api/issues/724)).
* Move the feature switches to the plugin's own page under Settings ([#755](https://github.com/WordPress/presence-api/issues/755)).
* Write reserved rows with recording off, so post locks use wp_set_presence() ([#752](https://github.com/WordPress/presence-api/issues/752)).

= 0.14.0 =
* REST presence entries no longer include `color`, and wp_presence_get_user_color() returns the block editor's color for the user ID instead of a stored one.
* Stop saving and serving presence colors ([#707](https://github.com/WordPress/presence-api/issues/707)).

= 0.13.0 =
* Write an agent's presence row when it saves a post ([#697](https://github.com/WordPress/presence-api/issues/697)).
* Skip trashing in the agent save hook and test only the guards it needs ([#700](https://github.com/WordPress/presence-api/issues/700)).
* Style the agent badge like the block editor's Badge ([#701](https://github.com/WordPress/presence-api/issues/701)).

= 0.12.2 =
* Elect the Heartbeat ping leader per site among visible tabs ([#675](https://github.com/WordPress/presence-api/issues/675)).
* Avoid repeated network summary table checks ([#657](https://github.com/WordPress/presence-api/issues/657)).
* Read each presence query once per request until the table changes ([#678](https://github.com/WordPress/presence-api/issues/678)).
* Trust the presence table's version option and recheck it only after a failed write ([#682](https://github.com/WordPress/presence-api/issues/682)).

= 0.12.1 =
* Maintenance release.
