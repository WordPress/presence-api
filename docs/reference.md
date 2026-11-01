# Reference

Every public function and documented hook in Presence API, generated from their docblocks by `scripts/build-reference.php`.

## PHP functions

### `wp_can_access_presence_room()`

Checks if a user can access a presence room.

`wp_can_access_presence_room( $room, $user_id = 0 )`

- `$room` (`string`): The room identifier.
- `$user_id` (`int`): Optional. The user ID. Default 0 (current user).

<sub>Since 0.1.1 · [`presence.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/presence.php)</sub>

### `wp_get_presence()`

Gets all present clients in a room, filtered by TTL.

`wp_get_presence( $room, $args = array() )`

- `$room` (`string`): The room identifier.
- `$args` (`array|string`): Optional. Array or string of arguments for reading the room.
  - `'timeout'` (`int|null`): Timeout in seconds. Default null, each row counts until its own expiry.
  - `'client_prefix'` (`string`): Only return clients whose client_id starts with this. Default empty.

<sub>Since 0.1.1 · [`presence.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/presence.php)</sub>

### `wp_get_presence_by_room_prefix()`

Gets all present clients in every room whose identifier starts with a prefix.

`wp_get_presence_by_room_prefix( $prefix, $timeout = null )`

- `$prefix` (`string`): The room prefix to match, matched literally (e.g., 'postType/').
- `$timeout` (`int|null`): Optional. Timeout in seconds. Default null, each row counts until its own expiry.

<sub>Since 0.1.1 · [`presence.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/presence.php)</sub>

### `wp_get_presence_room_next_expiry()`

Returns when the earliest live row in each room expires.

`wp_get_presence_room_next_expiry( $rooms )`

- `$rooms` (`array|string`): Array of room identifiers, or a single room.

<sub>Since 0.18.0 · [`presence.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/presence.php)</sub>

### `wp_get_presence_room_versions()`

Returns the version counters for one or more rooms.

`wp_get_presence_room_versions( $rooms )`

- `$rooms` (`array|string`): Array of room identifiers, or a single room.

<sub>Since 0.18.0 · [`presence.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/presence.php)</sub>

### `wp_presence_admin_room()`

Returns the presence room identifier for the admin "who's online" list.

`wp_presence_admin_room()`

<sub>Since 0.1.14 · [`presence.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/presence.php)</sub>

### `wp_presence_admin_state()`

Builds the state stored on a user's entry in the admin room, shared so its writers cannot drift apart.

`wp_presence_admin_state( $screen, $args = array() )`

- `$screen` (`string`): The screen ID, or 'front' on the front end.
- `$args` (`array|string`): Optional. Array or string of arguments.
  - `'post_id'` (`int`): The post edited, for its status, or viewed on the front end. Default 0.
  - `'title'` (`string`): The page title. Default empty.
  - `'object_id'` (`int`): The comment, user or term the screen edits. Default 0.
  - `'user_id'` (`int`): The user whose capabilities decide what is stored. Default 0, the current user.

<sub>Since 0.20.0 · [`heartbeat.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/heartbeat.php)</sub>

### `wp_presence_check_collaboration_threshold()`

Checks if the collaboration threshold has been crossed and fires appropriate actions.

`wp_presence_check_collaboration_threshold( $room, $rows = null )`

- `$room` (`string`): The presence room identifier.
- `$rows` (`array|null`): Optional. The room's rows, as returned by

<sub>Since 0.1.21 · [`heartbeat.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/heartbeat.php)</sub>

### `wp_presence_count_editors()`

Counts the editor entries in a set of presence entries.

`wp_presence_count_editors( $entries )`

- `$entries` (`array`): Presence entries, as returned by wp_get_presence().

<sub>Since 0.6.0 · [`heartbeat.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/heartbeat.php)</sub>

### `wp_presence_editor_state()`

Builds the state stored on a user's entry in a post room.

`wp_presence_editor_state( $screen_id, $locked )`

- `$screen_id` (`string`): The screen ID.
- `$locked` (`bool`): Whether this write carries a post lock refresh.

<sub>Since 0.1.20 · [`heartbeat.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/heartbeat.php)</sub>

### `wp_presence_exchange()`

Upserts a client's presence state and returns the room as it stands afterwards.

`wp_presence_exchange( $room, $client_id, $state, $args = array() )`

- `$room` (`string`): The room identifier.
- `$client_id` (`string`): The client identifier.
- `$state` (`array`): The presence state data.
- `$args` (`array|string`): Optional. Array or string of arguments for writing the row and reading the room.
  - `'user_id'` (`int`): The user ID. Default 0.
  - `'timeout'` (`int|null`): Timeout in seconds. Default null, each row counts until its own expiry.
  - `'client_prefix'` (`string`): Only return clients whose client_id starts with this. Default empty.
  - `'wp_error'` (`bool`): Whether to return the write's WP_Error instead of reading the room

<sub>Since 0.8.0 · [`presence.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/presence.php)</sub>

### `wp_presence_get_entry_screen()`

Returns the screen an entry's user is on, if the current user may see it.

`wp_presence_get_entry_screen( $entry )`

- `$entry` (`object`): Presence entry from wp_get_presence().

<sub>Since 0.9.0 · [`presence.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/presence.php)</sub>

### `wp_presence_get_user_color()`

Returns the color the block editor gives a user in a post room.

`wp_presence_get_user_color( $user_id )`

- `$user_id` (`int`): User ID.

<sub>Since 0.9.0 · [`presence.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/presence.php)</sub>

### `wp_presence_is_agent_user()`

Whether a presence row's user is an AI agent rather than a person.

`wp_presence_is_agent_user( $user_id )`

- `$user_id` (`int`): The user ID.

<sub>Since 0.9.0 · [`presence.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/presence.php)</sub>

### `wp_presence_is_available()`

Whether presence can be read and written on this site.

`wp_presence_is_available()`

<sub>Since 0.6.0 · [`presence.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/presence.php)</sub>

### `wp_presence_leave()`

Removes a client from a room and returns the room as it stands afterwards.

`wp_presence_leave( $room, $client_id, $args = array() )`

- `$room` (`string`): The room identifier.
- `$client_id` (`string`): The client identifier.
- `$args` (`array|string`): Optional. Array or string of arguments for reading the room, as wp_get_presence() takes them.
  - `'timeout'` (`int|null`): Timeout in seconds. Default null, each row counts until its own expiry.
  - `'client_prefix'` (`string`): Only return clients whose client_id starts with this. Default empty.

<sub>Since 0.8.0 · [`presence.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/presence.php)</sub>

### `wp_presence_parse_room()`

Parses a post room identifier.

`wp_presence_parse_room( $room )`

- `$room` (`string`): The room identifier.

<sub>Since 0.1.11 · [`presence.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/presence.php)</sub>

### `wp_presence_post_room()`

Returns the presence room identifier for a given post.

`wp_presence_post_room( $post )`

- `$post` (`int|WP_Post`): The post ID or post object.

<sub>Since 0.1.1 · [`presence.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/presence.php)</sub>

### `wp_presence_recording_enabled()`

Whether presence is recorded on this site.

`wp_presence_recording_enabled()`

<sub>Since 0.3.0 · [`presence.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/presence.php)</sub>

### `wp_presence_room_changed()`

Tells listeners a room reads differently although no row was written or removed.

`wp_presence_room_changed( $room )`

- `$room` (`string`): The room identifier.

<sub>Since 0.20.0 · [`presence.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/presence.php)</sub>

### `wp_presence_session_hash()`

Returns which browser the current request comes from, as a per-room hash of its login session that every tab of that login shares.

`wp_presence_session_hash( $room, $client_id = '' )`

- `$room` (`string`): The room identifier.
- `$client_id` (`string`): Optional. The client the hash is for. Default empty.

<sub>Since 0.18.0 · [`presence.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/presence.php)</sub>

### `wp_remove_presence()`

Removes a client from a room.

`wp_remove_presence( $room, $client_id )`

- `$room` (`string`): The room identifier.
- `$client_id` (`string`): The client identifier.

<sub>Since 0.1.1 · [`presence.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/presence.php)</sub>

### `wp_remove_user_presence()`

Removes all presence entries for a given user across all rooms.

`wp_remove_user_presence( $user_id, $args = array() )`

- `$user_id` (`int`): The user ID.
- `$args` (`array|string`): Optional. Array or string of arguments for the removal.
  - `'skip_reserved'` (`bool`): Whether to leave reserved rows, such as post locks, in place. Default false.

<sub>Since 0.1.1 · [`presence.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/presence.php)</sub>

### `wp_set_presence()`

Upserts a client's presence state in a room.

`wp_set_presence( $room, $client_id, $state, $args = array() )`

- `$room` (`string`): The room identifier.
- `$client_id` (`string`): The client identifier.
- `$state` (`array`): The presence state data.
- `$args` (`array|string`): Optional. Array or string of arguments for writing the row.
  - `'user_id'` (`int`): The user ID. Default 0.
  - `'date_gmt'` (`string|null`): The GMT timestamp to stamp the row with, as 'Y-m-d H:i:s'
  - `'expires_in'` (`int|null`): Seconds from `date_gmt` that the row counts as present, for a
  - `'wp_error'` (`bool`): Whether to return a WP_Error rather than false when the write

<sub>Since 0.1.1 · [`presence.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/presence.php)</sub>

## PHP hooks

### `removed_presence`

Fires after a client's presence row is removed from a room.

- `$room` (`string`): The room identifier.
- `$client_id` (`string`): The client identifier.

<sub>Action · since 0.15.0 · [`presence.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/presence.php)</sub>

### `removed_user_presence`

Fires after all of a user's presence rows are removed, across every room.

- `$user_id` (`int`): The user ID.

<sub>Action · since 0.15.0 · [`presence.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/presence.php)</sub>

### `set_presence`

Fires after a client's presence row is written to a room.

- `$room` (`string`): The room identifier.
- `$client_id` (`string`): The client identifier.
- `$state` (`array`): The presence state data.
- `$user_id` (`int`): The user ID, 0 when the row has no user.

<sub>Action · since 0.15.0 · [`presence.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/presence.php)</sub>

### `wp_check_post_lock_window`

This filter is documented in wp-admin/includes/ajax-actions.php */

<sub>Filter · [`post-lock-bridge.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/post-lock-bridge.php)</sub>

### `wp_presence_admin_room_changed`

Fires after a write that may have changed who's online on this site.

<sub>Action · since 0.2.0 · [`presence.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/presence.php)</sub>

### `wp_presence_cleanup_batch_size`

This filter is documented in includes/presence.php */

<sub>Filter · [`network-functions.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/network-functions.php)</sub>

### `wp_presence_cleanup_max_passes`

This filter is documented in includes/presence.php */

<sub>Filter · [`network-functions.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/network-functions.php)</sub>

### `wp_presence_collaboration_ended`

Fires when collaboration ends (2+ to 1 editor).

- `$room` (`string`): The presence room identifier.
- `$entries` (`array`): The current presence entries.

<sub>Action · since 0.1.21 · [`heartbeat.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/heartbeat.php)</sub>

### `wp_presence_collaboration_started`

Fires when collaboration starts (1 to 2+ editors).

- `$room` (`string`): The presence room identifier.
- `$entries` (`array`): The current presence entries.

<sub>Action · since 0.1.21 · [`heartbeat.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/heartbeat.php)</sub>

### `wp_presence_current_screen_key`

Filters the screen key used to track stale-screen state.

- `$key` (`string`): Computed screen key, or '' when none applies.
- `$screen` (`WP_Screen`): Current screen.

<sub>Filter · since 0.1.3 · [`screen-revisions.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/screen-revisions.php)</sub>

### `wp_presence_default_ttl`

Filters the presence TTL (time-to-live) used when a caller names no window.

- `$timeout` (`int`): The timeout in seconds. Default WP_PRESENCE_DEFAULT_TTL (150).

<sub>Filter · since 0.1.1 · [`presence.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/presence.php)</sub>

### `wp_presence_editor_state`

Filters the editor presence state before it's saved.

- `$state` (`array`): The presence state data.
- `$post_id` (`int`): The post ID being edited.
- `$user_id` (`int`): The user ID.

<sub>Filter · since 0.1.21 · [`heartbeat.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/heartbeat.php)</sub>

### `wp_presence_feature_enabled`

Filters whether a piece of the plugin is switched on for this site.

- `$enabled` (`bool`): Whether the feature is on. Default is the stored
- `$feature` (`string`): Feature key.

<sub>Filter · since 0.15.0 · [`features.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/features.php)</sub>

### `wp_presence_heartbeat_idle_interval`

Filters the interval.

- `$interval` (`int`): Interval in seconds. Default 45.

<sub>Filter · since 0.1.24 · [`heartbeat.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/heartbeat.php)</sub>

### `wp_presence_heartbeat_idle_ticks`

Filters the tick count.

- `$ticks` (`int`): Tick count. Default 5.

<sub>Filter · since 0.1.24 · [`heartbeat.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/heartbeat.php)</sub>

### `wp_presence_is_agent_user`

Filters whether a user is an AI agent, for labelling its presence rows.

- `$is_agent` (`bool`): Whether the user is an agent. Default false.
- `$user_id` (`int`): The user ID.

<sub>Filter · since 0.9.0 · [`presence.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/presence.php)</sub>

### `wp_presence_max_expires_in`

Filters the longest window a writer may ask for through `$expires_in`.

- `$max` (`int`): Seconds. Default HOUR_IN_SECONDS.

<sub>Filter · since 0.7.0 · [`presence.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/presence.php)</sub>

### `wp_presence_network_aggregation_enabled`

Filters whether network-wide presence aggregation runs.

- `$enabled` (`bool`): Default false above wp_is_large_network().

<sub>Filter · since 0.2.0 · [`network-functions.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/network-functions.php)</sub>

### `wp_presence_network_capability`

Filters the capability required to see network-wide presence data.

- `$capability` (`string`): Default 'manage_network'.

<sub>Filter · since 0.2.0 · [`network-functions.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/network-functions.php)</sub>

### `wp_presence_network_recording_enabled`

Filters whether presence is recorded anywhere on this network.

- `$enabled` (`bool`): Whether to record presence. Default is the

<sub>Filter · since 0.3.0 · [`presence.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/presence.php)</sub>

### `wp_presence_network_summary_refresh_interval`

Filters how stale a site's network summary row may get before a push rewrites it.

- `$interval` (`int`): Seconds. Default is the clamp maximum.

<sub>Filter · since 0.2.0 · [`network-functions.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/network-functions.php)</sub>

### `wp_presence_recording_enabled`

Filters whether presence is recorded on this site.

- `$enabled` (`bool`): Whether to record presence. Default is the

<sub>Filter · since 0.3.0 · [`presence.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/presence.php)</sub>

### `wp_presence_room_changed`

Fires when a room reads differently without a row being written or removed, such as when a post someone is editing changes its title or status.

- `$room` (`string`): The room identifier.

<sub>Action · since 0.20.0 · [`presence.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/presence.php)</sub>

### `wp_presence_screen_revision_bumped`

Fires after a screen revision is bumped.

- `$screen_key` (`string`): Screen key that was bumped.
- `$revision` (`int`): New revision number.
- `$actor_id` (`int`): User who triggered the bump.

<sub>Action · since 0.1.3 · [`screen-revisions.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/screen-revisions.php)</sub>

## JavaScript hooks

### `presence-api.collaborationEnded`

Fires `presence-api.collaborationStarted` when a second person joins the editor's room, and `presence-api.collaborationEnded` when one is left.

<sub>Action · since 0.6.0 · [`presence-ping.js`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/assets/js/presence-ping.js)</sub>

### `presence-api.collaborationStarted`

Fires `presence-api.collaborationStarted` when a second person joins the editor's room, and `presence-api.collaborationEnded` when one is left.

<sub>Action · since 0.6.0 · [`presence-ping.js`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/assets/js/presence-ping.js)</sub>

### `presence-api.keepalive`

Filters whether this tab keeps its own presence rows alive over Heartbeat.

<sub>Filter · since 0.19.0 · [`presence-ping.js`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/assets/js/presence-ping.js)</sub>

### `presence-api.liveSurfaces`

Filters the surfaces kept current over Heartbeat.

<sub>Filter · since 0.11.0 · [`presence-ping.js`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/assets/js/presence-ping.js)</sub>

### `presence-api.roomChanged`

Fire `presence-api.roomChanged` to refresh presence now instead of on the next Heartbeat tick, such as when a push transport hears a room change.

<sub>Action to fire · since 0.19.0 · [`presence-ping.js`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/assets/js/presence-ping.js)</sub>

### `presence-api.surfaceUpdated`

Fires after a live surface is swapped for fresh markup.

<sub>Action · since 0.11.0 · [`presence-ping.js`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/assets/js/presence-ping.js)</sub>

### `presence-api.watchingRoom`

Fires synchronously, before Heartbeat's first tick, so a listener can tell "presence-api isn't here" from "here, no tick yet".

<sub>Action · since 0.6.0 · [`presence-ping.js`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/assets/js/presence-ping.js)</sub>

REST routes and WP-CLI commands are listed in the [README](https://github.com/WordPress/presence-api#rest-api).
