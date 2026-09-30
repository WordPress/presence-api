# Presence Scenes

Plays real users through probable situations, such as two people editing one post, so the Presence API can be watched and checked end to end. It is a separate plugin that needs Presence API. Only the scenes in `library/` can be played.

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
| `cast` | Roles, each `contributor`, `author` or `editor`; `actor` 1 plays the first |
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
