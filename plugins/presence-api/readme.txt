=== Presence API ===
Contributors: joefusco, intenzi, ashishjii, iamchitti, iqbal1hossain, wp24horas, aldorza, bejignesh, stfulldev, obenland, moriikuri, ishitaj34, theaminuldev, muneebashraf, mindctrl, zahidui, mitgiselle, jaredrethman, jooahmed
Tags: presence, awareness, heartbeat, real-time
Requires at least: 7.0
Tested up to: 7.1
Stable tag: 0.13.0
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
* Online filter in the Users list
* AI agents labelled in Who's Online, the admin bar, and the Editors column, once a plugin such as Agent Users marks them

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

Yes. Clear the **Presence** checkbox on Settings > General, or run `wp presence recording set off`. Every screen empties within one TTL as the rows already stored expire. On multisite, Network Admin > Settings has the same checkbox for every site at once; whichever switch is off decides.

For code, the `wp_presence_recording_enabled` and `wp_presence_network_recording_enabled` filters take the checkboxes as their defaults, so a filter always has the last word.

== Changelog ==

Only the most recent releases are listed here. For the full history, see https://github.com/WordPress/presence-api/blob/main/CHANGELOG.md

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
* Translate Heartbeat debugger plurals and units ([#660](https://github.com/WordPress/presence-api/issues/660)).

= 0.12.0 =
* Add an admin bar debugger and remove the Dashboard widget ([#652](https://github.com/WordPress/presence-api/issues/652)).
* Label agent presence rows across the admin UI ([#653](https://github.com/WordPress/presence-api/issues/653)).
* Let plugins add rows and indicators to the debugger ([#666](https://github.com/WordPress/presence-api/issues/666)).
* Give the debugger and DB viewer's muted text AA contrast ([#648](https://github.com/WordPress/presence-api/issues/648)).

= 0.11.0 =
* Show presence on every post type edited in the admin ([#631](https://github.com/WordPress/presence-api/issues/631)).
* Give presence to roles that edit only pages or a custom post type ([#637](https://github.com/WordPress/presence-api/issues/637)).
* Join the post room for what the Site Editor has open ([#638](https://github.com/WordPress/presence-api/issues/638)).
* Keep post locks in the presence table with recording off ([#644](https://github.com/WordPress/presence-api/issues/644)).
* Name post types, untitled posts and sites in the dashboard widgets ([#634](https://github.com/WordPress/presence-api/issues/634)).
* Stop labelling Active Posts as posts being edited ([#645](https://github.com/WordPress/presence-api/issues/645)).
* Translate reused core strings under the plugin's text domain ([#646](https://github.com/WordPress/presence-api/issues/646)).
