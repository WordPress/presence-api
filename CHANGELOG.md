# Changelog

## [0.1.7](https://github.com/WordPress/presence-api/compare/v0.1.6...v0.1.7) (2026-07-24)


### Bug Fixes

* add validate_callback validation check to REST screen_key ([66eb99f](https://github.com/WordPress/presence-api/commit/66eb99f4ac0d1b136243714c4829eb8dd127edcf))
* use correct REST route in PHPUnit tests ([1a95f2f](https://github.com/WordPress/presence-api/commit/1a95f2f352340072d19800476087e7eebb4d3b80))

## [0.1.6](https://github.com/WordPress/presence-api/compare/v0.1.5...v0.1.6) (2026-07-23)


### Bug Fixes

* dispatch deploy workflow instead of calling as reusable to avoid startup failure ([acd812b](https://github.com/WordPress/presence-api/commit/acd812bcfe8b468a837ea88377d361d0ef4389da))
* flatten deploy workflow to remove reusable nesting causing startup failure ([b7b5459](https://github.com/WordPress/presence-api/commit/b7b54595b3380d05d453d1b54c0b4e0a7185f567))
* use 10up action ASSETS_DIR instead of separate assets workflow ([4ec612d](https://github.com/WordPress/presence-api/commit/4ec612db68878c61cfd4edf55a0b8adc83cccd49))
* use 10up action ASSETS_DIR, remove separate assets workflow ([5de6150](https://github.com/WordPress/presence-api/commit/5de6150b52b5ca54ac2f56ac921400af743aba75))
* use correct heading format in Unlinked Accounts regex ([6dc2d0d](https://github.com/WordPress/presence-api/commit/6dc2d0d75ee0397dda1b2dc58cd6038d58cc103b))

## [0.1.5](https://github.com/WordPress/presence-api/compare/v0.1.4...v0.1.5) (2026-07-23)


### Bug Fixes

* check entry ownership before enforcing per-user presence limit ([5698d94](https://github.com/WordPress/presence-api/commit/5698d9425baa9a67561626c4ca8421a5daf64728)), closes [#88](https://github.com/WordPress/presence-api/issues/88)
* exclude expired entries from ownership check to keep cap exact ([1560498](https://github.com/WordPress/presence-api/commit/15604988141f85028d4367a3c73dff909f65fca1))
* pass VERSION env var to deploy action so SVN tag matches git tag ([1a920ef](https://github.com/WordPress/presence-api/commit/1a920ef5f007465cd5e4f5e56a3439a34ec1bc10))
* preserve version headings in sync script and correct wp_options claim ([8d1189f](https://github.com/WordPress/presence-api/commit/8d1189fe02e70a778be05fdc31ec2e9492c8c662))


### Dependencies

* **deps-dev:** bump @wordpress/e2e-test-utils-playwright from 1.50.0 to 1.51.0 ([c217dd4](https://github.com/WordPress/presence-api/commit/c217dd4607362e0b3166678c93f88da07452d5e3))
* **deps-dev:** bump @wordpress/env from 11.10.0 to 11.11.0 ([ab48e93](https://github.com/WordPress/presence-api/commit/ab48e93eb4f2bbd335480703b263987ecb19d3c4))
* **deps-dev:** update wp-coding-standards/wpcs requirement from ~3.3.0 to ~3.4.0 ([a0578dd](https://github.com/WordPress/presence-api/commit/a0578dd9326f735cdcb315c1958aeff354bc9b01))

## [0.1.4](https://github.com/WordPress/presence-api/compare/v0.1.3...v0.1.4) (2026-07-09)


### Features

* auto-sync readme.txt changelog from CHANGELOG.md in sync-versions.sh ([cdf3fce](https://github.com/WordPress/presence-api/commit/cdf3fce38bea227d248613566a4e108e19e2a19a))

## [0.1.3](https://github.com/WordPress/presence-api/compare/v0.1.2...v0.1.3) (2026-07-09)


### Features

* add 40-user Playground blueprint ([797ca0c](https://github.com/WordPress/presence-api/commit/797ca0c6fb77cec461874f7f2944637538eebd24))
* add 40-user Playground blueprint (down from 100) ([782e282](https://github.com/WordPress/presence-api/commit/782e282d5e39aa15940143d805cb569f97505923))


### Bug Fixes

* address stale-screen review feedback ([495c3ce](https://github.com/WordPress/presence-api/commit/495c3ceaf92f16bfac71d977c951c5161ef24114))
* address WordPress.org plugin review feedback ([032a3d0](https://github.com/WordPress/presence-api/commit/032a3d02fef843d94a536b98eb089d7b642c56ff))
* address WordPress.org plugin review feedback ([155067f](https://github.com/WordPress/presence-api/commit/155067f4a912beee2cb25eba016d139675806627))
* close wp_presence_current_screen_key() brace dropped by autofix ([106cc9b](https://github.com/WordPress/presence-api/commit/106cc9b6e334e93b15298c4c2a766b679305b815))
* resolve merge conflicts with main branch ([afeb72b](https://github.com/WordPress/presence-api/commit/afeb72bd41934991bb603c651069072f00900ee3))
* **test:** use a second admin viewer for the options/* heartbeat test ([ea2f618](https://github.com/WordPress/presence-api/commit/ea2f61806cf9d74c730d20381594405baa48dd74))


### Dependencies

* **deps-dev:** bump @playwright/test from 1.58.2 to 1.61.0 ([8ac3924](https://github.com/WordPress/presence-api/commit/8ac392486d36127a510d034c7f3f4ba4dd7dd459))
* **deps-dev:** bump @playwright/test from 1.58.2 to 1.61.0 ([0840f51](https://github.com/WordPress/presence-api/commit/0840f51bd6513c1e587ba362b06f90720e839405))
* **deps-dev:** bump @playwright/test from 1.61.0 to 1.61.1 ([7de9a96](https://github.com/WordPress/presence-api/commit/7de9a96290340e01795efe710ca0c11f38f3e11d))
* **deps-dev:** bump @playwright/test from 1.61.0 to 1.61.1 ([fea7439](https://github.com/WordPress/presence-api/commit/fea7439d0ae9b2cbe8c65d897def8ef45e5079e2))
* **deps-dev:** bump @wordpress/e2e-test-utils-playwright ([dc16d26](https://github.com/WordPress/presence-api/commit/dc16d26518a7b5673f37e14300acbd79615669b6))
* **deps-dev:** bump @wordpress/e2e-test-utils-playwright ([3069684](https://github.com/WordPress/presence-api/commit/30696843b41a2b0bdc299af650ef8fd286989529))
* **deps-dev:** bump @wordpress/e2e-test-utils-playwright ([8588c9b](https://github.com/WordPress/presence-api/commit/8588c9b42f0d0ed6c610476675b35c484ea56311))
* **deps-dev:** bump @wordpress/e2e-test-utils-playwright from 1.42.0 to 1.48.1 ([8f0563a](https://github.com/WordPress/presence-api/commit/8f0563a70b92dbc3ba0b54ecd5b1f7cee803af7a))
* **deps-dev:** bump @wordpress/e2e-test-utils-playwright from 1.48.1 to 1.49.0 ([41ea0a5](https://github.com/WordPress/presence-api/commit/41ea0a59ce730fb0eac999644b78829ea0698610))
* **deps-dev:** bump @wordpress/e2e-test-utils-playwright from 1.49.0 to 1.50.0 ([2c5a787](https://github.com/WordPress/presence-api/commit/2c5a7877a2f111dc9b806885c03579f37de04b2d))
* **deps-dev:** bump @wordpress/env from 11.2.0 to 11.8.1 ([f434e72](https://github.com/WordPress/presence-api/commit/f434e72b691f9b0b7df72d14352ad5bc52a00c93))
* **deps-dev:** bump @wordpress/env from 11.2.0 to 11.8.1 ([89d77b1](https://github.com/WordPress/presence-api/commit/89d77b1563344875d29c3ef0deb5e2ff5f2651e1))
* **deps-dev:** bump @wordpress/env from 11.8.1 to 11.9.0 ([35860b9](https://github.com/WordPress/presence-api/commit/35860b9f5e0d28ac203dc55ce354a553eca9b8ce))
* **deps-dev:** bump @wordpress/env from 11.8.1 to 11.9.0 ([f67c7f1](https://github.com/WordPress/presence-api/commit/f67c7f10a02d1ae96adff1e8673e4b32c671ea21))
* **deps-dev:** bump @wordpress/env from 11.9.0 to 11.10.0 ([83cae8f](https://github.com/WordPress/presence-api/commit/83cae8feb1ecd444e21348b7253078726160d009))
* **deps-dev:** bump @wordpress/env from 11.9.0 to 11.10.0 ([32157ae](https://github.com/WordPress/presence-api/commit/32157aedaefb7777f4a8e839e11dd695636b50f0))
* **deps-dev:** update phpstan/phpstan requirement from 2.1.39 to 2.2.3 ([ac9ca35](https://github.com/WordPress/presence-api/commit/ac9ca3571a3644313d7da245a3a7b1ee8c7c41bf))
* **deps-dev:** update phpstan/phpstan requirement from 2.1.39 to 2.2.3 ([26151b2](https://github.com/WordPress/presence-api/commit/26151b225ce02583d7faeb3bf6df4846f637fba5))
* **deps-dev:** update phpstan/phpstan requirement from 2.2.3 to 2.2.5 ([b330e29](https://github.com/WordPress/presence-api/commit/b330e29340b3165fc0773b8865f61b467606e8f5))
* **deps-dev:** update phpstan/phpstan requirement from 2.2.3 to 2.2.5 ([767edaf](https://github.com/WordPress/presence-api/commit/767edafceee8b45a1f9fb008d679ddb49df31ffb))
* **deps:** bump actions/cache from 4 to 6 ([4cd66ba](https://github.com/WordPress/presence-api/commit/4cd66ba79d69ba80b5addc8a4c6aae9b716bf207))
* **deps:** bump actions/cache from 4 to 6 ([3659561](https://github.com/WordPress/presence-api/commit/365956198c387d3bfb6fd4275c56a20aac2d2079))
* **deps:** bump actions/checkout from 4 to 7 ([8a70b87](https://github.com/WordPress/presence-api/commit/8a70b87e2194e25db24ef93644ca6b4457fcadcb))
* **deps:** bump actions/checkout from 4 to 7 ([f7a1c7d](https://github.com/WordPress/presence-api/commit/f7a1c7d6195fa7bc2fc4b52fb9fbc96963d6e4bd))
* **deps:** bump github/codeql-action from 3 to 4 ([f9e540e](https://github.com/WordPress/presence-api/commit/f9e540e4ca1bed150e65f1e0615fe34989c649e0))
* **deps:** bump github/codeql-action from 3 to 4 ([08b5607](https://github.com/WordPress/presence-api/commit/08b560789eceae19c2419cad2f9d48bee6d16ea0))
* **deps:** bump googleapis/release-please-action from 4 to 5 ([68a89de](https://github.com/WordPress/presence-api/commit/68a89dea5af9bd71dec33c48be830ea3306c8aa6))
* **deps:** bump googleapis/release-please-action from 4 to 5 ([e2ae7e8](https://github.com/WordPress/presence-api/commit/e2ae7e80e78fc94534b96df31699077065c88c08))

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
