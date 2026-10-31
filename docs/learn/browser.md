# The browser

One script rides core's Heartbeat, one tab per screen does the asking, and the server answers with finished markup.

## Read

- [`assets/js/presence-ping.js`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/assets/js/presence-ping.js): the ping, the `SURFACES` list and `swapFragment()`.
- [`assets/js/tab-coordinator.js`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/assets/js/tab-coordinator.js): elects one visible tab per screen and replays its answer to the others.
- The JS hooks in the README: [`presence-api.roomChanged`](https://github.com/WordPress/presence-api#presence-apiroomchanged), [`presence-api.keepalive`](https://github.com/WordPress/presence-api#presence-apikeepalive), [`presence-api.liveSurfaces`](https://github.com/WordPress/presence-api#presence-apilivesurfaces) and [`presence-api.surfaceUpdated`](https://github.com/WordPress/presence-api#presence-apisurfaceupdated).

A live surface, such as the admin bar node or the Users list, asks under `presence-fragments` and gets back its HTML. The swap waits while the pointer or focus is inside it, except that the admin bar menu updates while open and waits only for focus.

## Try

In the console of a dashboard tab, fire a refresh and watch the Heartbeat request go out at once:

```js
wp.hooks.doAction( 'presence-api.roomChanged', 'admin/online' );
```

## Common mistake

Not every tab pings. Followers on the same screen get the leader's answer replayed, which is why a surface whose request differs per tab currently sees the leader's answer ([#921](https://github.com/WordPress/presence-api/issues/921)).

## Check yourself

<details>
<summary>Why does the ping skip a hidden tab?</summary>

So the rows of someone who switched away lapse within the TTL, and the room shows who is actually looking.

</details>
