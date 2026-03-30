# Presence API

System-wide presence and awareness for WordPress.

> "This idea of presence I think is really cool and seeing where people are... you log into your WordPress, I see oh Matias is moderating some comments, Lynn is on the dashboard maybe reading some news... that idea of like you log in and you can kind of see the neighborhood of like who else is also there." — [Matt Mullenweg, WordPress 7.0 planning session](https://youtu.be/F-xMPY9WqG4?si=YK0rIUM2nuYy7x45&t=2435)

## Try it

```bash
npm install
npx wp-env start
npx wp-env run cli wp presence demo 10 --keep-alive
```

Then open [localhost:8888/wp-admin/](http://localhost:8888/wp-admin/) (admin / password).

## How it works

A dedicated `wp_presence` table with a `UNIQUE KEY` on `(room, client_id)` enables atomic upserts via `INSERT ... ON DUPLICATE KEY UPDATE`. Entries expire after 60 seconds. Data flows through the WordPress [Heartbeat API](https://developer.wordpress.org/plugins/javascript/heartbeat-api/). Purely ephemeral — nothing persists beyond the TTL.

Six public functions. Four REST endpoints. One capability gate (`edit_posts`). Zero cache side effects.

See [ARCHITECTURE.md](ARCHITECTURE.md) for the full technical breakdown, API reference, and WP-CLI commands.
