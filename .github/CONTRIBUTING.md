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

Comment on the issue you would like to work on and a maintainer will assign it to you. The comment is not a formality: GitHub only allows issues to be assigned to people who have already commented on them, so there is nothing for us to click until you do.

Being assigned tells everyone else the issue is spoken for. It is not a commitment. If you find you cannot continue, for any reason, just say so on the issue and we will unassign it. No explanation needed.

If you got partway, a short note on what you tried and what you found out is genuinely useful. It saves the next person from starting over, and props-bot credits everyone who interacted with an issue or its linked pull request, so you stay in the props list for the work you did.

Issues that stay assigned and quiet for a couple of weeks may be unassigned so the backlog reflects reality. Comment again any time to pick one back up.

## Pull requests

1. Branch off `main`.
2. Title the pull request as a [Conventional Commit](https://www.conventionalcommits.org/). Pull requests are merged with a merge commit whose subject is the pull request title, so the title is what release-please reads. `lint-pr.yml` enforces this.
3. All CI checks must pass before merge (PHPCS, PHPStan, PHPUnit across PHP 7.4 + 8.3, multisite, Playwright).
4. Keep commits focused, one logical change per commit.

## Getting credited

WordPress credits contributors with props, which are tied to WordPress.org profiles rather than to GitHub accounts. Props-bot comments on every pull request with the running list of everyone who has contributed to it.

Two things have to be true for it to find you:

1. **The email on your commits is associated with your GitHub account.** If it is not, props-bot cannot resolve the commit to a person. Using your GitHub `@users.noreply.github.com` address is the simplest way to satisfy this, and it keeps your real address out of the public commit history.
2. **Your GitHub account is linked to your WordPress.org profile.** Follow [associating GitHub accounts with WordPress.org profiles](https://make.wordpress.org/core/2020/03/19/associating-github-accounts-with-wordpress-org-profiles/). Until this is done you will show up under "Unlinked Accounts" in the props-bot comment and cannot be included in the props for a release.

Both are worth doing before your first pull request is ready to merge, so nothing is held up at the end. Once linked, add the `props-bot` label to the pull request to refresh the list.

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
