# Presence API

[![CI](https://github.com/WordPress/presence-api/actions/workflows/ci.yml/badge.svg)](https://github.com/WordPress/presence-api/actions/workflows/ci.yml)

> **Status:** Experimental feature plugin

System-wide presence and awareness for WordPress.

## Problem

WordPress has no way to know who is logged in, what screen they are on, or which posts are being edited — without writing to shared tables like `wp_postmeta` or `wp_options`. High-frequency writes to those tables invalidate caches site-wide ([#64696](https://core.trac.wordpress.org/ticket/64696)). This plugin uses a dedicated `wp_presence` table with a 60-second TTL to provide that awareness with zero cache side effects.

> "This idea of presence I think is really cool and seeing where people are... you log into your WordPress, I see oh Matias is moderating some comments, Lynn is on the dashboard maybe reading some news... that idea of like you log in and you can kind of see the neighborhood of like who else is also there." — [Matt Mullenweg, WordPress 7.0 planning session](https://youtu.be/F-xMPY9WqG4?si=YK0rIUM2nuYy7x45&t=2435)

[![Watch the demo on YouTube](https://img.youtube.com/vi/Xa5WkZdjBD4/maxresdefault.jpg)](https://youtu.be/Xa5WkZdjBD4)

▶ [Watch the demo on YouTube](https://youtu.be/Xa5WkZdjBD4) (no audio)

## Try it

[Test in WordPress Playground](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/WordPress/presence-api/main/blueprint.json)

Or run locally:

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

## PHP API

Six public functions are part of the stable API contract. Everything else in `includes/functions.php` is marked `@access private` and may change without notice.

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
```

Each entry object returned by `wp_get_presence()` has: `room`, `client_id`, `user_id`, `data` (array), `date_gmt`.

## REST API

All endpoints require `edit_posts`. Responses include `Cache-Control: no-store`.

| Method | Path | Description |
|---|---|---|
| `GET` | `/wp-presence/v1/presence` | List entries in a room |
| `POST` | `/wp-presence/v1/presence` | Upsert a presence entry |
| `DELETE` | `/wp-presence/v1/presence` | Remove a presence entry |
| `GET` | `/wp-presence/v1/presence/rooms` | List active rooms |

## WP-CLI

```
wp presence list      # List all active presence entries
wp presence summary   # Summary grouped by room
wp presence set       # Manually upsert an entry
wp presence cleanup   # Delete expired entries immediately
```

## Filters and constants

```php
// Override the TTL (seconds) used for all queries and cleanup. Default: 60.
add_filter( 'wp_presence_default_ttl', fn() => 30 );

// Or define the constant before the plugin loads.
define( 'WP_PRESENCE_DEFAULT_TTL', 30 );
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

## Maintainers

- [@josephfusco](https://github.com/josephfusco)

Sponsored by the [Core team](https://make.wordpress.org/core/). Updates posted on [make.wordpress.org/core](https://make.wordpress.org/core/) with the tag `#presence-api`.

## Support

Questions and bug reports: [GitHub Issues](https://github.com/WordPress/presence-api/issues).

Discussion: [#feature-presence-api](https://wordpress.slack.com/archives/feature-presence-api) on WordPress Slack
