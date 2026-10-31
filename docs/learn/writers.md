# Writers

Every row has one kind of writer that keeps it alive, and knowing which one explains when it appears and when it lapses.

## Read

| Writer | Rows | Where |
| --- | --- | --- |
| A Heartbeat tick | `user-{id}`, and `editor-{id}` in the editor | The two `*_heartbeat_received()` handlers in [`includes/heartbeat.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/heartbeat.php) |
| Page render | The same rows, before the first tick | `wp_presence_enqueue_heartbeat_ping()` in the same file |
| Login and logout | Arrives in `admin/online`, then leaves everywhere | `wp_presence_on_login()` and `wp_presence_on_logout()` |
| Leaving a page | Removes the tab's rows | The `pagehide` handler in [`presence-ping.js`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/assets/js/presence-ping.js) |
| Post locks | `_lock`, which holds `_edit_lock` | [`includes/post-lock-bridge.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/post-lock-bridge.php) |
| An agent's save | `agent-{id}` in the post's room | [Agents](https://github.com/WordPress/presence-api#agents) in the README |
| A push transport | `user-{id}` and `editor-{id}`, in place of Heartbeat | [`presence-api.keepalive`](https://github.com/WordPress/presence-api#presence-apikeepalive) |

Two rules shape the Heartbeat writer: a hidden tab sends nothing, so its rows lapse, and only one tab per screen sends the ping.

## Try

Open the dashboard in two tabs, watch the Network panel in each, and only one sends `presence-ping` in its Heartbeat request. Hide that tab and the other takes over.

## Common mistake

Heartbeat is not the presence system, only its busiest writer. A relay, an agent or WP-CLI writes the same table, and readers cannot tell them apart except by prefix.

## Check yourself

<details>
<summary>A push transport holds someone's rows. What must it do when their last connection closes?</summary>

Remove the rows or let them lapse soon after, since `user-{id}` and `editor-{id}` are one per person and no Heartbeat will renew or remove them.

</details>
