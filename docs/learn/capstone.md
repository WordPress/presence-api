# Capstone

Build one of these, then explain every row it writes: who writes it, when it lapses, and who reads it.

## A custom room

Give a screen that isn't a post, such as an order or a form entry, a room of its own by following [Custom rooms](https://github.com/WordPress/presence-api#custom-rooms) in the README. Open it as two users in two browsers and watch both rows with `wp presence list`.

Then answer: what happens to the room when one browser's tab is hidden, and why does the recipe use its own Heartbeat key instead of a live surface?

## A tiny relay

Act as a push transport from the browser console of a dashboard tab:

1. Return false from [`presence-api.keepalive`](https://github.com/WordPress/presence-api#presence-apikeepalive), so Heartbeat stops writing your `user-{id}` row.
2. Write that row yourself over the [REST API](https://github.com/WordPress/presence-api#rest-api), with the same state Heartbeat would store.
3. Fire [`presence-api.roomChanged`](https://github.com/WordPress/presence-api#presence-apiroomchanged) and watch the admin bar update in a second browser.

Then answer: what removes your row if you close the tab, and what if your connection drops instead?
