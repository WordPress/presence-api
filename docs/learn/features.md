# Features are consumers

The admin bar, Active Posts, the Editors column, the Users list, stale-screen notices, synced patterns and post locks all read the same API, and each has its own switch.

## Read

- [Features](https://github.com/WordPress/presence-api#features) in the README, and Settings > Presence API on your local site.
- [`scripts/private-calls.txt`](https://github.com/WordPress/presence-api/blob/main/scripts/private-calls.txt): every place a feature still calls a private function, checked in CI so a new one shows up in review.
- [Capability](https://github.com/WordPress/presence-api#capability): seeing which screen someone is on also needs `list_users`, through `view_presence_location`.

The split exists so each feature could lift out as its own core patch.

## Try

Switch off the admin bar on Settings > Presence API and reload: the faces go, and `wp presence list admin/online` still shows your row, because Heartbeat still writes it.

## Common mistake

A feature is not part of the API. Switching one off changes what people see, and only the post locks switch changes what gets stored, sending locks back to post meta.

## Check yourself

<details>
<summary>Why does a feature's call to a private function go on a list?</summary>

Private functions can change without notice, so each call is a coupling a core patch would have to carry, and the list makes adding one a visible choice.

</details>
