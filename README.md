# Presence API

> **Status:** Experimental feature plugin

System-wide presence and awareness for WordPress.

## Problem

WordPress has no way to know who is logged in, what screen they are on, or which posts are being edited — without writing to shared tables like `wp_postmeta` or `wp_options`. High-frequency writes to those tables invalidate caches site-wide ([#64696](https://core.trac.wordpress.org/ticket/64696)). This plugin uses a dedicated `wp_presence` table with a 60-second TTL to provide that awareness with zero cache side effects.

> "This idea of presence I think is really cool and seeing where people are... you log into your WordPress, I see oh Matias is moderating some comments, Lynn is on the dashboard maybe reading some news... that idea of like you log in and you can kind of see the neighborhood of like who else is also there." — [Matt Mullenweg, WordPress 7.0 planning session](https://youtu.be/F-xMPY9WqG4?si=YK0rIUM2nuYy7x45&t=2435)

[![Watch the demo on YouTube](https://img.youtube.com/vi/Xa5WkZdjBD4/maxresdefault.jpg)](https://youtu.be/Xa5WkZdjBD4)

▶ [Watch the demo on YouTube](https://youtu.be/Xa5WkZdjBD4) (no audio)

## Try it

```bash
npm install
npx wp-env start
npx wp-env run cli wp presence demo 10 --keep-alive
```

Then open [localhost:8888/wp-admin/](http://localhost:8888/wp-admin/) (admin / password).

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

All features require `edit_posts`.

## Maintainers

- [@josephfusco](https://github.com/josephfusco)

Sponsored by the [Core team](https://make.wordpress.org/core/). Updates posted on [make.wordpress.org/core](https://make.wordpress.org/core/) with the tag `#presence-api`.

## Support

Questions and bug reports: [GitHub Issues](https://github.com/WordPress/presence-api/issues).
