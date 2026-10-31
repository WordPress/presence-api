# Introduction

When several people work on one WordPress site, Presence API shows who else is there: who is logged in, which screen they are on, and which posts they have open.

![The dashboard with 101 people online, the admin bar's presence menu open, and Active Posts listing who is editing each post and page.](../plugins/presence-api/.wordpress-org/screenshot-1.png)

## What you see

- Faces in the admin bar for everyone online, and a menu saying where each person is.
- An Active Posts box on the dashboard, listing each post someone has open.
- An Editors column on the Posts screen, showing who has each post open.
- An Online view on the Users screen.
- A notice when someone else saves a screen you have open, so you do not overwrite their change.

## How it knows

```mermaid
sequenceDiagram
	accTitle: How a browser tab tells the site someone is there
	accDescr: About once a minute, each open tab tells the site which screen the person is on. The site saves a short note saying the person is there, and replies with who else is around. A note that is not renewed stops counting after 150 seconds.
	participant Tab as Your browser tab
	participant Site as Your WordPress site
	Tab->>Site: I am here, on the Dashboard
	Site->>Site: Save a note: you are on the Dashboard
	Site-->>Tab: These people are here too
	Note over Site: A note not renewed within<br/>150 seconds stops counting
```

WordPress already has a built-in check-in called Heartbeat: about once a minute, each open tab sends a small request to the site. Presence API adds which screen you are on. The site saves that as a short note, and replies with who else is around.

A note counts for 150 seconds. Each check-in renews it, so when you close the tab or your connection drops, you disappear from everyone else's screen shortly after, without logging out.

## Words used in these docs

| Word | Meaning |
| --- | --- |
| Row | One saved note: this person is in this place, until this time |
| Room | A place people can be, such as the whole admin or one post |
| Heartbeat | WordPress's built-in check-in from each open tab |
| TTL | How long a row counts without being renewed, 150 seconds by default |
| Writer | Anything that saves rows, such as Heartbeat |
| Reader | Anything that shows rows, such as the admin bar |

## Where to go next

[Rows and rooms](rows-and-rooms.md) starts the technical explanation, and each page under Systems explains one feature from what you see down to the code.
