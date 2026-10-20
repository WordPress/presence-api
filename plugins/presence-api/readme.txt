=== Presence API ===
Contributors: joefusco, intenzi, ashishjii, iamchitti, iqbal1hossain, wp24horas, aldorza, bejignesh, stfulldev, obenland, moriikuri, ishitaj34, theaminuldev, muneebashraf, mindctrl, zahidui, mitgiselle, jaredrethman, jooahmed, noruzzaman, huzaifaalmesbah, rejaulalomkhan, bosskhj
Tags: presence, awareness, heartbeat, real-time
Requires at least: 7.0
Tested up to: 7.1
Stable tag: 0.18.3
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
* A notice when someone else saves the screen you have open
* Post locks kept in the presence table instead of post meta, so holding a lock no longer makes cached post queries stale
* A switch for each feature on Settings > Presence API
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

= 0.18.3 =
* Guard the functions in the six files core would ship in separate tickets ([#861](https://github.com/WordPress/presence-api/issues/861)).
* Leave a presence table from an older schema alone until it is upgraded ([#866](https://github.com/WordPress/presence-api/issues/866)).
* Store no session hash on the admin room's one row per person ([#867](https://github.com/WordPress/presence-api/issues/867)).
* Warn an open screen when a REST request saves what it shows ([#868](https://github.com/WordPress/presence-api/issues/868)).

= 0.18.2 =
* Make the presence screens readable with only a keyboard ([#841](https://github.com/WordPress/presence-api/issues/841)).

= 0.18.1 =
* Show the admin bar presence node on phones and spell out hover-only names on touch ([#830](https://github.com/WordPress/presence-api/issues/830)).

= 0.18.0 =
* Let a reader tell a room changed without reading every row ([#791](https://github.com/WordPress/presence-api/issues/791)).
* Let core's copy of a presence function or class win when it already exists ([#805](https://github.com/WordPress/presence-api/issues/805)).
* Raise a deprecation notice for positional presence arguments ([#800](https://github.com/WordPress/presence-api/issues/800)).
* Register each switchable piece with the callback that adds its hooks ([#814](https://github.com/WordPress/presence-api/issues/814)).
* Return WP_Error with specific refusal codes when presence write fails ([#792](https://github.com/WordPress/presence-api/issues/792)).
* Tell a second browser from a second tab with a per-room session hash ([#815](https://github.com/WordPress/presence-api/issues/815)).
* Cap the heartbeat ping's title and screen before storing them ([#816](https://github.com/WordPress/presence-api/issues/816)).

= 0.17.0 =
* Let a network switch off the network admin screens ([#788](https://github.com/WordPress/presence-api/issues/788)).
* Let a site switch off the Active Posts dashboard widget ([#774](https://github.com/WordPress/presence-api/issues/774)).
* Show who else is editing a synced pattern on its block ([#779](https://github.com/WordPress/presence-api/issues/779)).
* Take an $args array in the presence functions ([#789](https://github.com/WordPress/presence-api/issues/789)).
