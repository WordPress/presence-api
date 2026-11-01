# Presence API docs

Presence API shows who else is working on a WordPress site: who is logged in, which screen they are on, and which posts they have open. Under the hood, everything it knows is a row in one table, `wp_presence`, and a row counts until it expires.

```mermaid
flowchart LR
	accTitle: How presence moves through the system
	accDescr: Writers such as Heartbeat ticks, page loads, relays and agents write and renew rows in the wp_presence table. Readers such as admin screens, the REST API and hooks read the rows that have not expired.
	W["<b>Writers</b><br/>Heartbeat ticks<br/>page loads<br/>relays and agents"] -->|write and renew| T[("<b>wp_presence</b><br/>one row per<br/>room and client")]
	T -->|rows not yet expired| R["<b>Readers</b><br/>admin screens<br/>REST API<br/>hooks"]
```

## Where to start

- To use it on a site, read the [Introduction](introduction.md), [Admin screens](admin-screens.md), [Stale-screen notices](stale-screen-notices.md) and [Post locks](post-locks.md).
- To build with it, read every page in order from the [Introduction](introduction.md), then try the [Project](project.md).

These pages describe the plugin's `main` branch.
