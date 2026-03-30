# Architecture

## Data flow

1. Browser sends `presence-ping` via Heartbeat
2. Server upserts into `wp_presence`
3. Server reads the room and returns entries in the heartbeat response
4. Client diffs a signature of user IDs and swaps HTML when content changes
5. Client-side interval re-evaluates idle state every 5s between heartbeat ticks

## Rooms

| Pattern | Example |
|---|---|
| `admin/online` | All admin pages |
| `postType/{type}:{id}` | `postType/post:42` |

Post types opt in via `add_post_type_support( 'post', 'presence' )`.

## Post-lock bridge

Creates presence entries alongside `_edit_lock` postmeta when a post lock is refreshed via Heartbeat. Both systems coexist.

## Capability

All features require `edit_posts`. Filterable via `wp_presence_user_can_access_room`.

## Alternatives considered

**WebSocket** — Heartbeat handles visibility throttling, idle timeout, and suspension. Sub-second presence (cursors, selections) is a separate concern.

**Post meta** — Triggers `wp_cache_set_posts_last_changed()` on every write, invalidating `WP_Query` caches site-wide. See [#64696](https://core.trac.wordpress.org/ticket/64696), [#64916](https://core.trac.wordpress.org/ticket/64916).

**Persistent history** — Activity logging is a separate concern. See XWP Stream.
