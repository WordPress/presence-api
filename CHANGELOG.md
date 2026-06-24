# Changelog

## [0.1.3](https://github.com/WordPress/presence-api/compare/v0.1.2...v0.1.3) (2026-06-24)


### Features

* add 40-user Playground blueprint ([797ca0c](https://github.com/WordPress/presence-api/commit/797ca0c6fb77cec461874f7f2944637538eebd24))
* add 40-user Playground blueprint (down from 100) ([782e282](https://github.com/WordPress/presence-api/commit/782e282d5e39aa15940143d805cb569f97505923))


### Bug Fixes

* resolve merge conflicts with main branch ([afeb72b](https://github.com/WordPress/presence-api/commit/afeb72bd41934991bb603c651069072f00900ee3))


### Dependencies

* **deps-dev:** bump @playwright/test from 1.58.2 to 1.61.0 ([8ac3924](https://github.com/WordPress/presence-api/commit/8ac392486d36127a510d034c7f3f4ba4dd7dd459))
* **deps-dev:** bump @playwright/test from 1.58.2 to 1.61.0 ([0840f51](https://github.com/WordPress/presence-api/commit/0840f51bd6513c1e587ba362b06f90720e839405))
* **deps-dev:** bump @playwright/test from 1.61.0 to 1.61.1 ([7de9a96](https://github.com/WordPress/presence-api/commit/7de9a96290340e01795efe710ca0c11f38f3e11d))
* **deps-dev:** bump @playwright/test from 1.61.0 to 1.61.1 ([fea7439](https://github.com/WordPress/presence-api/commit/fea7439d0ae9b2cbe8c65d897def8ef45e5079e2))
* **deps-dev:** bump @wordpress/e2e-test-utils-playwright ([3069684](https://github.com/WordPress/presence-api/commit/30696843b41a2b0bdc299af650ef8fd286989529))
* **deps-dev:** bump @wordpress/e2e-test-utils-playwright ([8588c9b](https://github.com/WordPress/presence-api/commit/8588c9b42f0d0ed6c610476675b35c484ea56311))
* **deps-dev:** bump @wordpress/e2e-test-utils-playwright from 1.42.0 to 1.48.1 ([8f0563a](https://github.com/WordPress/presence-api/commit/8f0563a70b92dbc3ba0b54ecd5b1f7cee803af7a))
* **deps-dev:** bump @wordpress/e2e-test-utils-playwright from 1.48.1 to 1.49.0 ([41ea0a5](https://github.com/WordPress/presence-api/commit/41ea0a59ce730fb0eac999644b78829ea0698610))
* **deps-dev:** bump @wordpress/env from 11.2.0 to 11.8.1 ([f434e72](https://github.com/WordPress/presence-api/commit/f434e72b691f9b0b7df72d14352ad5bc52a00c93))
* **deps-dev:** bump @wordpress/env from 11.2.0 to 11.8.1 ([89d77b1](https://github.com/WordPress/presence-api/commit/89d77b1563344875d29c3ef0deb5e2ff5f2651e1))
* **deps-dev:** bump @wordpress/env from 11.8.1 to 11.9.0 ([35860b9](https://github.com/WordPress/presence-api/commit/35860b9f5e0d28ac203dc55ce354a553eca9b8ce))
* **deps-dev:** bump @wordpress/env from 11.8.1 to 11.9.0 ([f67c7f1](https://github.com/WordPress/presence-api/commit/f67c7f10a02d1ae96adff1e8673e4b32c671ea21))
* **deps:** bump actions/cache from 4 to 6 ([4cd66ba](https://github.com/WordPress/presence-api/commit/4cd66ba79d69ba80b5addc8a4c6aae9b716bf207))
* **deps:** bump actions/cache from 4 to 6 ([3659561](https://github.com/WordPress/presence-api/commit/365956198c387d3bfb6fd4275c56a20aac2d2079))
* **deps:** bump actions/checkout from 4 to 7 ([8a70b87](https://github.com/WordPress/presence-api/commit/8a70b87e2194e25db24ef93644ca6b4457fcadcb))
* **deps:** bump actions/checkout from 4 to 7 ([f7a1c7d](https://github.com/WordPress/presence-api/commit/f7a1c7d6195fa7bc2fc4b52fb9fbc96963d6e4bd))
* **deps:** bump github/codeql-action from 3 to 4 ([f9e540e](https://github.com/WordPress/presence-api/commit/f9e540e4ca1bed150e65f1e0615fe34989c649e0))
* **deps:** bump github/codeql-action from 3 to 4 ([08b5607](https://github.com/WordPress/presence-api/commit/08b560789eceae19c2419cad2f9d48bee6d16ea0))

## 0.1.2

- Add WordPress Playground blueprint for one-click testing.
- Remove demo CLI command from production builds.
- Split CI into separate PHPCS, PHPUnit, and Multisite workflows.
- Exclude vendor directory from release zip.
- Add readme.txt for WordPress.org directory submission.
- Add WordPress.org repository compliance files (CONTRIBUTING, CODEOWNERS, CODE_OF_CONDUCT).
- Move community health files to .github/.
- Replace deprecated get_page_by_title() with WP_Query.
- Add ABSPATH guards to db-viewer.php and demo-seeder.php.
- Exclude .claude directory from release zip.

## 0.1.1

- Fix Plugin Check errors for directory submission.

## 0.1.0

Initial release.

- Dedicated `wp_presence` table with `UNIQUE KEY (room, client_id)` for atomic upserts via `INSERT ... ON DUPLICATE KEY UPDATE`.
- 60-second TTL with batched cron cleanup.
- Public API: `wp_get_presence`, `wp_set_presence`, `wp_remove_presence`, `wp_remove_user_presence`, `wp_can_access_presence_room`, `wp_presence_post_room`.
- REST endpoints: `GET/POST/DELETE /wp-presence/v1/presence`, `GET /wp-presence/v1/presence/rooms` with SQL pagination and `Cache-Control: no-store`.
- Heartbeat integration for admin and editor presence pings.
- Post-lock bridge: translates `wp-refresh-post-lock` into presence entries.
- Login/logout lifecycle hooks gated on `edit_posts`.
- Dashboard widgets: Who's Online (with idle detection, overflow threshold, avatar stacks) and Active Posts (grouped by post with editor counts).
- Admin bar indicator: avatar stack for same-page users, dropdown grouped by "On this page" / "Elsewhere", alphabetically sorted.
- Post list "Editors" column with avatar stacks.
- Users list "Online" filter tab.
- WP-CLI: `set`, `list`, `summary`, `cleanup`.
- Debugger widget (WP_DEBUG only): heartbeat monitor with live table viewer.
- `wp_presence_default_ttl` filter and `WP_PRESENCE_DEFAULT_TTL` constant.
- Multisite-aware `uninstall.php`.
- Full i18n with `.pot` file.
- WCAG AA accessibility: ARIA labels, `aria-live`, keyboard navigation.
- 59 PHPUnit tests, 118 assertions.
- Playwright e2e tests with screenshot artifacts.
