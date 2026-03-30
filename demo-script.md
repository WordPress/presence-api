# Presence API — Demo Script

> **Runtime target:** ~2:00
> **Setup:** Fresh WordPress install, plugin activated, WP_DEBUG on.
> **Browser tabs:** Dashboard (logged in as admin), second browser/profile ready for User B.

---

## ACT 1 — The Empty Room (0:00–0:25)

**[Screen: Dashboard, Debugger widget visible]**

> Nobody's here. The heartbeat is flat, the table is empty. The system is at rest, costing zero.

**Terminal:**

```bash
wp presence summary
```

> Zero entries. Let's talk about *which* table.

> WordPress stores almost everything — options, transients, sessions — in a handful of shared tables. On a starter plan, that's fine. At scale, those tables become bottlenecks — every feature that writes there competes for the same rows, the same locks, the same indexes. Presence data is high-frequency and short-lived: it doesn't belong in a table designed for things that stick around. So this plugin uses its own dedicated table. Small, purpose-built, and completely isolated from the rest of WordPress. Hosts don't have to worry about it because it can't interfere with anything else.

---

## ACT 2 — First Signs of Life (0:25–0:50)

**[Refresh Dashboard in browser]**

> I loaded the dashboard and the Heartbeat API — which WordPress already runs — fired a ping. My entry appeared. No new connections, no new infrastructure.

```bash
wp presence list admin/online --format=table
```

> One row. If I stop pinging, it self-destructs in 60 seconds. That's the key design choice: every row has a built-in expiration. The table never grows unbounded — it only holds what's alive right now.

> Let's simulate a team.

```bash
wp presence demo 5
```

> Five users joined. Avatars, screens, active dots — all live.

```bash
wp presence summary --format=table
```

> Six users across multiple rooms. The table stays tiny because it only holds the present moment.

---

## ACT 3 — Real-Time Awareness (0:50–1:20)

**[Open a post in the block editor]**

> I opened a post. A second entry appeared for me in this post's room. The Active Posts widget shows who's editing what — live.

**[Open second browser, log in as User B, open the same post]**

> Two editors on the same post. No WebSocket server, no Redis — just the Heartbeat that was already running. This matters for hosts: zero additional infrastructure to provision, monitor, or scale.

```bash
wp presence list postType/post:1 --format=table
```

> Two rows. The post list shows stacked avatars, the admin bar shows a live count. All from the same small table.

---

## ACT 4 — Entropy (1:20–1:45)

**[Close User B's browser tab]**

> User B closed the tab. No logout event fired. But watch —

*Wait ~10 seconds.*

> The dot went hollow. After 60 seconds the row is gone. No manual cleanup, no cron piling up work — rows expire on their own and a lightweight sweep removes the residue. The table always returns to a predictable size. For hosts, that's the difference between a feature that degrades over time and one that stays constant.

```bash
wp presence demo --cleanup
```

> Demo users gone.

```bash
wp presence summary
```

> One user. The system is back at rest.

---

## ACT 5 — Why This Architecture (1:45–2:00)

> One dedicated table that never touches `wp_options`. Rows that delete themselves. The Heartbeat API that WordPress already ships. No external services.

> For hosts, this is a presence system that costs the same at 10 users and 10,000 — bounded storage, predictable queries, zero new infrastructure. It scales because it was designed to stay small.

---

## Quick Reference — Key Commands

| Command | Purpose |
|---|---|
| `wp presence summary` | Site-wide overview |
| `wp presence list <room>` | Entries in a room |
| `wp presence set <room>` | Manual entry |
| `wp presence demo 5 --keep-alive` | Seed + sustain demo users |
| `wp presence demo --cleanup` | Remove demo users |
| `GET /wp-presence/v1/presence?room=...` | REST endpoint |
| `GET /wp-presence/v1/presence/rooms` | All active rooms |
| `/?presence-db=1` | Live table viewer |
