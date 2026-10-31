# Edges

Where the same rows and rules bend.

| Edge | What changes | Read |
| --- | --- | --- |
| Multisite | Each site keeps its own table, and a network summary table is refreshed whenever a site's `admin/online` room changes | [Network](https://github.com/WordPress/presence-api#network) in the README |
| Privacy | Rows are personal data, so they join core's exporter and eraser, and reserved rows stay out of exports | [`includes/privacy.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/privacy.php) |
| Site Health | Warns when Heartbeat is missing or its interval is too long for the TTL | [`includes/site-health.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/site-health.php) |
| Recording off | `wp_set_presence()` refuses every row except reserved ones, so post locks keep working | `wp presence recording set off` |
| SQLite | An unchanged write counts as a change, so `set_presence` fires where MySQL would skip it | [`set_presence`](https://github.com/WordPress/presence-api#set_presence) |
| Core adoption | Every function sits behind `function_exists()`, so core's copy would win | [#805](https://github.com/WordPress/presence-api/pull/805), [#859](https://github.com/WordPress/presence-api/issues/859) |

## Try

```
npx wp-env run cli wp presence recording set off
```

Open a post in the editor, then run `wp presence list postType/post:{id}` with its ID: no `editor-` row, but the `_lock` row is there. Set it back with `wp presence recording set on`.

## Common mistake

Recording off does not switch off post locks. They are reserved rows, written even with recording off, and only the post locks switch moves them back to post meta.

## Check yourself

<details>
<summary>If core shipped <code>wp_set_presence()</code> tomorrow, what would this plugin do?</summary>

Step aside for that function: its own copy is defined only when the name is free, so core's runs instead.

</details>
