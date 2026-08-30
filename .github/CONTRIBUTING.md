# Contributing

## Local development

```bash
npm install
npx wp-env start
```

Dashboard: [localhost:8888/wp-admin/](http://localhost:8888/wp-admin/) (admin / password)

## Running tests

```bash
# PHP dependencies (PHPCS, PHPStan, PHPUnit, Polyfills)
composer install

# Coding standards
./vendor/bin/phpcs --standard=phpcs.xml.dist

# Static analysis
./vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --memory-limit=2G

# Unit tests (requires wp-env running)
npm test

# E2E tests (requires wp-env running)
npx playwright install chromium
npm run test:e2e
```

The Network Admin specs need a network, which is a second wp-env instance on [localhost:8890](http://localhost:8890/wp-admin/network/) with its own database. `npm run test:e2e` starts and seeds it on demand, so the first run after a fresh checkout takes a few minutes longer. `npm run env:stop:multisite` shuts it down.

## Claiming an issue

[Open and unassigned](https://github.com/WordPress/presence-api/issues?q=is%3Aissue+is%3Aopen+no%3Aassignee+-label%3A%22Needs+Discussion%22) issues are available. `Good First Issue` marks the ones suited to new contributors.

Comment on the issue you want and a maintainer will assign it. GitHub only allows assignment to people who have commented.

Assignment is not a commitment. If you stop, comment and we will unassign it. If you got partway, note what you tried. Props-bot credits everyone who interacted with an issue or its pull request.

Assigned issues left quiet for two weeks may be unassigned. Comment to pick one back up.

### Labels

Every open issue carries one `[Type]`, at least one `[Area]`, and a milestone. `[Area] Infrastructure` covers CI and the toolchain, so tooling issues get an area like everything else.

Color groups labels rather than identifying them. Anything that sits on most rows stays in a highlighter tone, saturation is reserved for the few that want something from you, and anything applied automatically is gray.

| Group | Color | Labels |
| --- | --- | --- |
| Area | `#FEF298` | the nine `[Area]` labels |
| Type | `#FFB7B0` `#E2C8FF` `#B5E0FF` `#B8EAE0` | `Bug`, `Enhancement`, `Feature`, `Documentation` |
| Cross-cutting | `#DED6B0` | `Performance`, `Privacy`, `Public API` |
| Open to contributors | `#97EDA0` | `Good First Issue`, `Good First Review`, `help wanted` |
| Waiting on a decision | `#F2994A` | `Needs Decision`, `Needs Discussion`, `Needs WP.org Link`, `Close Candidate` |
| Waiting on something external | `#DC3545` | `blocked` |
| Applied automatically | `#CED4DA` | `php`, `javascript`, `dependencies`, `github_actions`, `props-bot`, `skip changelog`, `maybelater`, `autorelease: pending`, `autorelease: tagged` |
| Resolution | `#ADB5BD` | `duplicate`, `invalid`, and `wontfix` at `#ffffff` |

A new label joins an existing group's color instead of taking a new hue. If it genuinely needs one, check it in both light and dark mode: GitHub picks the text color from the label's lightness and lightens the label itself on dark backgrounds, so a color that only works in one theme is common.

## Pull requests

1. Branch off `main`.
2. Title the pull request as a [Conventional Commit](https://www.conventionalcommits.org/). Pull requests are merged with a merge commit whose subject is the pull request title, so the title is what release-please reads. `lint-pr.yml` enforces this.
3. All CI checks must pass before merge (PHPCS, PHPStan, PHPUnit across PHP 7.4 + 8.3 plus a multisite run, Playwright).
4. Keep commits focused, one logical change per commit.

### Public surfaces

A hook, REST route, WP-CLI command, guarded constant, or browser global that a site can reach is a promise: renaming it later breaks that site. `public-surface.yml` labels any pull request that adds one and comments with the ones nothing has been written about yet.

Written about means a docblock directly above it with a sentence saying what it is for. That is where WordPress core's code reference reads from, and it keeps the write-up next to the code instead of in a hand-kept list that drifts the first time someone forgets it. `README.md` narrates the surfaces worth a worked example; it is not the inventory.

Some names are reachable without being a promise, like a global that only exists so two enqueued scripts can share a renderer. Mark those private in that same docblock and the check leaves them alone. In PHP use `@access private`, the marker [core's documentation standards](https://developer.wordpress.org/coding-standards/inline-documentation-standards/php/) name, directly below `@since`:

```php
/**
 * Returns the transient key a room's collaboration state is stored under.
 *
 * @since 0.1.0
 * @access private
 */
function wp_presence_collaboration_state_key( $room ) {}
```

JavaScript has no `@since` line to sit under, so `@private` on its own is enough there:

```js
/**
 * Builds an avatar stack.
 *
 * @private
 */
window.wpPresenceBuildAvatarStack = function ( users, max ) {};
```

## Getting credited

Props are tied to WordPress.org profiles, not GitHub accounts. Props-bot comments the running list on every pull request. Two things let it find you:

1. **A commit email tied to your GitHub account.** Your `@users.noreply.github.com` address works and keeps your real one out of public history.
2. **Your GitHub account [linked to your WordPress.org profile](https://make.wordpress.org/core/2020/03/19/associating-github-accounts-with-wordpress-org-profiles/).** Until then you appear under "Unlinked Accounts" and cannot be propped in a release.

Do both before your first pull request is ready. Add the `props-bot` label to refresh the list, on an open or an already merged pull request.

Linking late still counts. Release props are assembled when the release goes out, and anyone listed under "Unlinked Accounts" is checked again at that point, so a profile linked between your merge and the release picks up the credit.

## Releases

Releases are automated by [release-please](https://github.com/googleapis/release-please). Use [Conventional Commits](https://www.conventionalcommits.org/) in the pull request title — release-please reads it to decide the next version and to generate the changelog:

- `feat: ...` → minor bump
- `fix: ...` → patch bump
- `feat!: ...` or a `BREAKING CHANGE:` footer → major bump (or, pre-1.0, a minor bump)
- `chore:`, `docs:`, `refactor:`, `test:`, `ci:`, `build:`, `style:` → no version bump

When the release-please PR is merged, the tag, GitHub Release, and zip asset are produced automatically.

`scripts/sync-versions.sh` reads the version from `.release-please-manifest.json` and updates the plugin header `Version:`, the `WP_PRESENCE_VERSION` constant, and `readme.txt`'s `Stable tag:`. The release-please workflow runs it on every release PR; you can run it locally too:

```bash
bash scripts/sync-versions.sh
```
