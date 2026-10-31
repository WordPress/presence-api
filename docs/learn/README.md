# Learn presence

Presence is a set of rows in one table, each keyed by its room and client and counted as present until it expires. Writers keep their rows alive by writing them again, readers skip the expired ones, and nobody has to say goodbye.

| Writers → | `wp_presence` | → Readers |
| --- | --- | --- |
| A Heartbeat tick, a page load, login, a relay, an agent | One row per room and client, live until `expires_gmt` | The admin bar, widgets, REST, WP-CLI, hooks |

Every lesson adds a writer, adds a reader, or explains a rule about rows. When something in the code is confusing, ask which of the three it is.

1. [See it](see-it.md)
2. [Why a table](why-a-table.md)
3. [The row](the-row.md)
4. [Rooms and client IDs](rooms.md)
5. [Writers](writers.md)
6. [Readers and signals](readers.md)
7. [The browser](browser.md)
8. [Features are consumers](features.md)
9. [Edges](edges.md)
10. [Capstone](capstone.md)

## Words

| Word | Meaning |
| --- | --- |
| Room | A string naming a place, such as `admin/online` or `postType/post:42` |
| Client ID | Whose row it is within a room, such as `user-1` |
| TTL | How long a row counts as present without a new write, 150 seconds by default |
| Writer | Anything that writes rows: a Heartbeat tick, a page load, a relay, an agent |
| Reader | Anything that turns rows into something people see or code acts on |
| Leader | The one tab per screen that sends the ping for all of them |
| Live surface | Markup the server sends back on a tick, such as the admin bar node |

The lessons use a local site from `npx wp-env start` (see [Run locally](https://github.com/WordPress/presence-api#run-locally)), which also activates Presence Scenes and the debugger.
