# Rooms and client IDs

A room is a string that names where someone is, and a `client_id` prefix says who owns the row.

## Read

- [Rooms](https://github.com/WordPress/presence-api#rooms) and [Client IDs](https://github.com/WordPress/presence-api#client-ids) in the README.
- `wp_presence_post_room()`, `wp_presence_parse_room()` and `wp_can_access_presence_room()` in [`includes/presence.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/presence.php).

| Room | Who is in it |
| --- | --- |
| `admin/online` | Everyone on an admin screen |
| `postType/{type}:{id}` | Everyone editing that post, in the grammar Gutenberg's sync rooms use |
| Anything else | A plugin's own room, as in [Custom rooms](https://github.com/WordPress/presence-api#custom-rooms) |

| Prefix | Owner |
| --- | --- |
| `user-{id}`, `editor-{id}` | This plugin, one row per person |
| `cli-`, `agent-` | WP-CLI and AI agents |
| `_lock`, `_collab`, `_version` | Bookkeeping rows, never people |
| Your own, such as `gse-` | Any other writer, read back with `client_prefix` |

A post room needs `edit_post` on that post. Any other room lets in anyone who can edit some post type shown in the admin.

## Common mistake

One tab is not one row. Every tab a person has open on the dashboard writes the same `user-{id}` row, so the room counts people, not tabs.

## Check yourself

<details>
<summary>Why do relays read with <code>client_prefix</code>?</summary>

A relay shares the room with this plugin's rows, and `client_prefix` returns only its own, matched in the query.

</details>
