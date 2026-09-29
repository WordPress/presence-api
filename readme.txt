=== Presence API ===
Contributors: joefusco, intenzi, ashishjii, iamchitti, iqbal1hossain, wp24horas, aldorza, bejignesh, stfulldev, obenland, moriikuri, ishitaj34, theaminuldev, muneebashraf, mindctrl, zahidui, mitgiselle, jaredrethman, jooahmed
Tags: presence, awareness, heartbeat, real-time
Requires at least: 7.0
Tested up to: 7.1
Stable tag: 0.11.0
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

= 0.11.0 =
* Show presence on every post type edited in the admin ([#631](https://github.com/WordPress/presence-api/issues/631)).
* Give presence to roles that edit only pages or a custom post type ([#637](https://github.com/WordPress/presence-api/issues/637)).
* Join the post room for what the Site Editor has open ([#638](https://github.com/WordPress/presence-api/issues/638)).
* Keep post locks in the presence table with recording off ([#644](https://github.com/WordPress/presence-api/issues/644)).
* Name post types, untitled posts and sites in the dashboard widgets ([#634](https://github.com/WordPress/presence-api/issues/634)).
* Stop labelling Active Posts as posts being edited ([#645](https://github.com/WordPress/presence-api/issues/645)).
* Translate reused core strings under the plugin's text domain ([#646](https://github.com/WordPress/presence-api/issues/646)).

= 0.10.0 =
* Fold shared screens into one row in the admin bar menu ([#615](https://github.com/WordPress/presence-api/issues/615)).
* List people editing posts first in the admin bar menu ([#603](https://github.com/WordPress/presence-api/issues/603)).
* Refresh the Editors column on each heartbeat ([#606](https://github.com/WordPress/presence-api/issues/606)).
* Refresh the Online users list on each heartbeat ([#604](https://github.com/WordPress/presence-api/issues/604)).
* Show the stale-screen banner on Settings API, Privacy and Network Admin screens ([#619](https://github.com/WordPress/presence-api/issues/619)).
* Bump the right Users screen on a row Remove and skip bumps while deleting a site ([#628](https://github.com/WordPress/presence-api/issues/628)).
* Link admin bar rows to the comment, user or term being edited ([#617](https://github.com/WordPress/presence-api/issues/617)).
* Link Network Admin rows and count the network in the admin bar ([#618](https://github.com/WordPress/presence-api/issues/618)).
* Read the network Online view from the heartbeat's screen ([#622](https://github.com/WordPress/presence-api/issues/622)).
* Refresh presence rows on SQLite with CASE instead of IF() ([#623](https://github.com/WordPress/presence-api/issues/623)).
* Cut e2e runtime with backdated fixtures and readiness waits ([#621](https://github.com/WordPress/presence-api/issues/621)).

= 0.9.0 =
* Gate where people are behind a per-user view_presence_location meta cap ([#571](https://github.com/WordPress/presence-api/issues/571)).
* Give each user a room-assigned color from Gutenberg's palette ([#574](https://github.com/WordPress/presence-api/issues/574)).
* Keep the admin bar presence node in sync on each heartbeat ([#589](https://github.com/WordPress/presence-api/issues/589)).
* Put people on this page first in the admin bar menu and link everyone else to where they are ([#597](https://github.com/WordPress/presence-api/issues/597)).
* Retire the site Who's Online dashboard widget ([#591](https://github.com/WordPress/presence-api/issues/591)).
* Ring each admin bar face in its block editor collaborator color.
* Say how many people the admin bar menu leaves out ([#594](https://github.com/WordPress/presence-api/issues/594)).
* Seat the admin bar faces beside My Account and build the menu from core groups ([#565](https://github.com/WordPress/presence-api/issues/565)).
* Show whole faces in the admin bar and beside each name in its menu.
* Carry the filter nonce on the Plugins screen's online users link ([#569](https://github.com/WordPress/presence-api/issues/569)).
* Close the remaining location leaks and refresh the screen token ([#600](https://github.com/WordPress/presence-api/issues/600)).
* Keep post locks in the presence table instead of post meta ([#551](https://github.com/WordPress/presence-api/issues/551)).
* Keep presence markup and location to the people allowed them ([#596](https://github.com/WordPress/presence-api/issues/596)).
* List everyone online in the admin bar and keep only their location behind the cap ([#590](https://github.com/WordPress/presence-api/issues/590)).
* Skip an unchanged editor tick's presence write ([#553](https://github.com/WordPress/presence-api/issues/553)).
* Store the recording option so reading it costs no query ([#552](https://github.com/WordPress/presence-api/issues/552)).

= 0.8.0 =
* Add wp_presence_exchange() and wp_presence_leave() ([#546](https://github.com/WordPress/presence-api/issues/546)).
* Say when Heartbeat cannot keep presence current ([#544](https://github.com/WordPress/presence-api/issues/544)).

= 0.7.0 =
* Filter wp_get_presence() by client_id prefix in SQL ([#530](https://github.com/WordPress/presence-api/issues/530)).
* Store an expiry per presence row ([#541](https://github.com/WordPress/presence-api/issues/541)).
* Stop the site TTL filter overriding an explicit $timeout ([#540](https://github.com/WordPress/presence-api/issues/540)).
* Typos CI failure caused by changelog link label in `readme.txt` ([#527](https://github.com/WordPress/presence-api/issues/527)).
* Decide redundant presence writes inside the upsert ([#542](https://github.com/WordPress/presence-api/issues/542)).
