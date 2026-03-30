# Presence API — Demo Script

> **Runtime target:** ~1:45
> **Setup:** Fresh WordPress install, plugin activated, WP_DEBUG on.
> **Browser:** Dashboard (logged in as admin). Terminal visible alongside.

---

## SHOT 1 — Empty state (0:00–0:15)

**[Screen: Dashboard, widgets visible]**

> This is a feature plugin for a dedicated presence table in WordPress. One table, isolated from the rest of the database. Let me show you what it does.

**Terminal:**

```bash
wp presence summary
```

> Empty. Let's wake it up.

---

## SHOT 2 — First heartbeat (0:10–0:25)

**[Refresh Dashboard]**

*Pause 3 seconds — let the viewer see the entry appear in the debugger.*

> Refreshing the dashboard fires a Heartbeat ping. That upserts a row. If I stop pinging, the row expires in 60 seconds.

```bash
wp presence list admin/online --format=table
```

*Pause — let the viewer read the output.*

---

## SHOT 3 — Team arrives (0:25–0:50)

> Let's add a team.

```bash
wp presence demo 5 --keep-alive
```

**[Refresh Dashboard]**

*Pause 5 seconds — let the viewer see widgets fill in, admin bar update.*

> Five users. Avatars, screen labels, active indicators. All from the same small table.

---

## SHOT 4 — Post editing (0:50–1:10)

**[Open a post in the editor. Return to Dashboard.]**

> Opening a post creates a second entry in that post's room. The Active Posts widget picks it up.

*Pause — let the viewer see it.*

---

## SHOT 5 — Entropy (1:10–1:35)

**[Ctrl+C the keep-alive in terminal. Wait 15 seconds.]**

> I stopped refreshing the demo users. Watch the dots.

*Let the idle transition happen on screen. Pause.*

> Hollow — idle. After 60 seconds, gone. The table cleans itself.

```bash
wp presence demo --cleanup
wp presence summary
```

---

## SHOT 6 — Close (1:35–1:45)

> One table. Bounded by active users, not history. Same cost at 10 users or 10,000.

*Dashboard showing just you. Hold 3 seconds. End.*

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
