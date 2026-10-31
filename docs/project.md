# Project

Each brief below is a piece of work to do on a local site, with a list to check it against when you are done.

## Set up

Run a local site with `npx wp-env start`, as in [Run locally](https://github.com/WordPress/presence-api#run-locally). It comes with the debugger: click the heart in the admin bar to see every row in the rooms you are in.

## Brief 1: a custom room

Show who else has a screen open that is not a post, such as a plugin's settings page or an order.

Builds on [Rows and rooms](rows-and-rooms.md), [Writers](writers.md) and [Heartbeat and tabs](heartbeat-and-tabs.md). Start from [Custom rooms](https://github.com/WordPress/presence-api#custom-rooms) in the README.

Done when:

- Two users, in two browsers, each see the other's name on the screen.
- Closing one browser removes that name from the other within 150 seconds.
- `wp presence list` on your room shows one row per person, under your plugin's own prefix.

## Brief 2: a push transport

Refresh a tab the moment a row changes, instead of at the next Heartbeat tick.

Builds on [Readers](readers.md) and [Push transports](push-transports.md).

Done when:

- While your filter returns `false` from `presence-api.keepalive`, the `date_gmt` of your `user-{id}` row stops moving unless your code writes it.
- Your code writes that row with the state `wp_presence_admin_state()` builds.
- A listener on `set_presence` makes open tabs fire `presence-api.roomChanged`, and the admin bar updates without waiting for a tick.

## Brief 3: a contribution

Pick an open issue labelled [Good First Issue](https://github.com/WordPress/presence-api/issues?q=is%3Aopen+label%3A%22Good+First+Issue%22), follow [CONTRIBUTING](https://github.com/WordPress/presence-api/blob/main/.github/CONTRIBUTING.md), and open a pull request.

Done when:

- The pull request passes every check.
- A maintainer has reviewed it.
