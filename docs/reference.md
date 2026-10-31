# Reference

Every public function and documented hook in Presence API, generated from their docblocks by `scripts/build-reference.php`.

## PHP functions

### `wp_can_access_presence_room()`

`wp_can_access_presence_room( $room, $user_id = 0 )`

Checks if a user can access a presence room.

- `$room` (`string`): The room identifier.
- `$user_id` (`int`): Optional. The user ID. Default 0 (current user).

Since 0.1.1. Source: [`plugins/presence-api/includes/presence.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/presence.php)

### `wp_get_presence()`

`wp_get_presence( $room, $args = array() )`

Gets all present clients in a room, filtered by TTL.

- `$room` (`string`): The room identifier.
- `$args` (`array|string`): Optional. Array or string of arguments for reading the room.
  - `'timeout'` (`int|null`): Timeout in seconds. Default null, each row counts until its own expiry.
  - `'client_prefix'` (`string`): Only return clients whose client_id starts with this. Default empty.

Since 0.1.1. Source: [`plugins/presence-api/includes/presence.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/presence.php)

### `wp_get_presence_by_room_prefix()`

`wp_get_presence_by_room_prefix( $prefix, $timeout = null )`

Gets all present clients in every room whose identifier starts with a prefix.

- `$prefix` (`string`): The room prefix to match, matched literally (e.g., 'postType/').
- `$timeout` (`int|null`): Optional. Timeout in seconds. Default null, each row counts until its own expiry.

Since 0.1.1. Source: [`plugins/presence-api/includes/presence.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/presence.php)

### `wp_get_presence_room_next_expiry()`

`wp_get_presence_room_next_expiry( $rooms )`

Returns when the earliest live row in each room expires.

- `$rooms` (`array|string`): Array of room identifiers, or a single room.

Since 0.18.0. Source: [`plugins/presence-api/includes/presence.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/presence.php)

### `wp_get_presence_room_versions()`

`wp_get_presence_room_versions( $rooms )`

Returns the version counters for one or more rooms.

- `$rooms` (`array|string`): Array of room identifiers, or a single room.

Since 0.18.0. Source: [`plugins/presence-api/includes/presence.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/presence.php)

### `wp_presence_admin_room()`

`wp_presence_admin_room()`

Returns the presence room identifier for the admin "who's online" list.

Since 0.1.14. Source: [`plugins/presence-api/includes/presence.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/presence.php)

### `wp_presence_admin_state()`

`wp_presence_admin_state( $screen, $args = array() )`

Builds the state stored on a user's entry in the admin room, shared so its writers cannot drift apart.

- `$screen` (`string`): The screen ID, or 'front' on the front end.
- `$args` (`array|string`): Optional. Array or string of arguments.
  - `'post_id'` (`int`): The post edited, for its status, or viewed on the front end. Default 0.
  - `'title'` (`string`): The page title. Default empty.
  - `'object_id'` (`int`): The comment, user or term the screen edits. Default 0.
  - `'user_id'` (`int`): The user whose capabilities decide what is stored. Default 0, the current user.

Since 0.20.0. Source: [`plugins/presence-api/includes/heartbeat.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/heartbeat.php)

### `wp_presence_check_collaboration_threshold()`

`wp_presence_check_collaboration_threshold( $room, $rows = null )`

Checks if the collaboration threshold has been crossed and fires appropriate actions.

- `$room` (`string`): The presence room identifier.
- `$rows` (`array|null`): Optional. The room's rows, as returned by

Since 0.1.21. Source: [`plugins/presence-api/includes/heartbeat.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/heartbeat.php)

### `wp_presence_count_editors()`

`wp_presence_count_editors( $entries )`

Counts the editor entries in a set of presence entries.

- `$entries` (`array`): Presence entries, as returned by wp_get_presence().

Since 0.6.0. Source: [`plugins/presence-api/includes/heartbeat.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/heartbeat.php)

### `wp_presence_editor_state()`

`wp_presence_editor_state( $screen_id, $locked )`

Builds the state stored on a user's entry in a post room.

- `$screen_id` (`string`): The screen ID.
- `$locked` (`bool`): Whether this write carries a post lock refresh.

Since 0.1.20. Source: [`plugins/presence-api/includes/heartbeat.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/heartbeat.php)

### `wp_presence_exchange()`

`wp_presence_exchange( $room, $client_id, $state, $args = array() )`

Upserts a client's presence state and returns the room as it stands afterwards.

- `$room` (`string`): The room identifier.
- `$client_id` (`string`): The client identifier.
- `$state` (`array`): The presence state data.
- `$args` (`array|string`): Optional. Array or string of arguments for writing the row and reading the room.
  - `'user_id'` (`int`): The user ID. Default 0.
  - `'timeout'` (`int|null`): Timeout in seconds. Default null, each row counts until its own expiry.
  - `'client_prefix'` (`string`): Only return clients whose client_id starts with this. Default empty.
  - `'wp_error'` (`bool`): Whether to return the write's WP_Error instead of reading the room

Since 0.8.0. Source: [`plugins/presence-api/includes/presence.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/presence.php)

### `wp_presence_get_entry_screen()`

`wp_presence_get_entry_screen( $entry )`

Returns the screen an entry's user is on, if the current user may see it.

- `$entry` (`object`): Presence entry from wp_get_presence().

Since 0.9.0. Source: [`plugins/presence-api/includes/presence.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/presence.php)

### `wp_presence_get_user_color()`

`wp_presence_get_user_color( $user_id )`

Returns the color the block editor gives a user in a post room.

- `$user_id` (`int`): User ID.

Since 0.9.0. Source: [`plugins/presence-api/includes/presence.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/presence.php)

### `wp_presence_is_agent_user()`

`wp_presence_is_agent_user( $user_id )`

Whether a presence row's user is an AI agent rather than a person.

- `$user_id` (`int`): The user ID.

Since 0.9.0. Source: [`plugins/presence-api/includes/presence.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/presence.php)

### `wp_presence_is_available()`

`wp_presence_is_available()`

Whether presence can be read and written on this site.

Since 0.6.0. Source: [`plugins/presence-api/includes/presence.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/presence.php)

### `wp_presence_leave()`

`wp_presence_leave( $room, $client_id, $args = array() )`

Removes a client from a room and returns the room as it stands afterwards.

- `$room` (`string`): The room identifier.
- `$client_id` (`string`): The client identifier.
- `$args` (`array|string`): Optional. Array or string of arguments for reading the room, as wp_get_presence() takes them.
  - `'timeout'` (`int|null`): Timeout in seconds. Default null, each row counts until its own expiry.
  - `'client_prefix'` (`string`): Only return clients whose client_id starts with this. Default empty.

Since 0.8.0. Source: [`plugins/presence-api/includes/presence.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/presence.php)

### `wp_presence_parse_room()`

`wp_presence_parse_room( $room )`

Parses a post room identifier.

- `$room` (`string`): The room identifier.

Since 0.1.11. Source: [`plugins/presence-api/includes/presence.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/presence.php)

### `wp_presence_post_room()`

`wp_presence_post_room( $post )`

Returns the presence room identifier for a given post.

- `$post` (`int|WP_Post`): The post ID or post object.

Since 0.1.1. Source: [`plugins/presence-api/includes/presence.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/presence.php)

### `wp_presence_recording_enabled()`

`wp_presence_recording_enabled()`

Whether presence is recorded on this site.

Since 0.3.0. Source: [`plugins/presence-api/includes/presence.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/presence.php)

### `wp_presence_room_changed()`

`wp_presence_room_changed( $room )`

Tells listeners a room reads differently although no row was written or removed.

- `$room` (`string`): The room identifier.

Since 0.20.0. Source: [`plugins/presence-api/includes/presence.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/presence.php)

### `wp_presence_session_hash()`

`wp_presence_session_hash( $room, $client_id = '' )`

Returns which browser the current request comes from, as a per-room hash of its login session that every tab of that login shares.

- `$room` (`string`): The room identifier.
- `$client_id` (`string`): Optional. The client the hash is for. Default empty.

Since 0.18.0. Source: [`plugins/presence-api/includes/presence.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/presence.php)

### `wp_remove_presence()`

`wp_remove_presence( $room, $client_id )`

Removes a client from a room.

- `$room` (`string`): The room identifier.
- `$client_id` (`string`): The client identifier.

Since 0.1.1. Source: [`plugins/presence-api/includes/presence.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/presence.php)

### `wp_remove_user_presence()`

`wp_remove_user_presence( $user_id, $args = array() )`

Removes all presence entries for a given user across all rooms.

- `$user_id` (`int`): The user ID.
- `$args` (`array|string`): Optional. Array or string of arguments for the removal.
  - `'skip_reserved'` (`bool`): Whether to leave reserved rows, such as post locks, in place. Default false.

Since 0.1.1. Source: [`plugins/presence-api/includes/presence.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/presence.php)

### `wp_set_presence()`

`wp_set_presence( $room, $client_id, $state, $args = array() )`

Upserts a client's presence state in a room.

- `$room` (`string`): The room identifier.
- `$client_id` (`string`): The client identifier.
- `$state` (`array`): The presence state data.
- `$args` (`array|string`): Optional. Array or string of arguments for writing the row.
  - `'user_id'` (`int`): The user ID. Default 0.
  - `'date_gmt'` (`string|null`): The GMT timestamp to stamp the row with, as 'Y-m-d H:i:s'
  - `'expires_in'` (`int|null`): Seconds from `date_gmt` that the row counts as present, for a
  - `'wp_error'` (`bool`): Whether to return a WP_Error rather than false when the write

Since 0.1.1. Source: [`plugins/presence-api/includes/presence.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/presence.php)

## PHP hooks

### `removed_presence`

Action.

Fires after a client's presence row is removed from a room.

- `$room` (`string`): The room identifier.
- `$client_id` (`string`): The client identifier.

Since 0.15.0. Source: [`plugins/presence-api/includes/presence.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/presence.php)

### `removed_user_presence`

Action.

Fires after all of a user's presence rows are removed, across every room.

- `$user_id` (`int`): The user ID.

Since 0.15.0. Source: [`plugins/presence-api/includes/presence.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/presence.php)

### `set_presence`

Action.

Fires after a client's presence row is written to a room.

- `$room` (`string`): The room identifier.
- `$client_id` (`string`): The client identifier.
- `$state` (`array`): The presence state data.
- `$user_id` (`int`): The user ID, 0 when the row has no user.

Since 0.15.0. Source: [`plugins/presence-api/includes/presence.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/presence.php)

### `wp_check_post_lock_window`

Filter.

This filter is documented in wp-admin/includes/ajax-actions.php */

Source: [`plugins/presence-api/includes/post-lock-bridge.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/post-lock-bridge.php)

### `wp_presence_admin_room_changed`

Action.

Fires after a write that may have changed who's online on this site.

Since 0.2.0. Source: [`plugins/presence-api/includes/presence.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/presence.php)

### `wp_presence_cleanup_batch_size`

Filter.

This filter is documented in includes/presence.php */

Source: [`plugins/presence-api/includes/network-functions.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/network-functions.php)

### `wp_presence_cleanup_max_passes`

Filter.

This filter is documented in includes/presence.php */

Source: [`plugins/presence-api/includes/network-functions.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/network-functions.php)

### `wp_presence_collaboration_ended`

Action.

Fires when collaboration ends (2+ to 1 editor).

- `$room` (`string`): The presence room identifier.
- `$entries` (`array`): The current presence entries.

Since 0.1.21. Source: [`plugins/presence-api/includes/heartbeat.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/heartbeat.php)

### `wp_presence_collaboration_started`

Action.

Fires when collaboration starts (1 to 2+ editors).

- `$room` (`string`): The presence room identifier.
- `$entries` (`array`): The current presence entries.

Since 0.1.21. Source: [`plugins/presence-api/includes/heartbeat.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/heartbeat.php)

### `wp_presence_current_screen_key`

Filter.

Filters the screen key used to track stale-screen state.

- `$key` (`string`): Computed screen key, or '' when none applies.
- `$screen` (`WP_Screen`): Current screen.

Since 0.1.3. Source: [`plugins/presence-api/includes/screen-revisions.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/screen-revisions.php)

### `wp_presence_default_ttl`

Filter.

Filters the presence TTL (time-to-live) used when a caller names no window.

- `$timeout` (`int`): The timeout in seconds. Default WP_PRESENCE_DEFAULT_TTL (150).

Since 0.1.1. Source: [`plugins/presence-api/includes/presence.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/presence.php)

### `wp_presence_editor_state`

Filter.

Filters the editor presence state before it's saved.

- `$state` (`array`): The presence state data.
- `$post_id` (`int`): The post ID being edited.
- `$user_id` (`int`): The user ID.

Since 0.1.21. Source: [`plugins/presence-api/includes/heartbeat.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/heartbeat.php)

### `wp_presence_feature_enabled`

Filter.

Filters whether a piece of the plugin is switched on for this site.

- `$enabled` (`bool`): Whether the feature is on. Default is the stored
- `$feature` (`string`): Feature key.

Since 0.15.0. Source: [`plugins/presence-api/includes/features.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/features.php)

### `wp_presence_heartbeat_idle_interval`

Filter.

Filters the interval.

- `$interval` (`int`): Interval in seconds. Default 45.

Since 0.1.24. Source: [`plugins/presence-api/includes/heartbeat.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/heartbeat.php)

### `wp_presence_heartbeat_idle_ticks`

Filter.

Filters the tick count.

- `$ticks` (`int`): Tick count. Default 5.

Since 0.1.24. Source: [`plugins/presence-api/includes/heartbeat.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/heartbeat.php)

### `wp_presence_is_agent_user`

Filter.

Filters whether a user is an AI agent, for labelling its presence rows.

- `$is_agent` (`bool`): Whether the user is an agent. Default false.
- `$user_id` (`int`): The user ID.

Since 0.9.0. Source: [`plugins/presence-api/includes/presence.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/presence.php)

### `wp_presence_max_expires_in`

Filter.

Filters the longest window a writer may ask for through `$expires_in`.

- `$max` (`int`): Seconds. Default HOUR_IN_SECONDS.

Since 0.7.0. Source: [`plugins/presence-api/includes/presence.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/presence.php)

### `wp_presence_network_aggregation_enabled`

Filter.

Filters whether network-wide presence aggregation runs.

- `$enabled` (`bool`): Default false above wp_is_large_network().

Since 0.2.0. Source: [`plugins/presence-api/includes/network-functions.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/network-functions.php)

### `wp_presence_network_capability`

Filter.

Filters the capability required to see network-wide presence data.

- `$capability` (`string`): Default 'manage_network'.

Since 0.2.0. Source: [`plugins/presence-api/includes/network-functions.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/network-functions.php)

### `wp_presence_network_recording_enabled`

Filter.

Filters whether presence is recorded anywhere on this network.

- `$enabled` (`bool`): Whether to record presence. Default is the

Since 0.3.0. Source: [`plugins/presence-api/includes/presence.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/presence.php)

### `wp_presence_network_summary_refresh_interval`

Filter.

Filters how stale a site's network summary row may get before a push rewrites it.

- `$interval` (`int`): Seconds. Default is the clamp maximum.

Since 0.2.0. Source: [`plugins/presence-api/includes/network-functions.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/network-functions.php)

### `wp_presence_recording_enabled`

Filter.

Filters whether presence is recorded on this site.

- `$enabled` (`bool`): Whether to record presence. Default is the

Since 0.3.0. Source: [`plugins/presence-api/includes/presence.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/presence.php)

### `wp_presence_room_changed`

Action.

Fires when a room reads differently without a row being written or removed, such as when a post someone is editing changes its title or status.

- `$room` (`string`): The room identifier.

Since 0.20.0. Source: [`plugins/presence-api/includes/presence.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/presence.php)

### `wp_presence_screen_revision_bumped`

Action.

Fires after a screen revision is bumped.

- `$screen_key` (`string`): Screen key that was bumped.
- `$revision` (`int`): New revision number.
- `$actor_id` (`int`): User who triggered the bump.

Since 0.1.3. Source: [`plugins/presence-api/includes/screen-revisions.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/screen-revisions.php)

## JavaScript hooks

### `presence-api.liveSurfaces`

Filter.

Filters the surfaces kept current over Heartbeat.

Since 0.11.0. Source: [`plugins/presence-api/assets/js/presence-ping.js`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/assets/js/presence-ping.js)

### `presence-api.roomChanged`

Action to fire.

Fire `presence-api.roomChanged` to refresh presence now instead of on the next Heartbeat tick, such as when a push transport hears a room change.

Since 0.19.0. Source: [`plugins/presence-api/assets/js/presence-ping.js`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/assets/js/presence-ping.js)

### `presence-api.surfaceUpdated`

Action.

Fires after a live surface is swapped for fresh markup.

Since 0.11.0. Source: [`plugins/presence-api/assets/js/presence-ping.js`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/assets/js/presence-ping.js)

### `presence-api.watchingRoom`

Action.

Fires synchronously, before Heartbeat's first tick, so a listener can tell "presence-api isn't here" from "here, no tick yet".

Since 0.6.0. Source: [`plugins/presence-api/assets/js/presence-ping.js`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/assets/js/presence-ping.js)

REST routes and WP-CLI commands are listed in the [README](https://github.com/WordPress/presence-api#rest-api).
