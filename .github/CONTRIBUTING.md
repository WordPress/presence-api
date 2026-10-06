# Contributing

## Local development

```bash
npm install
npx wp-env start
```

Dashboard: [localhost:8888/wp-admin/](http://localhost:8888/wp-admin/) (admin / password)

### Debugging

With `WP_DEBUG` on, which wp-env sets, administrators get a heart in the toolbar that counts down to the next Heartbeat and beats when the server answers. Its menu lists every client in the rooms you are in, including the plugin's own bookkeeping rows. Click the heart to keep the menu open, and use the database icon beside a room to see its raw rows. Release zips leave these tools out.

## Running tests

Before pushing, one command runs everything that works without wp-env, which is coding standards, static analysis, the JavaScript lint, the JavaScript unit tests, and the workflow script tests. It stops at the first failure.

```bash
# PHP dependencies (PHPCS, PHPStan, PHPUnit, Polyfills)
composer install

npm run check
```

Most coding-standard errors it reports can be fixed in place:

```bash
composer phpcbf
```

The individual commands, for when you want just one of them:

```bash
# Coding standards
composer phpcs

# Static analysis
composer phpstan

# JavaScript lint and unit tests
npm run lint:js
npm run test:unit

# Workflow script tests
npm run test:scripts

# PHP unit tests (requires wp-env running)
npm test

# Multisite tests (requires wp-env running)
npm run test:multisite

# E2E tests (requires wp-env running)
npx playwright install chromium
npm run test:e2e
```

The Network Admin specs need a network, which is a second wp-env instance on [localhost:8890](http://localhost:8890/wp-admin/network/) with its own database. `npm run test:e2e` starts and seeds it on demand, so the first run after a fresh checkout takes a few minutes longer. `npm run test:e2e -- --project chromium` skips the network, and `--project chromium-multisite` runs only the network specs. `npm run env:stop:multisite` shuts it down.

Tests here carry `@covers`, and PHPUnit records coverage only for the functions a test names. A new helper called by a function that is already covered still reports as unexecuted until some test names it, which shows up as a Codecov drop with every test passing. Add the helper to the `@covers` list of whichever tests exercise it.

## Claiming an issue

[Open and unassigned](https://github.com/WordPress/presence-api/issues?q=is%3Aissue+is%3Aopen+no%3Aassignee+-label%3A%22Needs+Discussion%22) issues are available. `Good First Issue` marks the ones suited to new contributors.

Comment on the issue you want and a maintainer will assign it. GitHub only allows assignment to people who have commented.

Assignment is not a commitment. If you stop, comment and we will unassign it. If you got partway, note what you tried. Props-bot credits everyone who interacted with an issue or its pull request.

Assigned issues left quiet for two weeks may be unassigned. Comment to pick one back up.

### Labels

Every open issue carries one `[Type]`, at least one `[Area]`, and a milestone. `[Area] Infrastructure` covers CI and the toolchain. The bug and enhancement forms apply the `[Type]`, and `issue-triage.yml` comments on an issue until it has the other two. A blank issue is still fine.

Color groups labels rather than identifying them. Labels on most rows stay in a highlighter tone, saturation is reserved for the few that want something from you, and anything a bot applies is gray.

`Needs Reply` is the exception, since it asks something of a maintainer: `needs-reply.yml` adds it once someone outside CODEOWNERS has waited 48 hours for an answer and removes it when one arrives.

<details>
<summary>What each color marks</summary>

| Color | Labels |
| --- | --- |
| `#FEF298` | every `[Area]` label |
| `#FFB7B0` | `[Type] Bug` |
| `#E2C8FF` | `[Type] Enhancement` |
| `#B5E0FF` | `[Type] Feature` |
| `#B8EAE0` | `[Type] Documentation` |
| `#DED6B0` | `Performance`, `Privacy`, `Public API` |
| `#97EDA0` | `Good First Issue`, `Good First Review`, `help wanted` |
| `#F2994A` | the `Needs` labels and `Close Candidate` |
| `#DC3545` | `blocked` |
| `#CED4DA` | anything a bot applies, languages included |
| `#ADB5BD` | `duplicate`, `invalid` |
| `#FFFFFF` | `wontfix` |

Rendered, they are on the [labels page](https://github.com/WordPress/presence-api/labels).

A new label joins an existing color. If it genuinely needs its own, check it in both themes: GitHub picks the text color from the label's lightness and lightens the label itself on dark backgrounds.

</details>

## Pull requests

1. Branch off `main`.
2. Title the pull request as a [Conventional Commit](https://www.conventionalcommits.org/). Pull requests are squashed into a single commit whose subject is the pull request title, so the title is what release-please reads. `lint-pr.yml` enforces this.
3. All CI checks must pass before merge (PHPCS, PHPStan, PHPUnit across PHP 7.4 + 8.3 plus a multisite run, Playwright).
4. Keep a pull request to one logical change. Its commits are collapsed on merge, so the pull request is the unit of history.

### Descriptions

WordPress moves at the speed of volunteer review, and there is only so much of it to go around. The thing that helps most is a sentence at the top saying what we would miss by only reading the diff. That is the whole ask. Short is good, "couldn't test this on multisite" is good, and if it only occurs to you after opening, just edit it in.

### Public surfaces

A hook, REST route, WP-CLI command, constant, or browser global that a site can reach is a promise: renaming it later breaks that site. `public-surface.yml` labels any pull request that adds one and comments with the ones nothing has been written about yet.

Written about means a docblock directly above it with a sentence saying what it is for. That is where WordPress core's code reference reads from, and it keeps the write-up next to the code instead of in a hand-kept list that drifts the first time someone forgets it. `README.md` narrates the surfaces worth a worked example; it is not the inventory.

Some names are reachable without being a promise, like a global that only exists so two enqueued scripts can share a renderer. Mark those private in that same docblock and the check leaves them alone.

<details>
<summary>Marking a name private</summary>

In PHP use `@access private`, the marker [core's documentation standards](https://developer.wordpress.org/coding-standards/inline-documentation-standards/php/) name, directly below `@since`:

```php
/**
 * Returns the reserved client_id a room's collaboration state is stored under.
 *
 * @since 0.1.0
 * @access private
 */
function wp_presence_collaboration_state_client_id() {}
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

</details>

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

Merging a plugin's release PR tags it `<plugin>-vX.Y.Z` and attaches its zip to the GitHub Release, except Presence API, which is tagged `vX.Y.Z` and also ships to WordPress.org.

<details>
<summary>Releasing a new plugin</summary>

1. Add its directory to `packages` in `release-please-config.json` with its `package-name`, `"include-component-in-tag": true`, and an `"initial-version"` matching its `Version:` header.
2. Add the directory to presence-api's `exclude-paths` so its commits stay out of the Presence API changelog.
3. Give it a `.distignore` listing what the zip leaves out, starting with `.distignore`, `CHANGELOG.md`, and `tests`.

</details>

<details>
<summary>Keeping the version numbers in step</summary>

On every release PR, `scripts/sync-versions.sh` copies the version from `.release-please-manifest.json` into every plugin's `Version:` header, plus Presence API's `WP_PRESENCE_VERSION` constant and `readme.txt` `Stable tag:`, and you can run it locally too:

```bash
bash scripts/sync-versions.sh
```

It also rebuilds the changelog in `readme.txt` from `CHANGELOG.md` and leaves out entries that never reach a site, which it recognizes by the title naming the debugger, the DB viewer, release-please, a workflow or a test tool.

Right after it, `scripts/make-pot.sh` regenerates `plugins/presence-api/languages/presence-api.pot` with WP-CLI, skipping everything in the plugin's `.distignore`, so the template ships with the release it names. It needs [WP-CLI](https://wp-cli.org/) to run locally:

```bash
bash scripts/make-pot.sh
```

</details>
