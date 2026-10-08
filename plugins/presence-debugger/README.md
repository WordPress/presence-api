# Presence Debugger

The Presence API's developer tools, in a plugin of their own so releases of
Presence API ship without them. Everything here loads **only under `WP_DEBUG`**.

It needs the Presence API plugin (`Requires Plugins: presence-api`) and adds two
things for people working on presence:

| Tool | What it does |
|---|---|
| Admin-bar debugger | A node in the toolbar showing the next Heartbeat and every client in the rooms the current user is in. Other plugins can add indicators and menu rows through the `wp_presence_debugger_indicators` and `wp_presence_debugger_menu` hooks. |
| DB viewer | `?presence-db=1` on any URL renders the `wp_presence` table — newest first, with each row's age and expiry. Requires `manage_options` and a nonce. |
