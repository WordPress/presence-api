# Presence API

System-wide presence and awareness for WordPress.

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
