# Why a table

Knowing who is where takes a write every time someone moves or a Heartbeat ticks, so those writes need a home that clears nothing else.

## Read

- [#64696](https://core.trac.wordpress.org/ticket/64696) on Trac, where real-time collaboration writes in post meta made cached post queries stale across a site.
- [#548](https://github.com/WordPress/presence-api/issues/548) and [#836](https://github.com/WordPress/presence-api/issues/836), which measured the same effect for post locks.
- The top of the [README](https://github.com/WordPress/presence-api#readme): no object cache, no WebSockets and no extra services, so a table with a TTL is the only moving part.

## Common mistake

A transient or an option looks simpler, but it lands in the options table, or in the object cache on a host that has one. The plugin cannot count on that cache, and in post meta every write marks cached post queries as stale.

## Check yourself

<details>
<summary>Why do post locks live in the presence table too?</summary>

Core refreshes `_edit_lock` on every Heartbeat tick from an open editor, and as post meta each refresh made cached post queries stale. In the presence table the same refresh touches nothing else.

</details>
