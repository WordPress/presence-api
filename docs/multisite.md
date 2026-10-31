# Multisite

Each site keeps its own `wp_presence` table. A network-wide summary table collects who is online on each site, for Network Admin.

```mermaid
flowchart LR
	accTitle: Network presence collected from each site
	accDescr: When a site's admin/online room changes, the site pushes its online users to a network summary table. Network Admin's Who's Online widget, the Sites and Users screens, and the network REST routes read that table.
	S1[("Site 1<br/>wp_presence")] -->|admin/online changed| N[("presence_network_summary")]
	S2[("Site 2<br/>wp_presence")] -->|admin/online changed| N
	N --> W[Who's Online widget]
	N --> L[Sites and Users screens]
	N --> R[REST network routes]
```

- A write to a site's `admin/online` room fires `wp_presence_admin_room_changed`, which pushes that site's online users to `{base_prefix}presence_network_summary`.
- A push is skipped while the site's online users are unchanged and the last push is recent, about 105 seconds.
- Reads count a site only if its summary was updated within the TTL.
- Collection is on by default and off on a large network, filtered by `wp_presence_network_aggregation_enabled`.
- Network screens and routes require `manage_network` by default.

| Route | Returns |
| --- | --- |
| `GET /wp-presence/v1/presence/network` | Sites with users online, busiest first |
| `GET /wp-presence/v1/presence/network/{blog_id}` | One site's users online |

The switch is Network Admin screens, on the network's settings page.

Source: [`includes/network-functions.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/network-functions.php), [`includes/schema.php`](https://github.com/WordPress/presence-api/blob/main/plugins/presence-api/includes/schema.php)
