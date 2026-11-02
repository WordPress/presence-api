# Writers

Several things save rows: an open tab checking in, a page loading, someone logging in, an AI agent saving a post. Each keeps its own rows alive by saving them again.

## How it works

```mermaid
flowchart LR
	accTitle: What writes and removes rows
	accDescr: Page loads, Heartbeat ticks, login, post locks, agent saves, push transports and WP-CLI write rows to wp_presence. Logout and leaving a page remove them.
	W["Page loads<br/>Heartbeat ticks<br/>logins, post locks<br/>agent saves, push transports"] -->|write and renew| T[("wp_presence")]
	T -.->|removed by| O["Logout or<br/>leaving a page"]
```

| Writer | Rows |
| --- | --- |
| Page load | `user-{id}`, and `editor-{id}` on a post's edit screen |
| Heartbeat tick | The same rows, renewed |
| Login | `user-{id}`, with the screen `login` |
| [Post lock](post-locks.md) | `_lock` in the post room |
| [Agent save](ai-agents.md) | `agent-{id}` in the post room |
| [Push transport](push-transports.md) | `user-{id}` and `editor-{id}`, in place of Heartbeat |
| WP-CLI | Any row, `cli-{id}` by default |

Rows are also removed when a tab leaves its page, when the user is deleted, and when the user logs out, which keeps only `_lock`.

A write that changes nothing is skipped while the row was stamped within the last 30 seconds, so frequent ticks do not rewrite an unchanged row.

<sub>Source: [`heartbeat.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/heartbeat.php) · [`lifecycle.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/lifecycle.php) · [`presence-ping.js`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/assets/js/presence-ping.js)</sub>

## Check your understanding

<details>
<summary>Which rows does logging out remove, and which does it keep?</summary>

It removes every row the user wrote except `_lock`, so a post lock outlasts the logout.

</details>
