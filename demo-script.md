# Presence API — Demo Script

> **Setup:** Fresh WordPress install, plugin activated, debugger hidden via Screen Options.
> **Browser:** Dashboard (logged in as admin). Terminal visible alongside.

---

## SHOT 1 — Empty state

**[Screen: Dashboard, widgets visible]**

> This is a feature plugin for a dedicated presence table in WordPress. One table, isolated from the rest of the database.

```bash
wp presence summary
```

---

## SHOT 2 — First heartbeat

**[Refresh Dashboard]**

*Pause — let the viewer see the Who's Online widget update.*

> Refreshing the dashboard fires a Heartbeat ping. That upserts a row. If I stop pinging, the row expires in 60 seconds.

```bash
wp presence list admin/online --format=table
```

---

## SHOT 3 — Team arrives

```bash
wp presence demo 5 --keep-alive
```

**[Refresh Dashboard]**

*Pause — let the viewer see widgets fill in, admin bar update.*

> Five users. Avatars, screen labels, active indicators.

**[Click the admin bar online indicator to show the dropdown.]**

> Users grouped by "On this page" and "Elsewhere" — with screen labels and post titles.

---

## SHOT 4 — Post editing

**[Navigate to Posts list. Show the Editors column.]**

**[Open a post in the editor. Return to Dashboard.]**

> Opening a post creates a second entry in that post's room. The Active Posts widget picks it up.

---

## SHOT 5 — Entropy

**[Ctrl+C the keep-alive. Wait ~30 seconds.]**

> Dots go hollow — idle. After 60 seconds, gone. The table cleans itself.

```bash
wp presence demo --cleanup
wp presence summary
```

---

## SHOT 6 — Close

> One table. Bounded by active users, not history.

*Hold 3 seconds. End.*

---

## Quick Reference

| Command | Purpose |
|---|---|
| `wp presence summary` | Site-wide overview |
| `wp presence list <room>` | Entries in a room |
| `wp presence set <room>` | Manual entry |
| `wp presence demo 5 --keep-alive` | Seed + sustain demo users |
| `wp presence demo --cleanup` | Remove demo users |
| `GET /wp-presence/v1/presence?room=...` | REST endpoint |
| `GET /wp-presence/v1/presence/rooms` | All active rooms |
