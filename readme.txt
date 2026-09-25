=== Presence API ===
Contributors: joefusco, intenzi, ashishjii, iamchitti, iqbal1hossain, wp24horas, aldorza, bejignesh, stfulldev, obenland, moriikuri, ishitaj34, theaminuldev, muneebashraf, mindctrl, zahidui, mitgiselle, jaredrethman
Tags: presence, awareness, heartbeat, real-time
Requires at least: 7.0
Tested up to: 7.1
Stable tag: 0.9.0
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
* Admin bar indicator showing who's online, grouped by who's on this page
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

= Can a site stop recording presence? =

Yes. Clear the **Presence** checkbox on Settings > General, or run `wp presence recording set off`. Every screen empties within one TTL as the rows already stored expire. On multisite, Network Admin > Settings has the same checkbox for every site at once; whichever switch is off decides.

For code, the `wp_presence_recording_enabled` and `wp_presence_network_recording_enabled` filters take the checkboxes as their defaults, so a filter always has the last word.

== Changelog ==

Only the most recent releases are listed here. For the full history, see https://github.com/WordPress/presence-api/blob/main/CHANGELOG.md

= 0.9.0 =
* Keep post locks in the presence table instead of post meta.
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

= 0.6.0 =
* Add Network Admin plugin action links ([#513](https://github.com/WordPress/presence-api/issues/513)).
* Add wp_presence_is_available() for integrators ([#519](https://github.com/WordPress/presence-api/issues/519)).
* Expose the room's collaborator count as JS hooks ([#503](https://github.com/WordPress/presence-api/issues/503)).
* Centralize network site status filtering ([#453](https://github.com/WordPress/presence-api/issues/453)).
* Keep a client that is still pinging out of the idle state ([#522](https://github.com/WordPress/presence-api/issues/522)).
* Keep the collaboration edge state in the presence table ([#514](https://github.com/WordPress/presence-api/issues/514)).

= 0.5.0 =
* Add a Settings link to the plugin row actions ([ba04159](https://github.com/WordPress/presence-api/commit/ba04159d8e780936ee8e25100d7b23e239fd8cab)), closes [#484](https://github.com/WordPress/presence-api/issues/484).
* Let wp_set_presence() accept an explicit GMT timestamp.
* Bypass the redundant-write guard and validate $date_gmt on wp_set_presence().
* Request a retina-sharp avatar resolution across the presence surfaces.
* Stop PHPStan's bootstrap from silently exiting before analysis.
