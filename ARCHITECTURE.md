# Architecture

## Table

```sql
CREATE TABLE wp_presence (
    id         bigint(20) unsigned NOT NULL auto_increment,
    room       varchar(191)        NOT NULL default '',
    client_id  varchar(191)        NOT NULL default '',
    user_id    bigint(20) unsigned NOT NULL default '0',
    data       text                NOT NULL,
    date_gmt   datetime            NOT NULL default '0000-00-00 00:00:00',
    PRIMARY KEY  (id),
    UNIQUE KEY room_client (room, client_id),
    KEY date_gmt (date_gmt),
    KEY user_id (user_id),
    KEY room_date (room(40), date_gmt)
);
```

`UNIQUE KEY (room, client_id)` enables `INSERT ... ON DUPLICATE KEY UPDATE`. Post meta cannot carry a unique constraint.

60-second TTL. Cron deletes expired rows in batches of 1,000. No data persists beyond the TTL.

Per-site on multisite. Each site has its own `{prefix}presence` table.

## Data flow

1. Browser sends `presence-ping` via Heartbeat
2. Server upserts into `wp_presence`
3. Server reads the room and returns entries in the heartbeat response
4. Client diffs a signature of user IDs and swaps HTML when content changes
5. Client-side interval re-evaluates idle state every 5s between heartbeat ticks

## Surfaces

| Surface | Description |
|---|---|
| Who's Online widget | Users with avatar, screen label, active/idle indicator |
| Active Posts widget | Posts being edited with editor avatars |
| Admin bar | Avatar stack, count, dropdown grouped by "On this page" / "Elsewhere" |
| Post list | Editors column |
| Users list | Online filter tab |
| Frontend | Admin bar presence for logged-in users on singular views |
| Debugger widget | Heartbeat status, active users, TTL, room breakdown (WP_DEBUG) |

## States

| State | Condition | Indicator |
|---|---|---|
| Active | Heartbeat within 30s | Solid dot |
| Idle | 30–60s since last heartbeat | Hollow dot |
| Gone | 60+s, row expired | Removed |

## Rooms

| Pattern | Example |
|---|---|
| `admin/online` | All admin pages |
| `postType/{type}:{id}` | `postType/post:42` |

Post types opt in via `add_post_type_support( 'post', 'presence' )`.

## Post-lock bridge

Creates presence entries alongside `_edit_lock` postmeta when a post lock is refreshed via Heartbeat. Both systems coexist.

## Frontend presence

On singular views, the heartbeat ping includes the post ID, type, and title. Non-singular views report as "Viewing site."

## Public API

```php
wp_get_presence( $room, $timeout )
wp_set_presence( $room, $client_id, $state, $user_id )
wp_remove_presence( $room, $client_id )
wp_remove_user_presence( $user_id )
wp_can_access_presence_room( $room, $user_id )
wp_presence_post_room( $post )
```

## REST

```
GET    /wp-presence/v1/presence?room={room}&per_page=100&page=1
POST   /wp-presence/v1/presence
DELETE /wp-presence/v1/presence
GET    /wp-presence/v1/presence/rooms?per_page=50&page=1
```

Response headers: `X-WP-Total`, `X-WP-TotalPages`, `Cache-Control: no-store`.

## WP-CLI

```
wp presence set <room> [<client_id>] [--data=<json>] [--user=<id>]
wp presence list <room> [--format=<table|json|csv>]
wp presence summary [--format=<table|json>]
wp presence cleanup [--yes]
wp presence demo [<count>] [--keep-alive] [--cleanup]
```

## Hooks

| Hook | Type | Description |
|---|---|---|
| `wp_presence_user_can_access_room` | Filter | Room access check |
| `wp_presence_default_ttl` | Filter | Timeout in seconds (default 60) |

`WP_PRESENCE_DEFAULT_TTL` can also be defined in `wp-config.php` to override the default.

## Capability

All features require `edit_posts`. Filterable via `wp_presence_user_can_access_room`.

## Alternatives considered

**WebSocket** — Heartbeat handles visibility throttling, idle timeout, and suspension. Sub-second presence (cursors, selections) is a separate concern.

**Post meta** — Triggers `wp_cache_set_posts_last_changed()` on every write, invalidating `WP_Query` caches site-wide. See [#64696](https://core.trac.wordpress.org/ticket/64696), [#64916](https://core.trac.wordpress.org/ticket/64916).

**Persistent history** — Activity logging is a separate concern. See XWP Stream.
