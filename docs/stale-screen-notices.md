# Stale-screen notices

If someone else saves a screen you have open, such as Settings, you see a notice asking you to reload, so you do not overwrite their change.

![The notice someone sees when another person saves the settings screen they have open.](../plugins/presence-api/.wordpress-org/screenshot-4.png)

## How it works

```mermaid
sequenceDiagram
	accTitle: A save marks other open copies of a screen stale
	accDescr: Person A saves Settings > General, which moves the screen's revision. Person B has the same screen open; B's tab sends the screen key on Heartbeat, the server answers with the new revision and who made it, and B's screen shows a notice to reload.
	participant A as Person A
	participant S as Server
	participant B as Person B's tab
	A->>S: Save Settings > General
	S->>S: Revision of options/general moves
	B->>S: Heartbeat with screen key options/general
	S-->>B: New revision, saved by A
	Note over B: "A updated this screen 1 minute ago."<br/>Reload or Dismiss
```

Each watched admin screen has a revision. When someone else saves it, every open copy shows a notice on its next Heartbeat tick.

## Screen keys

| Screen | Key |
| --- | --- |
| A Settings page | `options/general`, or the page's option group |
| A post | `post/{id}` |
| A user | `user-edit/{id}` |
| A term | `term/{taxonomy}/{id}` |
| A comment | `comment/{id}` |
| A network setting | `network/settings`, or `network/site-info/{id}` and similar |

Another screen gets a key from the `wp_presence_current_screen_key` filter.

## Revisions

Classic screens that save with a form post and redirect move their revision on save. A screen that saves in the background, over REST or Ajax, moves it with:

```js
wp.presence.markScreenStale( 'options/my-plugin-settings' );
```

Your own saves never show you a notice, and Dismiss hides it until the next revision. Each key is checked against that screen's own capability, such as `manage_options` for Settings.

Source: [`includes/screen-revisions.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/screen-revisions.php), [`assets/js/stale-screen.js`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/assets/js/stale-screen.js)
