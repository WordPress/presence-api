=== Presence API ===
Contributors: joefusco, intenzi, ashishjii, iamchitti, iqbal1hossain, wp24horas, aldorza, bejignesh, stfulldev, obenland, moriikuri, ishitaj34, theaminuldev, muneebashraf, mindctrl, zahidui, mitgiselle
Tags: presence, awareness, heartbeat, real-time
Requires at least: 7.0
Tested up to: 7.1
Stable tag: 0.5.0
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

= 0.5.0 =
* Add a Settings link to the plugin row actions.
* Let wp_set_presence() accept an explicit GMT timestamp.
* Bypass the redundant-write guard and validate $date_gmt on wp_set_presence().
* Request a retina-sharp avatar resolution across the presence surfaces.
* Stop PHPStan's bootstrap from silently exiting before analysis.

= 0.4.0 =
* Carry the room's editor count on the editor heartbeat response.
* Gate the network column renderers on the network capability.
* Pin the patched bullseye apt sources to the image's own frozen snapshot.
* Public surface read constants.
* Skip presence writes that would only move the timestamp.

= 0.3.0 =
* Add a site and network switch for whether presence is recorded.
* Add policy content, exporter and eraser for presence data.
* Switch presence recording on and off from Settings and WP-CLI.
* Add data-post-id to server-rendered Active Posts rows.
* Collapse the duplicated online-ID assembly into one helper.
* Count everyone present, including yourself, on every surface.
* Count Who's Online overflow from the heartbeat total.
* Declare the current user next to where the bar renders it.
* Distinguish a non-aggregating network from a quiet one.
* Keep the network Who's Online widget's accessible names across a re-render.
* List yourself in the widget so its rows match the count above them.
* Preserve accessible names across heartbeat re-renders in the network Who's Online widget.
* Report a switched-off site rather than a failed write.
* Report network aggregation state from the REST and CLI network reads.
* Restore the named stack limit on the admin bar avatar cap.
* Run workflows on the release pull request's final commit.
* Say so on the network dashboard widget when the network does not aggregate.
* Say the network does not aggregate on the Users list too.
* Skip the Playground preview publish when the built SHA is superseded.
* Warn on the Network Sites list when the network does not aggregate presence.
* Store network summary rows compact.

= 0.2.1 =
* Filter out archived, spam, and deleted sites from network presence.
* Gate cross-tab relay on Web Locks availability.
* Prune network summary rows past the read cutoff.
* Stop tab coordinator rebroadcast loop when Web Locks is unavailable.

= 0.2.0 =
* Add a network-wide presence summary table.
* Add a Who's Online widget to the Network Admin dashboard.
* Add a wp presence network CLI subcommand for the network-wide summary.
* Add an Online column to the Network Sites list.
* Add an Online view and column to the Network Users list.
* Add network-scoped REST routes for reading presence across a network.
* Add Playground blueprint for multisite network demo.
* Announce admin room changes with an action.
* Expose network presence via REST and WP-CLI.
* Let the network summary skip sites so callers can paginate.
* Push each site's online set into the network summary.
* Read the network summary as a capped snapshot.
* Boot the multisite Playground preview through wp-cli steps.
* Bring the stale-screen banner back after a dismissal.
* Carry each site's own scheme in the network summary row.
* Clear a user's presence when their account or site membership ends.
* Detect the collaboration edge across requests.
* Exclude Codecov config and Jest test from release zip.
* Outlast core's unfocused heartbeat interval in the presence TTL.
* Preserve focus across heartbeat re-renders in the network Who's Online widget.
* Refresh the network summary timestamp unconditionally.
* Register the network summary table name idempotently.
* Register the presence table name idempotently.
* Require manage_options to reach the debugger widget.
* Serve Playground preview assets over an origin that allows CORS.
* Skip an avatar-less user in the stack instead of drawing an empty img.
* Skip notifications for no-op presence changes.
* Store collaboration state only while two editors are present.
* Style the avatar stack on the Network Admin Sites list.
* Gate network presence aggregation on wp_is_large_network().
* Gate network summary reads on wp_is_large_network().
* Gate the network summary push on wp_is_large_network().
