# The row

One row is one client in one room, written as an upsert and present until it expires.

## Read

- The `CREATE TABLE` in [`includes/schema.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/schema.php): a unique key on `room` and `client_id`, `data` as JSON, and `date_gmt` and `expires_gmt`.
- `wp_set_presence()` and `wp_get_presence()` in [`includes/presence.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/presence.php).

Three numbers hold it together:

| Number | What it is |
| --- | --- |
| 150 seconds | The default TTL, `WP_PRESENCE_DEFAULT_TTL` |
| 120 seconds | How often core's Heartbeat ticks in a tab without focus |
| 1 minute | How often cron deletes expired rows |

The TTL outlasts the slowest normal tick, so a person on one screen never drops out between ticks. Reads already skip expired rows, so the cron only keeps the table small.

## Try

```
npx wp-env run cli wp presence set admin/online cli-1 --user=1
npx wp-env run cli wp presence list admin/online
```

List again after two and a half minutes and the row is gone from the list.

## Common mistake

Nothing deletes a row when someone leaves without saying so. It stops being written, and once its expiry passes every read leaves it out.

## Check yourself

<details>
<summary>A site filters the TTL down to 60 seconds. What happens to someone reading in a window that is open but not focused?</summary>

That tab ticks every 120 seconds, so its row expires between ticks and they drop in and out of the room. Site Health warns when the Heartbeat interval is too long for the TTL.

</details>
