# Synced patterns

A synced pattern is a block reused in many posts. While someone edits the pattern itself, anyone using it in a post sees a notice that it is being changed.

![Priya Raman opens a synced pattern in another browser, and a post using it shows a notice until she closes it.](media/synced-patterns.mp4)

## How it works

```mermaid
sequenceDiagram
	accTitle: A synced pattern notice
	accDescr: Person B edits a synced pattern, which writes editor-B in the pattern's room. Person A has a post open that uses the pattern; A's editor reads the pattern's room over REST on each Heartbeat tick and shows a notice naming B.
	participant B as Person B, editing the pattern
	participant T as wp_presence
	participant A as Person A, editing a post
	B->>T: Write editor-B in postType/wp_block:7
	A->>T: Read postType/wp_block:7 over REST, each tick
	T-->>A: editor-B
	Note over A: "B is editing this pattern"
```

- A `core/block` block with a `ref` reads the room `postType/wp_block:{ref}` with `usePresenceUsers()`.
- Every copy of the same pattern in the editor shares one read.
- The person editing the pattern writes their `editor-` row from the pattern's own edit screen or the Site Editor.
- Reading the room requires `edit_post` on the pattern, and the notice cannot be dismissed while they are there.
- Turn it off with Synced patterns on Settings > Presence API.

<sub>Source: [`synced-patterns.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/synced-patterns.php) · [`index.js`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/src/synced-patterns/index.js)</sub>

## Check your understanding

<details>
<summary>Who sees the notice that a synced pattern is being edited?</summary>

Only someone who can edit the pattern, since reading its room requires `edit_post` on it.

</details>
