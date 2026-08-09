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

## Claiming an issue

[Open and unassigned](https://github.com/WordPress/presence-api/issues?q=is%3Aissue+is%3Aopen+no%3Aassignee+-label%3A%22Needs+Discussion%22) issues are available. `Good First Issue` marks the ones suited to new contributors.

Comment on the issue you want and a maintainer will assign it. GitHub only allows assignment to people who have commented.

Assignment is not a commitment. If you stop, comment and we will unassign it. If you got partway, note what you tried. Props-bot credits everyone who interacted with an issue or its pull request.

Assigned issues left quiet for two weeks may be unassigned. Comment to pick one back up.

## Pull requests

1. Branch off `main`.
2. Title the pull request as a [Conventional Commit](https://www.conventionalcommits.org/). Pull requests are merged with a merge commit whose subject is the pull request title, so the title is what release-please reads. `lint-pr.yml` enforces this.
3. All CI checks must pass before merge (PHPCS, PHPStan, PHPUnit across PHP 7.4 + 8.3 plus a multisite run, Playwright).
4. Keep commits focused, one logical change per commit.

## Getting credited

Props are tied to WordPress.org profiles, not GitHub accounts. Props-bot comments the running list on every pull request. Two things let it find you:

1. **A commit email tied to your GitHub account.** Your `@users.noreply.github.com` address works and keeps your real one out of public history.
2. **Your GitHub account [linked to your WordPress.org profile](https://make.wordpress.org/core/2020/03/19/associating-github-accounts-with-wordpress-org-profiles/).** Until then you appear under "Unlinked Accounts" and cannot be propped in a release.

Do both before your first pull request is ready. Add the `props-bot` label to refresh the list.

### In the demos

Getting onto the `Contributors` line in `readme.txt` also puts you in the [Playground demos](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/WordPress/presence-api/main/blueprint.json), which seed themselves from it, shown under your WordPress.org username and avatar. Each boot samples a different handful, so the demo shows a different room every time.

If you would rather not appear, add your username, lowercased, to `WP_PRESENCE_DEMO_OPTOUT` in [`tests/e2e/demo-seeder.php`](../tests/e2e/demo-seeder.php). No reason needed, and it does not affect your props.

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
