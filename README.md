# Presence API

[![CI](https://github.com/WordPress/presence-api/actions/workflows/ci.yml/badge.svg)](https://github.com/WordPress/presence-api/actions/workflows/ci.yml)

A feature plugin for system-wide presence and awareness in WordPress.

> [!IMPORTANT]
> **Built for the lowest common denominator of environments.** No object cache, no WebSockets, no extra services: a dedicated table with a TTL is the only moving part. Anything a managed host offers on top is a bonus, never a dependency.

## Problem

Tracking who is logged in, on which screen and in which post takes frequent writes, and in `wp_postmeta` or `wp_options` those invalidate caches site-wide ([#64696](https://core.trac.wordpress.org/ticket/64696)). This plugin writes them to its own `wp_presence` table instead, with a 150-second TTL.

> "This idea of presence I think is really cool and seeing where people are... you log into your WordPress, I see oh Matias is moderating some comments, Lynn is on the dashboard maybe reading some news... that idea of like you log in and you can kind of see the neighborhood of like who else is also there."
>
> [Matt Mullenweg, WordPress 7.0 planning session](https://youtu.be/F-xMPY9WqG4?si=YK0rIUM2nuYy7x45&t=2435)

![The dashboard with 101 people online, the admin bar's presence menu open, and Active Posts listing who is editing each post and page](plugins/presence-api/.wordpress-org/screenshot-1.png)

## Features

- Admin bar indicator showing who's online and who's on this page (switch off **Admin bar** on Settings > Presence API to remove it)
- Active Posts dashboard widget grouped by post (switch off **Dashboard widget** on Settings > Presence API to remove it)
- Editors column in the post list (switch off **Posts list** on Settings > Presence API to remove it)
- A notice on synced patterns in the block editor while someone else is editing the pattern itself (switch off **Synced patterns** on Settings > Presence API to remove it)
- Online filter in the Users list (switch off **Users list** on Settings > Presence API to remove it)
- AI agents labelled in the admin bar, the Active Posts widget, and the Editors column (see [Agents](#agents))
- Notice when someone else saves the screen you have open (switch off **Stale-screen notice** on Settings > Presence API to remove it; see [Stale-screen detection](#stale-screen-detection))
- On multisite, a Who's Online widget in Network Admin, an Online column in the Sites list, and an Online view, filter, and column in the Users list

## Run locally

```bash
npm install
npm run build
npx wp-env start
```

Then open [localhost:8888/wp-admin/](http://localhost:8888/wp-admin/) (admin / password).

This also activates [Presence Scenes](plugins/presence-scenes/README.md), which plays real users through situations like two people editing one post: `wp presence scene run editing-together`.

## WordPress Playground

No install needed: launch a scratch site straight from `main`.

|  | Post locks | RTC |
| --- | --- | --- |
| **Single site** | [![Launch post locks, single site](https://img.shields.io/badge/Launch-3858E9?style=for-the-badge&logo=wordpress&logoColor=white)](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/WordPress/presence-api/main/blueprint.json) | [![Launch RTC, single site](https://img.shields.io/badge/Launch-3858E9?style=for-the-badge&logo=wordpress&logoColor=white)](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/WordPress/presence-api/main/blueprint-rtc.json) |
| **Multisite** | [![Launch post locks, multisite](https://img.shields.io/badge/Launch-3858E9?style=for-the-badge&logo=wordpress&logoColor=white)](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/WordPress/presence-api/main/blueprint-multisite.json) | [![Launch RTC, multisite](https://img.shields.io/badge/Launch-3858E9?style=for-the-badge&logo=wordpress&logoColor=white)](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/WordPress/presence-api/main/blueprint-multisite-rtc.json) |

[![Launch Presence Scenes](https://img.shields.io/badge/Launch-3858E9?style=for-the-badge&logo=wordpress&logoColor=white)](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/WordPress/presence-api/main/blueprint-scenes.json) plays a [Presence Scene](plugins/presence-scenes/README.md) on the Posts screen.

## Data flow

1. Browser sends `presence-ping` via Heartbeat
2. Server upserts into `wp_presence`
3. Server answers with the admin bar node's markup, the Active Posts list, and a hash of the `admin/online` room
4. The admin bar swaps its node when the markup changes, unless its menu is open, and Active Posts redraws when its posts change
5. After a run of ticks with an unchanged hash, the ping widens the Heartbeat interval; a new hash snaps it back

Only Heartbeat refreshes a row between page loads. With its script removed, or its interval too long for the TTL, someone who stays on one screen drops out of the room while still there. Site Health reports both.

## Rooms

| Pattern                | Example            |
| ---------------------- | ------------------ |
| `admin/online`         | All admin pages    |
| `postType/{type}:{id}` | `postType/post:42` |

Each post type with presence support gets a room; see [Post Type Support](#post-type-support).

### Client IDs

A `client_id` prefix tells this plugin's rows apart from anyone else's in the same room. These are reserved:

| Prefix | Room | Written by |
| --- | --- | --- |
| `user-{user_id}` | `admin/online` | Heartbeat, login and logout |
| `editor-{user_id}` | Post rooms | Heartbeat, post locks |
| `cli-{user_id}` | Any | `wp presence set` when given no client ID |

Anything else writing to a room, such as a plugin relaying awareness or a REST client, must use its own prefix, like `gse-` for [gutenberg-sync-engines](https://github.com/WordPress/gutenberg-sync-engines). Pass it as `client_prefix` to read back only your rows: `wp_get_presence( $room, array( 'client_prefix' => 'gse-' ) )`.

A leading `_` marks bookkeeping rows, which are not participants: `_collab` holds a post room's last editor count for the collaboration actions, and `_lock` holds the post's `_edit_lock`. `wp_get_presence()` and the REST collection leave them out, and the REST write and delete routes reject them. `wp_set_presence()` writes one even with recording off, which is how a post lock outlasts the switch.

This plugin counts a post room's editors by the `editor-` prefix, so anyone else using it inflates the count.

## Agents

An AI agent working over REST or MCP runs no Heartbeat, so the plugin writes its row when it saves a post. That row puts it, labelled, in the admin bar, the Active Posts widget and the post list's Editors column.

The row is written on `wp_after_insert_post` (REST, abilities and WP-CLI) when the current user is an agent. It is `agent-{user_id}` in `postType/{post_type}:{post_id}` and expires on its own, so an agent that stops saving drops out. People's saves and trashing a post write nothing.

To show up before its first save, an agent can still write the row itself:

```php
// Join: write a row that expires in 60 seconds if nothing renews it.
wp_set_presence( 'postType/post:42', 'agent-' . $user_id, array(), array( 'user_id' => $user_id, 'expires_in' => 60 ) );

// While still working, write again before the window runs out, to stay present.
wp_set_presence( 'postType/post:42', 'agent-' . $user_id, array(), array( 'user_id' => $user_id, 'expires_in' => 60 ) );

// Leave, once done. Otherwise the row simply expires on its own.
wp_remove_presence( 'postType/post:42', 'agent-' . $user_id );
```

Renew a short row instead of writing one long `$expires_in`, since an untouched row reads as idle long before a long window runs out. [`wp_presence_max_expires_in`](#wp_presence_max_expires_in) caps `$expires_in` at one hour by default.

`agent-{user_id}` is only a convention. What matters is `$user_id`: a row without one, or with a person's, is never labelled.

Another plugin, such as [Agent Users](https://github.com/WordPress/ai/pull/961), decides who is an agent. `wp_presence_is_agent_user( $user_id )` is a filter, `false` by default:

```php
add_filter( 'wp_presence_is_agent_user', function ( $is_agent, $user_id ) {
    return function_exists( 'wpai_is_agent_user' ) ? wpai_is_agent_user( $user_id ) : $is_agent;
}, 10, 2 );
```

This plugin already registers it, so nothing more is needed once `wpai_is_agent_user()` is loaded.

[`wp_presence_collaboration_started`](#wp_presence_collaboration_started) does not count agents, since it watches `editor-` rows only.

## PHP API

<details>
<summary>Functions, return shapes, and network variants</summary>

These functions are the stable API. Treat every other function as internal, since it may change without notice. Optional arguments go in `$args`, an array or query string as core's `wp_parse_args()` takes them; the positional parameters from before 0.17.0 still work.

```php
// Read all presence entries in a room, or only those whose client_id starts
// with 'client_prefix'. The prefix is matched literally, in the query.
// A 'timeout' you pass is the window used; with null, each row counts until its own expiry.
$entries = wp_get_presence( $room, array( 'timeout' => null, 'client_prefix' => '' ) );

// Read every room whose identifier starts with $prefix, newest first.
// 'postType/' lists who is editing which post.
$entries = wp_get_presence_by_room_prefix( $prefix, $timeout = null );

// Upsert a client's presence state. Atomic via INSERT … ON DUPLICATE KEY UPDATE.
// 'date_gmt' ('Y-m-d H:i:s') lets a relay keep each client's own timestamp.
// Future values are clamped to now, invalid dates return false. Defaults to now.
// 'expires_in' (seconds from 'date_gmt') is how long the row counts as present,
// for a writer that removes its own rows when clients leave. Capped by
// wp_presence_max_expires_in (default one hour); under one second returns
// false. Defaults to the site TTL.
// Passing either one always writes, even when the row is unchanged.
wp_set_presence( $room, $client_id, $state, array( 'user_id' => 0, 'date_gmt' => null, 'expires_in' => null ) );

// Remove a single client from a room.
wp_remove_presence( $room, $client_id );

// Write or remove a client, then read the room back, in one call.
$entries = wp_presence_exchange( $room, $client_id, $state, array( 'user_id' => 0, 'timeout' => null, 'client_prefix' => '' ) );
$entries = wp_presence_leave( $room, $client_id, array( 'timeout' => null, 'client_prefix' => '' ) );

// Wait for a room to change without reading its rows. The version moves when
// what peers see changes: a client arriving (over its own expired row too),
// changing its state or user, or being removed. A refresh that only restamps a
// row does not move it, and neither does a row expiring, so read the room again
// once its next expiry passes. Both return null for a room with nothing to say.
$versions = wp_get_presence_room_versions( $rooms ); // array( $room => '12', ... )
$expiry   = wp_get_presence_room_next_expiry( $rooms ); // array( $room => 'Y-m-d H:i:s', ... )

// Remove all presence entries for a user across all rooms.
wp_remove_user_presence( $user_id );

// Check whether a user can access a room (edit_post for a post's room, otherwise any post type).
wp_can_access_presence_room( $room, $user_id = 0 );

// Return the canonical room string for a post, or false if the post type
// does not support presence.
$room = wp_presence_post_room( $post );

// The reverse: array( 'post_type' => 'post', 'post_id' => 42 ), or false
// for a room that is not a post's.
$parts = wp_presence_parse_room( $room );

// Return the site-wide room, 'admin/online'.
$room = wp_presence_admin_room();

// Whether a user is an AI agent; see Agents.
wp_presence_is_agent_user( $user_id );

// Whether this site records presence at all.
wp_presence_recording_enabled();

// Whether presence can be used here: the table exists and recording is on.
wp_presence_is_available();
```

The plugin's screens also call internal helpers. A plugin can get the same result this way:

| Internal helper | Use instead |
| --- | --- |
| `wp_presence_online_user_ids()` | `wp_list_pluck( $entries, 'user_id' )`, plus `get_current_user_id()` if the viewer always counts |
| `wp_presence_render_avatar_stack()` | `get_avatar()` for each user, with your own markup |
| `wp_presence_render_agent_badge()` | `wp_presence_is_agent_user()`, with your own label |
| `wp_presence_fragment_request()` | Core's `heartbeat_received` filter, under a key of your own |

If you build on this plugin, check `wp_presence_is_available()` before you depend on it. The `function_exists()` guard covers the plugin not being loaded:

```php
if ( function_exists( 'wp_presence_is_available' ) && wp_presence_is_available() ) {
	// Read and write presence.
}
```

Without that check, a site with no table or with recording off still accepts your calls: `wp_set_presence()` returns `false`, and once leftover rows expire, `wp_get_presence()` returns an empty array that reads the same as an empty room.

Each entry object returned by `wp_get_presence()` or `wp_get_presence_by_room_prefix()` has:

| Field       | Type     | Notes                                                                                                                      |
| ----------- | -------- | --------------------------------------------------------------------------------------------------------------------------- |
| `room`      | `string` | The room the entry belongs to.                                                                                              |
| `client_id` | `string` | A `varchar` column, opaque, and not guaranteed numeric even when it looks like one. See [Client IDs](#client-ids).         |
| `user_id`   | `string` | `"0"` for an entry with no signed-in user. Every column comes back as a string, so cast before a strict comparison. |
| `data`      | `array`  | Decoded from the stored JSON; an empty array if that JSON failed to decode.                                                 |
| `date_gmt`  | `string` | A MySQL `datetime` string in UTC (e.g. `2024-01-01 12:00:00`), not a Unix timestamp. Convert with `strtotime( $entry->date_gmt . ' UTC' )`. See below for how far behind a live client it can sit. |

An unchanged row isn't rewritten on every ping, so `date_gmt` can trail a live client. `wp_get_presence()` already filters on each row's expiry, so this only matters for a tighter window of your own.

### Network

These load only on multisite, so check `function_exists()` before calling them on a single site.

```php
// Whether this network assembles its sites' rows into the network-wide view.
wp_presence_network_aggregation_enabled();
```

</details>

## Extension Points

### Post Type Support
The plugin adds `presence` support to templates, template parts, and every post type with `show_ui` and `editor` support, including ones registered after it loads. To leave one out, remove support once it is registered:
```php
add_action( 'init', function () {
    remove_post_type_support( 'my-post-type', 'presence' );
}, 11 );
```

Without support, `wp_presence_post_room()` returns `false` for that post type and no per-post room is created. Its post locks still move out of post meta.

### React hook
Add `wp-presence` to a script's dependencies to use [`wp.presence.usePresenceUsers()`](plugins/presence-api/src/README.md), which lists who else is in a room.

<details>
<summary>Filters and actions</summary>

### Filters
#### `wp_presence_default_ttl`
The TTL in seconds for writes and reads that don't name their own window. Default: 150. Stored rows pick up a new value on their next ping.

Values under 120 drop open tabs, since 120 is core's Heartbeat interval for an unfocused or idle tab.

An explicit `timeout`, such as `wp_get_presence( $room, array( 'timeout' => 30 ) )`, is left alone, so widening the TTL never makes that caller read stale clients as present.
```php
add_filter( 'wp_presence_default_ttl', function( $timeout ) {
    return 300; // Override TTL to 5 minutes.
} );
```

Or define the constant before the plugin loads:
```php
define( 'WP_PRESENCE_DEFAULT_TTL', 300 );
```

#### `wp_presence_heartbeat_idle_ticks`
How many unchanged ticks in a row widen the Heartbeat interval. Default: 5. Return 0 to turn the backoff off.
```php
add_filter( 'wp_presence_heartbeat_idle_ticks', fn() => 0 );
```

#### `wp_presence_heartbeat_idle_interval`
The widened Heartbeat interval, in seconds. Default: 45. The ping keeps it inside the TTL, so idle people never drop out; see [Data flow](#data-flow).

#### `wp_presence_cleanup_batch_size`
How many expired rows cron deletes per pass. Default: 1000.

#### `wp_presence_cleanup_max_passes`
How many delete passes one cron run makes. Default: 10. Anything left waits for the next run.

#### `wp_presence_max_expires_in`
The longest `$expires_in` a writer may ask for, in seconds. Default: 3600. The privacy policy text and personal data export report it as the retention period.
```php
add_filter( 'wp_presence_max_expires_in', fn() => 15 * MINUTE_IN_SECONDS );
```

#### `wp_presence_current_screen_key`
The current admin screen's key for [stale-screen detection](#stale-screen-detection). Core screens and Settings API pages have their own, and `$key` is `''` elsewhere. Return a non-empty string to opt a custom screen in.
```php
add_filter( 'wp_presence_current_screen_key', function( $key, $screen ) {
    if ( 'toplevel_page_my-plugin' === $screen->id ) {
        return 'options/my-plugin-settings';
    }
    return $key; // Leave other screens untouched.
}, 10, 2 );
```

Keys are slash-separated like rooms. The filter cuts them to 191 characters (`WP_PRESENCE_SCREEN_KEY_LIMIT`), while `wp.presence.markScreenStale()` rejects longer keys and any characters other than lowercase letters, digits, `/`, `_` and `-`, so pass it the same key.

#### `wp_presence_editor_state`
Filters the state saved in an editor's post room row on each tick, so a plugin can add its own data. Keep it stable between ticks, or every tick writes.
```php
add_filter( 'wp_presence_editor_state', function( $state, $post_id, $user_id ) {
    $state['my_plugin_panel'] = 'seo';
    return $state;
}, 10, 3 );
```

#### `wp_presence_recording_enabled`
Whether this site records presence. Default: the **Presence** checkbox on Settings > General, on for new installs. Return `false` and writes stop, so surfaces empty as existing rows expire, within one TTL for Heartbeat rows.
```php
add_filter( 'wp_presence_recording_enabled', '__return_false' );
```

On multisite, `wp_presence_network_recording_enabled` does the same for every site, defaulting to the **Presence** checkbox on Network Admin > Settings. Either switch turning recording off wins.

#### `wp_presence_feature_enabled`
Whether a piece of the plugin is on for this site, passed the feature key. Default: its checkbox on Settings > Presence API, on until switched off. On multisite, the same checkbox on Network Admin > Settings > Presence API switches a feature off for every site. `network-admin`, the Network Admin screens, has its checkbox on the network page alone and follows only the network's choice. Hooks are registered when the plugin loads, so add this from a must-use plugin. Each switchable piece has a checkbox on Settings > Presence API, saved under its key in the `wp_presence_features` option, and [#710](https://github.com/WordPress/presence-api/issues/710) tracks the ones still to come.
```php
add_filter( 'wp_presence_feature_enabled', function ( $enabled, $feature ) {
    return 'post-locks' === $feature ? false : $enabled;
}, 10, 2 );
```

#### `wp_presence_is_agent_user`
Filters whether a user is an AI agent, for labelling its presence rows. Default: `false`, deferred to `wpai_is_agent_user()` when it is loaded (see [Agents](#agents)).

#### `wp_presence_network_aggregation_enabled`
Whether a network builds the network-wide view behind Network Admin. Default: `true` unless [`wp_is_large_network()`](https://developer.wordpress.org/reference/functions/wp_is_large_network/). Recording is separate, so sites keep recording with this off.
```php
add_filter( 'wp_presence_network_aggregation_enabled', '__return_false' );
```

#### `wp_presence_network_capability`
The capability needed to see network-wide presence. Default: `manage_network`. A site-level capability such as `edit_posts` shows every holder who is online on every site, including sites they don't belong to.

#### `wp_presence_network_summary_refresh_interval`
How stale a site's row in the network summary may get, in seconds, before a write refreshes it. It can only be lowered, since the default is the longest that keeps rows inside the TTL.

#### `wp_presence_debugger_indicators`
Filters the icons beside the admin bar debugger's countdown, which refresh with each Heartbeat. The debugger loads only under `WP_DEBUG` in a git checkout, since the release zip leaves it out.
```php
add_filter( 'wp_presence_debugger_indicators', function( $indicators ) {
    $indicators[] = array( 'icon' => 'dashicons-controls-play', 'label' => __( 'Import running', 'my-plugin' ) );
    return $indicators;
} );
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
Fires when a room goes from one editor to two or more. Only `editor-` rows count, but `$entries` holds every client except bookkeeping rows. The previous count lives in `_collab` and expires on the TTL, so the next pair after everyone leaves is a fresh start.
```php
add_action( 'wp_presence_collaboration_started', function( $room, $entries ) {
    // Announce room active or update integration state
}, 10, 2 );
```

#### `wp_presence_collaboration_ended`
Fires when a room goes from two or more editors to one. It runs on an editor's Heartbeat tick, so if everyone leaves at once it never fires and the room resets when `_collab` expires.
```php
add_action( 'wp_presence_collaboration_ended', function( $room, $entries ) {
    // Announce room inactive or update integration state
}, 10, 2 );
```

#### `wp_presence_debugger_menu`
Fires after the debugger's Interval and TTL rows, on page load and on each Heartbeat refresh. Add nodes under `presence-debug` with the `presence-debug-row` class; a `presence-debug-value` span right-aligns a value.
```php
add_action( 'wp_presence_debugger_menu', function( $wp_admin_bar ) {
    $wp_admin_bar->add_node( array(
        'parent' => 'presence-debug',
        'id'     => 'presence-debug-imports',
        'title'  => '<span>Imports</span><span class="presence-debug-value">3</span>',
        'meta'   => array( 'class' => 'presence-debug-row' ),
    ) );
} );
```

#### `wp_presence_admin_room_changed`
Fires after a write changes at least one row in the `admin/online` room, and whenever `wp_remove_user_presence()` deletes rows. Multisite uses it to refresh the network summary.
```php
add_action( 'wp_presence_admin_room_changed', function() {
    // Refresh a cached headcount.
} );
```

#### `set_presence`
Fires after `wp_set_presence()` writes a row, named like core's `set_transient`. A write skipped because the row was unchanged and recently stamped fires nothing, and neither does a reserved row such as `_lock` or `_collab`. The skip holds on MySQL; SQLite counts an unchanged write as a change, so it fires there.
```php
add_action( 'set_presence', function( $room, $client_id, $state, $user_id ) {
    // Push the change to a WebSocket server.
}, 10, 4 );
```

#### `removed_presence`
Fires after `wp_remove_presence()` deletes a client's row from a room, and not when there was no row to delete or the row is a reserved one. A row that expires fires nothing: it stops counting as present at its expiry and is cleaned up later by cron.
```php
add_action( 'removed_presence', function( $room, $client_id ) {
    // Tell the room this client left.
}, 10, 2 );
```

#### `removed_user_presence`
Fires once after `wp_remove_user_presence()` deletes a user's rows across every room, such as on logout. It does not fire `removed_presence` for each row.
```php
add_action( 'removed_user_presence', function( $user_id ) {
    // Drop anything cached for this user.
} );
```

### JS Actions
Fired through `wp.hooks`, not PHP. The plugin's ping script is the only thing that computes these, so a consumer has to listen rather than poll for them.

#### `presence-api.watchingRoom`
Fires once, before Heartbeat's first tick, on the edit screen of a post type with `presence` support, so a listener can tell "presence-api isn't here" from "no tick yet."
```js
wp.hooks.addAction( 'presence-api.watchingRoom', 'my-plugin', ( room ) => {
    // room is 'postType/{type}:{id}'
} );
```

#### `presence-api.collaborationStarted`
Fires on the same edge as `wp_presence_collaboration_started`. `count` includes you, so it is at least 2. A third editor joining later fires nothing, so read the room for a live number.
```js
wp.hooks.addAction( 'presence-api.collaborationStarted', 'my-plugin', ( room, count ) => {
    // Someone besides you is now in the room.
} );
```

#### `presence-api.collaborationEnded`
Fires on the same edge as `wp_presence_collaboration_ended`. `count` is 1, meaning you. If everyone leaves at once, it never fires.
```js
wp.hooks.addAction( 'presence-api.collaborationEnded', 'my-plugin', ( room, count ) => {
    // You are alone in the room again.
} );
```

#### `presence-api.surfaceUpdated`
Fires after a live surface's markup is swapped for a fresh copy from Heartbeat. Swaps wait while the pointer, focus or a checked checkbox is inside the surface.
```js
wp.hooks.addAction( 'presence-api.surfaceUpdated', 'my-plugin', ( key, element ) => {
    // key names the surface, such as 'admin-bar', or one you registered.
} );
```

### JS Filters

#### `presence-api.liveSurfaces`
The surfaces the plugin's ping script keeps current. A surface asks Heartbeat for its key while `target()` finds it on the page, and the server answers with HTML under the same key. That HTML goes in as is, so escape it in your `heartbeat_received` callback.

| Property | Required | Description |
| --- | --- | --- |
| `key` | Yes | The key sent under `presence-fragments` and answered under the same key. |
| `target( id )` | Yes | Returns the element to fill, or null when it is not on the page. |
| `request()` | No | Returns what to send instead of `true`, such as row IDs; a falsy value skips the ask. |
| `apply( element, html )` | No | Swaps the markup in, instead of setting `innerHTML`. |

A response can also be an object of HTML keyed by ID, in which case each entry fills `target( id )`.
```js
wp.hooks.addFilter( 'presence-api.liveSurfaces', 'my-plugin', ( surfaces ) => [
    ...surfaces,
    { key: 'my-plugin-count', target: () => document.getElementById( 'my-plugin-count' ) },
] );
```
```php
add_filter( 'heartbeat_received', function( $response, $data ) {
    if ( ! empty( $data['presence-fragments']['my-plugin-count'] ) && wp_can_access_presence_room( wp_presence_admin_room() ) ) {
        $response['presence-fragments']['my-plugin-count'] = esc_html( my_plugin_count() );
    }
    return $response;
}, 10, 2 );
```

</details>

## REST API

All endpoints require editing at least one post type, and a post room also requires editing that post, and marking a screen stale requires that screen's own capability. GET responses include `Cache-Control: no-store`.

<details>
<summary>Endpoints</summary>

| Method | Path | Description |
|---|---|---|
| `GET` | `/wp-presence/v1/presence` | List entries in a room |
| `POST` | `/wp-presence/v1/presence` | Upsert a presence entry |
| `DELETE` | `/wp-presence/v1/presence` | Remove a presence entry |
| `GET` | `/wp-presence/v1/presence/rooms` | List active rooms |
| `POST` | `/wp-presence/v1/presence/screen-revisions/stale` | Mark a screen key stale |

### Network

Multisite only, and gated on `manage_network` by default rather than `edit_posts`.

| Method | Path | Description |
|---|---|---|
| `GET` | `/wp-presence/v1/presence/network` | List sites with users online, busiest first |
| `GET` | `/wp-presence/v1/presence/network/<blog_id>` | One site's users online |

The collection takes `page` and `per_page` (default 50, max 100), with site counts in `X-WP-Total` and `X-WP-TotalPages` and the network headcount in `X-WP-Presence-Users-Online`. Both routes take `users_per_site` to cap the users named per site (default 0, all), while `user_count` stays the real total. Only an unknown `blog_id` is a 404.

</details>

## WP-CLI

<details>
<summary>Commands</summary>

```
wp presence list <room>  # List a room's entries, reserved rows included
wp presence summary   # Summary grouped by room
wp presence set       # Manually upsert an entry
wp presence cleanup   # Delete every entry, live or expired, after confirmation
wp presence network   # Network-wide summary (multisite only)
wp presence recording # Read or set the recording switch
```

```
wp presence recording get
wp presence recording set off
wp presence recording set off --network   # Multisite only
```

</details>

## Relationship to the block editor

Cursors, selections and who is in which block belong to the plugin implementing the block editor's collaboration storage, which can keep its awareness rows in this table. When it does, someone editing a post also shows up in the admin bar and the post list.

Gutenberg's [`__unstable_wp_sync_storage`](https://github.com/WordPress/gutenberg/pull/81697) filter takes one `WP_Sync_Storage` for both awareness and the CRDT update log, and [WordPress/gutenberg#83165](https://github.com/WordPress/gutenberg/issues/83165) asks for them to be separable. Until then, a plugin can route awareness to `wp_get_presence()`, `wp_set_presence()` and `wp_remove_presence()` behind its own filter, as [gutenberg-sync-engines](https://github.com/WordPress/gutenberg-sync-engines) does with `wp_sync_awareness_backend`. CRDT updates stay in the editor plugin's table.

Both sides use `postType/{type}:{id}`, the grammar of Gutenberg's `WP_Sync_Config::parse_room()` and what [`wp_presence_post_room()`](#php-api) returns. The `client_id` prefix keeps their rows apart: `gse-{id}` for the editor plugin, `editor-{user_id}` for this one.

A consumer like Gutenberg's sync poll loop ([presence-api#444](https://github.com/WordPress/presence-api/issues/444)) can wait for `presence-api.collaborationStarted` instead of polling to learn whether anyone else is in the room.

## Post-lock bridge

Keeps `_edit_lock` in the post room's `_lock` row instead of post meta, so refreshing a lock no longer makes cached post queries stale. Meta short-circuits keep `wp_check_post_lock()` and other callers working, and it applies to every post type even with recording off. Switch off **Post locks** on Settings > Presence API and locks stay in post meta, as core keeps them.

## Capability

All features require editing at least one post type shown in the admin, so a role limited to pages or a custom post type is included. Network views need `manage_network` by default.

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

For a screen to be *watched* in the first place, it needs a screen key. Core screens resolve their own, and custom screens supply one via the [`wp_presence_current_screen_key`](#wp_presence_current_screen_key) filter.

## Maintainers

- [@josephfusco](https://github.com/josephfusco)
- [@i-am-chitti](https://github.com/i-am-chitti)

Sponsored by the [Core team](https://make.wordpress.org/core/). Updates posted on [make.wordpress.org/core](https://make.wordpress.org/core/) with the tag `#presence-api`.

## Support

Questions and bug reports: [GitHub Issues](https://github.com/WordPress/presence-api/issues).

Discussion: [#feature-presence-api](https://wordpress.slack.com/archives/feature-presence-api) on WordPress Slack
