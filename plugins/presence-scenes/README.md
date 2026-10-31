# Presence Scenes

Plays real users through probable situations, such as two people editing one post, so the Presence API can be watched and checked end to end. It is a separate plugin that needs Presence API, and it calls only these Presence API functions:

| Function | Used for |
|---|---|
| `wp_get_presence()` | Checking an actor shows up where they should |
| `wp_remove_presence()` | Closing an editor |
| `wp_remove_user_presence()` | Logging out |
| `wp_presence_admin_room()`, `wp_presence_post_room()` | Naming the rooms to check |

Actors reach everything else through core's `heartbeat_received` filter, as a browser would.

Gutenberg's collaboration end-to-end tests open a browser for each user and check what the block editor shows them. Scenes play users from the server instead, so one browser can watch several people arrive on admin screens, take over post locks, log out or lose their connection. That makes them useful for demos, for checking a change by hand in the debugger, and for replaying a situation on demand. Assertions about what the editor renders still belong in end-to-end tests.

## Playground

[![Launch Presence Scenes](https://img.shields.io/badge/Launch-3858E9?style=for-the-badge&logo=wordpress&logoColor=white)](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/WordPress/presence-api/main/blueprint-scenes.json)

Plays Editing together on the Posts screen. Steps play on your tab's Heartbeat, so a few land on each tick, and a tab left in the background catches up all at once when you return.

## WP-CLI

`npm run env:start` activates it. Anywhere else, activate it after Presence API.

```
wp presence scene list
wp presence scene run editing-together
wp presence scene stop
```

A run creates a user for each part in the cast, plays each step at its time, then deletes those users and everything they wrote. Runs refuse to overlap, a run whose command died is soon replaced, and actors cannot log in. Only the scenes in `library/`, and in folders a plugin adds, can be played.

`npm run test:scenes` plays every scene and saves a screenshot of the screen each step happened on to `artifacts/screenshots/scenes/`.

## Scene format

```json
{
	"apiVersion": 1,
	"name": "editing-together",
	"title": "Editing together",
	"cast": [ "author", "editor" ],
	"steps": [
		{ "at": 0, "actor": 1, "step": "write", "title": "Launch checklist" },
		{ "at": 5, "actor": 2, "step": "open", "post": "Launch checklist" },
		{ "at": 6, "actor": 2, "step": "checkLocked", "post": "Launch checklist" },
		{ "at": 10, "actor": "cast", "step": "leave" }
	]
}
```

| Key | Value |
|---|---|
| `name` | Lowercase letters, numbers and dashes, matching the file name |
| `title` | Plain text |
| `cast` | Roles, each `subscriber`, `contributor`, `author` or `editor`; `actor` 1 plays the first |
| `steps` | The steps, in the order they play |
| `at` | Seconds from the start, never earlier than the step before |
| `actor` | A part in the cast, or `"cast"` for every part on the steps marked below |
| `post` | The title of a post an earlier `write` created; each `write` needs its own title |

| Step | Fields | Whole cast |
|---|---|---|
| `visit` | `place`: `dashboard`, `posts`, `pages`, `media`, `comments` or `profile` | Yes |
| `write` | `title` | |
| `open` | `post` | |
| `takeOver` | `post` | |
| `type` | `post`, `text` | |
| `close` | `post` | |
| `drop` | | Yes |
| `leave` | | Yes |
| `checkOnline` | | Yes |
| `checkOffline` | | Yes |
| `checkLocked` | `post` | |
| `checkUnlocked` | `post` | |

Step `title` and `text` are plain text too, and `wp_presence_scene_limits()` caps how many roles and steps a scene has, how late a step plays and how long each title and text is.

## Adding a scene

Add a JSON file to `library/` named after its `name`; `npm test` checks every file there, so a new scene needs no test changes.

## Steps and scenes from another plugin

A plugin that keeps presence through its own PHP rather than Heartbeat can play its users through scenes too. It adds a folder of scenes with `wp_presence_scene_libraries`, and steps with `wp_presence_scene_steps`, each played by a callback that runs as the actor and throws to fail the step:

```php
add_filter( 'wp_presence_scene_libraries', function ( $folders ) {
	$folders[] = __DIR__ . '/scenes';
	return $folders;
} );

add_filter( 'wp_presence_scene_steps', function ( $steps ) {
	$steps['startListening'] = array(
		'callback' => function ( WP_Presence_Scene_Actor $actor, array $fields ) {
			my_plugin_share( $fields['text'] ); // $actor is the current user here.
		},
		'fields'   => array( 'text' ),
		'label'    => '%1$s starts listening to %2$s',
	);
	return $steps;
} );
```

A step's name is camelCase and can't replace a bundled one. A field named `post` or `place` means what it does on the bundled steps, and any other field is plain text held to the same limits. A scene file is skipped when it isn't named after its scene, or when an earlier folder already has a scene of that name.
