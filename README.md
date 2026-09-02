# Presence API

[![CI](https://github.com/WordPress/presence-api/actions/workflows/ci.yml/badge.svg)](https://github.com/WordPress/presence-api/actions/workflows/ci.yml)
[![Open in WordPress Playground](https://img.shields.io/badge/Open%20in-WordPress%20Playground-3858E9?logo=wordpress&logoColor=white)](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/WordPress/presence-api/main/blueprint.json)

> **Status:** Experimental feature plugin

System-wide presence and awareness for WordPress.

## Problem

WordPress has no way to know who is logged in, what screen they are on, or which posts are being edited — without writing to shared tables like `wp_postmeta` or `wp_options`. High-frequency writes to those tables invalidate caches site-wide ([#64696](https://core.trac.wordpress.org/ticket/64696)). This plugin uses a dedicated `wp_presence` table with a 150-second TTL to provide that awareness with zero cache side effects.

> "This idea of presence I think is really cool and seeing where people are... you log into your WordPress, I see oh Matias is moderating some comments, Lynn is on the dashboard maybe reading some news... that idea of like you log in and you can kind of see the neighborhood of like who else is also there." — [Matt Mullenweg, WordPress 7.0 planning session](https://youtu.be/F-xMPY9WqG4?si=YK0rIUM2nuYy7x45&t=2435)

## Run locally

```bash
npm install
npx wp-env start
```

Then open [localhost:8888/wp-admin/](http://localhost:8888/wp-admin/) (admin / password).

## Data flow

1. Browser sends `presence-ping` via Heartbeat
2. Server upserts into `wp_presence`
3. Server reads the room and returns entries in the heartbeat response
4. Client diffs a signature of user IDs and swaps HTML when content changes
5. Client-side interval re-evaluates idle state every 5s between heartbeat ticks

## Rooms

| Pattern                | Example            |
| ---------------------- | ------------------ |
| `admin/online`         | All admin pages    |
| `postType/{type}:{id}` | `postType/post:42` |

Post types opt in via `add_post_type_support( 'post', 'presence' )`.

### Client IDs

Rooms carry no producer namespace of their own — this plugin's own writers are told apart from anyone else's entries in the same room by a `client_id` prefix instead. `user-{user_id}` (admin/online room, from `includes/heartbeat.php` and `includes/lifecycle.php`) and `editor-{user_id}` (post rooms, from `includes/heartbeat.php` and `includes/post-lock-bridge.php`) are reserved this way; a row using either belongs to this plugin and has the state shape its writer expects.

Anything else sharing a room — another plugin relaying awareness from an external source, a REST client, a WP-CLI entry — must prefix its own `client_id` (e.g. `sync-{id}`) so it can't collide with those rows or be mistaken for one.

The `editor-` prefix is load-bearing rather than cosmetic: `includes/heartbeat.php` counts the editors in a post room with `str_starts_with( $entry->client_id, 'editor-' )`, so a colliding prefix inflates that count.

## PHP API

The following public functions are part of the stable public API contract. All other helper functions in `includes/functions.php` and `includes/network-functions.php` (such as `wp_get_active_rooms()`, `wp_get_presence_summary()`, etc.) are marked `@access private`, are intended for internal plugin use only, and may change or be removed without notice.

```php
// Read all presence entries in a room.
$entries = wp_get_presence( $room, $timeout = WP_PRESENCE_DEFAULT_TTL );

// Upsert a client's presence state. Atomic via INSERT … ON DUPLICATE KEY UPDATE.
wp_set_presence( $room, $client_id, $state, $user_id = 0 );

// Remove a single client from a room.
wp_remove_presence( $room, $client_id );

// Remove all presence entries for a user across all rooms.
wp_remove_user_presence( $user_id );

// Check whether a user can access a room (requires edit_posts).
wp_can_access_presence_room( $room, $user_id = 0 );

// Return the canonical room string for a post, or false if the post type
// does not support presence.
$room = wp_presence_post_room( $post );

// Whether this site records presence at all.
wp_presence_recording_enabled();
```

Each entry object returned by `wp_get_presence()` has:

| Field       | Type     | Notes                                                                                                                      |
| ----------- | -------- | --------------------------------------------------------------------------------------------------------------------------- |
| `room`      | `string` | The room the entry belongs to.                                                                                              |
| `client_id` | `string` | A `varchar` column — opaque, and not guaranteed numeric even when it looks like one. See [Client IDs](#client-ids).         |
| `user_id`   | `string` | `"0"` for an entry with no signed-in user. Every column comes back as a string, so cast before a strict comparison. |
| `data`      | `array`  | Decoded from the stored JSON; an empty array if that JSON failed to decode.                                                 |
| `date_gmt`  | `string` | A MySQL `datetime` string in UTC (e.g. `2024-01-01 12:00:00`), not a Unix timestamp. Convert with `strtotime( $entry->date_gmt . ' UTC' )`. |

### Network

Multisite only, from `includes/network-functions.php`. Returns `false` outside multisite.

```php
// Whether this network assembles its sites' rows into the network-wide view.
wp_presence_network_aggregation_enabled();
```

## Extension Points

### Post Type Support
A post type opts in to per-post presence rooms by declaring `presence` support. `post` and `page` are registered by the plugin; any other post type must opt in itself, either during registration or afterwards:
```php
// During registration:
register_post_type( 'my-post-type', array(
    'supports' => array( 'title', 'editor', 'presence' ),
) );

// Or afterwards, on a post type someone else registered:
add_post_type_support( 'my-post-type', 'presence' );
```

Without support, `wp_presence_post_room()` returns `false` for that post type and no per-post room is created.

### Filters
#### `wp_presence_default_ttl`
Filters the presence TTL (time-to-live) in seconds used for all queries and cleanup. Default: 150.

Values under 120 drop a tab that is still open and still pinging, since that is the Heartbeat interval core gives an unfocused or five-minute-idle tab.
```php
add_filter( 'wp_presence_default_ttl', function( $timeout ) {
    return 300; // Override TTL to 5 minutes.
} );
```

Or define the constant before the plugin loads:
```php
define( 'WP_PRESENCE_DEFAULT_TTL', 300 );
```

#### `wp_presence_current_screen_key`
Filters the key identifying the current admin screen for [stale-screen detection](#stale-screen-detection). Core screens (Settings, `post.php`, term, user, comment) resolve their own keys; `$key` is `''` on any screen without coverage. Return a non-empty string to opt a custom screen in.
```php
add_filter( 'wp_presence_current_screen_key', function( $key, $screen ) {
    if ( 'toplevel_page_my-plugin' === $screen->id ) {
        return 'options/my-plugin-settings';
    }
    return $key; // Leave other screens untouched.
}, 10, 2 );
```

Keys follow the plugin's slash-separated room convention and are truncated to 191 characters (`WP_PRESENCE_SCREEN_KEY_LIMIT`). Use the same key when bumping the revision from JS via `wp.presence.markScreenStale()`.

#### `wp_presence_recording_enabled`
Filters whether presence is recorded on this site. Default: the **Presence** checkbox on Settings > General, which is on for a new install. Return `false` and nothing further is written; every surface empties within one TTL as the rows already stored expire, so there is nothing else to clear.
```php
add_filter( 'wp_presence_recording_enabled', '__return_false' );
```

Because the checkbox is only the filter's default, a filter always has the last word over whatever an administrator has chosen.

On multisite, `wp_presence_network_recording_enabled` does the same for every site at once, defaulting to the **Presence** checkbox on Network Admin > Settings. It is consulted only once the site-level filter has allowed recording, so either switch turning off wins and neither can turn the other back on.

#### `wp_presence_network_aggregation_enabled`
Filters whether a network assembles its sites' rows into the network-wide view behind Network Admin. Default: `true` below [`wp_is_large_network()`](https://developer.wordpress.org/reference/functions/wp_is_large_network/), which answers write concentration rather than policy. Independent of recording: a network can go on recording site by site and still switch the aggregate off.
```php
add_filter( 'wp_presence_network_aggregation_enabled', '__return_false' );
```

### Actions
#### `wp_presence_screen_revision_bumped`
Fires after an admin screen revision has been bumped. Useful for triggering custom sync or WebSocket integrations.
```php
add_action( 'wp_presence_screen_revision_bumped', function( $screen_key, $revision, $actor_id ) {
    // Custom sync logic
}, 10, 3 );
```

#### `wp_presence_collaboration_started`
Fires when collaboration starts in a room (transition from 1 to 2+ editors). Only entries whose `client_id` begins with `editor-` count toward the transition, while `$entries` is every entry in the room. The previous count is held in a transient that expires on the presence TTL, so once those entries have aged out the next pair reads as a fresh start.
```php
add_action( 'wp_presence_collaboration_started', function( $room, $entries ) {
    // Announce room active or update integration state
}, 10, 2 );
```

#### `wp_presence_collaboration_ended`
Fires when collaboration ends in a room (transition from 2+ to exactly 1 editor). The check runs on an editor heartbeat tick, so if every editor leaves at once there is nobody left to tick and the hook does not fire; the transient expires on the presence TTL and the room resets quietly.
```php
add_action( 'wp_presence_collaboration_ended', function( $room, $entries ) {
    // Announce room inactive or update integration state
}, 10, 2 );
```

## REST API

All endpoints require `edit_posts`. Responses include `Cache-Control: no-store`.

| Method | Path | Description |
|---|---|---|
| `GET` | `/wp-presence/v1/presence` | List entries in a room |
| `POST` | `/wp-presence/v1/presence` | Upsert a presence entry |
| `DELETE` | `/wp-presence/v1/presence` | Remove a presence entry |
| `GET` | `/wp-presence/v1/presence/rooms` | List active rooms |

### Network

Multisite only, and gated on `manage_network` rather than `edit_posts`.

| Method | Path | Description |
|---|---|---|
| `GET` | `/wp-presence/v1/presence/network` | List sites with users online, busiest first |
| `GET` | `/wp-presence/v1/presence/network/<blog_id>` | One site's users online |

The collection accepts `page` and `per_page` (default 50, max 100), counting sites in `X-WP-Total` and `X-WP-TotalPages` and the network headcount in `X-WP-Presence-Users-Online`. Both routes accept `users_per_site` to cap the users named per site (default 0, every user); each site's `user_count` stays its real total. A site nobody is on answers with an empty user list, so only an unknown `blog_id` is a 404.

## WP-CLI

```
wp presence list      # List all active presence entries
wp presence summary   # Summary grouped by room
wp presence set       # Manually upsert an entry
wp presence cleanup   # Delete expired entries immediately
wp presence network   # Network-wide summary (multisite only)
wp presence recording # Read or set the recording switch
```

```
wp presence recording get
wp presence recording set off
wp presence recording set off --network   # Multisite only
```

## Post-lock bridge

Creates presence entries alongside `_edit_lock` postmeta when a post lock is refreshed via Heartbeat. Both systems coexist.

## Capability

All features require `edit_posts`.

## Stale-screen detection

Warns users when an admin screen they are viewing has been modified by someone else.

Classic admin screens that save via `POST` and redirect (like Settings or `post.php`) are covered automatically.

Custom JS-driven screens (like Gutenberg settings panels or custom plugin screens) can opt-in by bumping the screen revision after a successful background save:

```js
// After a successful REST or AJAX save:
if (window.wp?.presence?.markScreenStale) {
    wp.presence.markScreenStale('options/my-custom-plugin-settings');
}
```

For a screen to be *watched* in the first place, it needs a screen key — core screens resolve their own, and custom screens supply one via the [`wp_presence_current_screen_key`](#wp_presence_current_screen_key) filter.

## Maintainers

- [@josephfusco](https://github.com/josephfusco)

Sponsored by the [Core team](https://make.wordpress.org/core/). Updates posted on [make.wordpress.org/core](https://make.wordpress.org/core/) with the tag `#presence-api`.

## Support

Questions and bug reports: [GitHub Issues](https://github.com/WordPress/presence-api/issues).

Discussion: [#feature-presence-api](https://wordpress.slack.com/archives/feature-presence-api) on WordPress Slack
