# Admin screens

These are the places in the WordPress admin where you see who else is around.

![Active Posts goes from all quiet to Maya Chen editing a post, then Theo Okafor joining her, as their rows appear below.](media/admin-screens.mp4)

## How it works

```mermaid
flowchart LR
	accTitle: Which rooms each admin screen reads
	accDescr: The admin bar and the Users list read the admin/online room. The admin bar, the Active Posts widget and the Editors column read the post rooms. Each refreshes from markup in the Heartbeat answer.
	A[("admin/online")] --> B[Admin bar]
	A --> U[Users list Online view]
	P[("postType/* rooms")] --> B
	P --> W[Active Posts widget]
	P --> E[Editors column]
```

| Screen | Shows |
| --- | --- |
| Admin bar | Who is online, who is on this page, and what each person is editing |
| Active Posts | Each post being edited, its editors, and who holds its lock |
| Editors column | Who is editing each post in a post list |
| Users list | An Online view and count |

Each refreshes from markup in the Heartbeat answer, as [live surfaces](heartbeat-and-tabs.md#live-surfaces).

Who sees each screen follows [Access](rows-and-rooms.md#access): a post's title only shows to someone who can edit the post, and another person's screen only to someone with `list_users`. A person whose row has not been written for 75 seconds is shown as idle.

Each has a switch on Settings > Presence API, which removes the screen and leaves the rows unchanged.

<sub>Source: [`admin-bar.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/admin-bar.php) · [`class-wp-presence-widget-active-posts.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/widgets/class-wp-presence-widget-active-posts.php) · [`post-list.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/post-list.php) · [`user-list.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/user-list.php)</sub>

## Check your understanding

<details>
<summary>You switch off the admin bar on Settings > Presence API. Are you still recorded as online?</summary>

Yes. The switch removes the screen, and Heartbeat still writes your row.

</details>
