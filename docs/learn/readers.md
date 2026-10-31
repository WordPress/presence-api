# Readers and signals

Reading is cheap and always filtered by expiry, and change is announced in layers, from a counter to a full callback.

## Read

| Signal | What it tells you | Where |
| --- | --- | --- |
| Per-request cache | A room is read once per request until the table changes | `wp_presence_cached_rows()` in [`includes/presence.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/presence.php) |
| Room version | What others see has changed | `wp_get_presence_room_versions()`, kept in the `_version` row |
| Next expiry | When the room will next look different on its own | `wp_get_presence_room_next_expiry()` |
| Online hash | Whether anyone in `admin/online` arrived, left or moved | `wp_presence_online_hash_heartbeat_received()` in [`includes/heartbeat.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/heartbeat.php) |
| PHP actions | A row was written or removed, or a room reads differently | [`set_presence`](https://github.com/WordPress/presence-api#set_presence), [`removed_presence`](https://github.com/WordPress/presence-api#removed_presence), [`wp_presence_room_changed`](https://github.com/WordPress/presence-api#wp_presence_room_changed) |
| Collaboration actions | A post room went from one editor to two, or back | [`wp_presence_collaboration_started`](https://github.com/WordPress/presence-api#wp_presence_collaboration_started) and `_ended` |

The room version moves when someone arrives, changes their state or leaves. A tick that only restamps a row does not move it, and neither does a row expiring.

After a run of ticks with an unchanged online hash, the ping widens the Heartbeat interval, staying under the TTL, and a new hash snaps it back.

## Common mistake

A row expiring is not an event. Nothing runs at that moment, and readers find out the next time they read, which is why the next expiry is exposed beside the version.

## Check yourself

<details>
<summary>Renaming a post changes no row. How do open tabs learn the new title?</summary>

The admin bar reads titles live, so the next read shows it, and `wp_presence_room_changed` fires for the post's room and `admin/online` so a push transport can tell tabs to read now.

</details>
