# Changelog

## [0.1.18](https://github.com/WordPress/presence-api/compare/v0.1.17...v0.1.18) (2026-08-11)


### Bug Fixes

* check table availability in the CLI command and debug viewer ([cfd705b](https://github.com/WordPress/presence-api/commit/cfd705bc66e352c4b56e301500848edd87eec08d))
* delete expired presence rows by key in bounded passes ([fc624e2](https://github.com/WordPress/presence-api/commit/fc624e2405e077f57858230f276def8544eb5430))
* delete expired presence rows by key in bounded passes ([b4a19a0](https://github.com/WordPress/presence-api/commit/b4a19a072c7bcb68669dcd0c27e210cce1877020))
* guard the CLI cleanup command with a table availability check ([a932652](https://github.com/WordPress/presence-api/commit/a9326529ef3cb8570062b37c2dd54290f578926c))
* guard the debug DB viewer query and drop the unused row count ([453bb12](https://github.com/WordPress/presence-api/commit/453bb12ce65b145a5dabc12c53e4a7f5fa066d52))

## [0.1.17](https://github.com/WordPress/presence-api/compare/v0.1.16...v0.1.17) (2026-08-10)


### Bug Fixes

* bound presence keys to the column width and validate REST args ([fb436fb](https://github.com/WordPress/presence-api/commit/fb436fb7760196a64ebf8afba0fa368eb138d07d))
* provision the presence table per site instead of on admin_init only ([56cf948](https://github.com/WordPress/presence-api/commit/56cf948d8928ab20bd99423f3877c933766e9dbe))
* rebuild the presence table when the version option outlives it ([3105f3b](https://github.com/WordPress/presence-api/commit/3105f3b4248f8825a9fbf404b9ef20aa3a9e1414))

## [0.1.16](https://github.com/WordPress/presence-api/compare/v0.1.15...v0.1.16) (2026-08-08)


### Bug Fixes

* store presence data as longtext and compare schema version as an integer ([06f12a7](https://github.com/WordPress/presence-api/commit/06f12a789136bdd92139780f2999440ad80506a4))

## [0.1.15](https://github.com/WordPress/presence-api/compare/v0.1.14...v0.1.15) (2026-08-08)


### Bug Fixes

* replace 404ing Playground badge with the one used in PR previews ([4581d8f](https://github.com/WordPress/presence-api/commit/4581d8f26a5e8c9b6c40786bf26e97f1d39da15b))
* stop props bot echoing raw commit author emails in unlinked accounts ([8198fa9](https://github.com/WordPress/presence-api/commit/8198fa9c29d390f5d0b9d2cfa92dd7f4e9f884be))

## [0.1.14](https://github.com/WordPress/presence-api/compare/v0.1.13...v0.1.14) (2026-08-08)


### Features

* add display_name and avatar_url to presence response ([909da07](https://github.com/WordPress/presence-api/commit/909da07f1d464216faa8c8b88c264a9e3f436388))


### Bug Fixes

* add missing alt text to Who's Online widget avatar ([d2dd547](https://github.com/WordPress/presence-api/commit/d2dd547e99f43d8bfe8e2d589d3733134bd9d765))
* correct heartbeat function name in render test ([9ba2c81](https://github.com/WordPress/presence-api/commit/9ba2c81cabb3db71b7d61809c91b9f5ee5f61134))
* filter presence read paths by per-post capability ([d009a97](https://github.com/WordPress/presence-api/commit/d009a97cc294954bb5a19b1aa339dcae61f47116))
* restore wp_set_presence, fix assertion quote handling ([e9129df](https://github.com/WordPress/presence-api/commit/e9129df82bb8c72ae63280d92e0bd8b1204ea34a))
* use heartbeat path in render test for reliable coverage ([da96ad3](https://github.com/WordPress/presence-api/commit/da96ad373528ccdbce557ffabdc7bd83d59f4c9b))
* use wp_presence_admin_room() after ROOM constant removed ([8ac1ae4](https://github.com/WordPress/presence-api/commit/8ac1ae495f3e1734369e759dbfc2bd3725c5e122))
* write presence via admin handler in render test ([eed9be1](https://github.com/WordPress/presence-api/commit/eed9be18200d27a11eb75370d914068b3233c3ee))

## [0.1.13](https://github.com/WordPress/presence-api/compare/v0.1.12...v0.1.13) (2026-08-07)


### Features

* move inline heartbeat JS to a standalone enqueued script ([8913cad](https://github.com/WordPress/presence-api/commit/8913cad63e40dda4f4c56bad579fba4fb90c70a6))


### Bug Fixes

* replace GROUP_CONCAT session mutations with PHP aggregation ([9628b33](https://github.com/WordPress/presence-api/commit/9628b3312f98cfe027b59bda431941562f5e0797))

## [0.1.12](https://github.com/WordPress/presence-api/compare/v0.1.11...v0.1.12) (2026-08-07)


### Features

* add action links to the plugin list table ([a5bd44a](https://github.com/WordPress/presence-api/commit/a5bd44a3720529a6262066315ee28a9e25417dea))
* add blueprints for plugin page preview button ([722b400](https://github.com/WordPress/presence-api/commit/722b400cf89ba0743c3cf602ccd5da6b9430c76f))


### Dependencies

* bump php_codesniffer to 3.13.6 for CVE-2026-67434 ([f2667ee](https://github.com/WordPress/presence-api/commit/f2667ee20435f0fe9cab48b6525ba8d165141125))
* declare PHPUnit and Polyfills as composer dependencies ([0636200](https://github.com/WordPress/presence-api/commit/0636200383110c681358b72d4a8a79f451b512d2))
* **deps:** bump the codeql-action group with 3 updates ([9eaa679](https://github.com/WordPress/presence-api/commit/9eaa679257ccbf4dcdd874e11385cf7d177ea42f))
* pin the Dependabot commit prefix so bumps reach the changelog ([c11c4fa](https://github.com/WordPress/presence-api/commit/c11c4fa1c7fabb736ffd44132cb0b09f9c9c21e6))
* require phpunit-polyfills ^2.0 to clear the core bootstrap floor ([031bd84](https://github.com/WordPress/presence-api/commit/031bd848b2750070f1f7e3bfccf4abcdaf74b050))

## [0.1.11](https://github.com/WordPress/presence-api/compare/v0.1.10...v0.1.11) (2026-07-31)


### Bug Fixes

* enforce per-room authorization checks for presence rooms ([e6d7782](https://github.com/WordPress/presence-api/commit/e6d77823d6216481b025d70667411c0ae4115499))

## [0.1.10](https://github.com/WordPress/presence-api/compare/v0.1.9...v0.1.10) (2026-07-27)


### Bug Fixes

* credit every contributor in the release props comment ([d069193](https://github.com/WordPress/presence-api/commit/d069193109e69dc1f6a84b261ca6b95c0efd313b))
* move the admin/online write out of the Who's Online widget ([b7b500b](https://github.com/WordPress/presence-api/commit/b7b500bd2ca5eac2d2cc98485ea3ac4452c0a324)), closes [#141](https://github.com/WordPress/presence-api/issues/141)
* render release props in a code block like props-bot ([3a2ae99](https://github.com/WordPress/presence-api/commit/3a2ae990edf2279519e480d6e3f1f12c425cb93b))

## [0.1.9](https://github.com/WordPress/presence-api/compare/v0.1.8...v0.1.9) (2026-07-26)


### Features

* aggregate props from merged PRs onto release PR ([05441f1](https://github.com/WordPress/presence-api/commit/05441f154299304a7d67966705f9ac43e3b440f7))


### Bug Fixes

* default presence widgets to top of dashboard on fresh install ([aa2e12f](https://github.com/WordPress/presence-api/commit/aa2e12fa0bf40cdf709b68a8e88592e5e6a173a3))
* remove top-level permissions block that broke release-please startup ([e3408dd](https://github.com/WordPress/presence-api/commit/e3408dd7205688029fa776b4c5582036d3508c05))
* use inline script to load aggregate-props from workspace ([a7aa1f1](https://github.com/WordPress/presence-api/commit/a7aa1f1903a11dfafe6c0e441bac69f6ae451c6f))

## [0.1.8](https://github.com/WordPress/presence-api/compare/v0.1.7...v0.1.8) (2026-07-24)


### Bug Fixes

* add AI Tools disclosure to automated contributor PR body ([17261d4](https://github.com/WordPress/presence-api/commit/17261d450362cdedca898f161e83008630965e70))
* add concurrency group, use default_branch instead of hardcoded main ([bd4b20e](https://github.com/WordPress/presence-api/commit/bd4b20e93ae1f1855de8507e43338db0ef102772))
* robot PR body for first contributions, suppress props-bot on contributor PRs ([1990954](https://github.com/WordPress/presence-api/commit/1990954eedb6c5130e3677d89667fa16181829f1))
* suppress props-bot on release-please PRs ([b2dd9ab](https://github.com/WordPress/presence-api/commit/b2dd9ab4020cfe70eb8d0d5af08c461599dbbed6))
* use user.type for bot detection, wrap fetch in full try/catch ([840ab72](https://github.com/WordPress/presence-api/commit/840ab720b1ba7901c3f2b8a69b021340abd6b9b3))


### Reverts

* remove AI disclosure from automated PR body ([2333d5e](https://github.com/WordPress/presence-api/commit/2333d5e29e823b4738ada29654afe63162e1e825))

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
* close wp_presence_current_screen_key() brace dropped by autofix ([106cc9b](https://github.com/WordPress/presence-api/commit/106cc9b6e334e93b15298c4c2a766b679305b815))
* resolve merge conflicts with main branch ([afeb72b](https://github.com/WordPress/presence-api/commit/afeb72bd41934991bb603c651069072f00900ee3))
* **test:** use a second admin viewer for the options/* heartbeat test ([ea2f618](https://github.com/WordPress/presence-api/commit/ea2f61806cf9d74c730d20381594405baa48dd74))


### Dependencies

* **deps-dev:** bump @playwright/test from 1.58.2 to 1.61.0 ([8ac3924](https://github.com/WordPress/presence-api/commit/8ac392486d36127a510d034c7f3f4ba4dd7dd459))
* **deps-dev:** bump @playwright/test from 1.61.0 to 1.61.1 ([7de9a96](https://github.com/WordPress/presence-api/commit/7de9a96290340e01795efe710ca0c11f38f3e11d))
* **deps-dev:** bump @wordpress/e2e-test-utils-playwright ([dc16d26](https://github.com/WordPress/presence-api/commit/dc16d26518a7b5673f37e14300acbd79615669b6))
* **deps-dev:** bump @wordpress/e2e-test-utils-playwright from 1.42.0 to 1.48.1 ([8f0563a](https://github.com/WordPress/presence-api/commit/8f0563a70b92dbc3ba0b54ecd5b1f7cee803af7a))
* **deps-dev:** bump @wordpress/e2e-test-utils-playwright from 1.48.1 to 1.49.0 ([41ea0a5](https://github.com/WordPress/presence-api/commit/41ea0a59ce730fb0eac999644b78829ea0698610))
* **deps-dev:** bump @wordpress/e2e-test-utils-playwright from 1.49.0 to 1.50.0 ([2c5a787](https://github.com/WordPress/presence-api/commit/2c5a7877a2f111dc9b806885c03579f37de04b2d))
* **deps-dev:** bump @wordpress/env from 11.2.0 to 11.8.1 ([f434e72](https://github.com/WordPress/presence-api/commit/f434e72b691f9b0b7df72d14352ad5bc52a00c93))
* **deps-dev:** bump @wordpress/env from 11.8.1 to 11.9.0 ([35860b9](https://github.com/WordPress/presence-api/commit/35860b9f5e0d28ac203dc55ce354a553eca9b8ce))
* **deps-dev:** bump @wordpress/env from 11.9.0 to 11.10.0 ([83cae8f](https://github.com/WordPress/presence-api/commit/83cae8feb1ecd444e21348b7253078726160d009))
* **deps-dev:** update phpstan/phpstan requirement from 2.1.39 to 2.2.3 ([ac9ca35](https://github.com/WordPress/presence-api/commit/ac9ca3571a3644313d7da245a3a7b1ee8c7c41bf))
* **deps-dev:** update phpstan/phpstan requirement from 2.2.3 to 2.2.5 ([b330e29](https://github.com/WordPress/presence-api/commit/b330e29340b3165fc0773b8865f61b467606e8f5))
* **deps:** bump actions/cache from 4 to 6 ([4cd66ba](https://github.com/WordPress/presence-api/commit/4cd66ba79d69ba80b5addc8a4c6aae9b716bf207))
* **deps:** bump actions/checkout from 4 to 7 ([8a70b87](https://github.com/WordPress/presence-api/commit/8a70b87e2194e25db24ef93644ca6b4457fcadcb))
* **deps:** bump github/codeql-action from 3 to 4 ([f9e540e](https://github.com/WordPress/presence-api/commit/f9e540e4ca1bed150e65f1e0615fe34989c649e0))
* **deps:** bump googleapis/release-please-action from 4 to 5 ([68a89de](https://github.com/WordPress/presence-api/commit/68a89dea5af9bd71dec33c48be830ea3306c8aa6))

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
