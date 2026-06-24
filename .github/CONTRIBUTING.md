# Contributing

## Local development

```bash
npm install
npx wp-env start
```

Dashboard: [localhost:8888/wp-admin/](http://localhost:8888/wp-admin/) (admin / password)

## Running tests

```bash
# Coding standards
composer install
./vendor/bin/phpcs --standard=phpcs.xml.dist

# Static analysis
./vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --memory-limit=2G

# Unit tests (requires wp-env running)
npx wp-env run tests-cli -- composer require --dev phpunit/phpunit:^9.6 yoast/phpunit-polyfills:^2.0 --working-dir=/var/www/html --update-with-all-dependencies
npm test

# E2E tests
npx playwright test --config tests/e2e/playwright.config.js
```

The PHPUnit install step only needs to be run once per `wp-env start`.

## Pull requests

1. Branch off `main`.
2. All CI checks must pass before merge (PHPCS, PHPUnit across PHP 7.4 + 8.3, multisite).
3. Keep commits focused — one logical change per commit.

## Releases

Releases are automated by [release-please](https://github.com/googleapis/release-please). Use [Conventional Commits](https://www.conventionalcommits.org/) in the commit subject — release-please reads them to decide the next version and to generate the changelog:

- `feat: ...` → minor bump
- `fix: ...` → patch bump
- `feat!: ...` or a `BREAKING CHANGE:` footer → major bump (or, pre-1.0, a minor bump)
- `chore:`, `docs:`, `refactor:`, `test:`, `ci:`, `build:`, `style:` → no version bump

When the release-please PR is merged, the tag, GitHub Release, and zip asset are produced automatically.

`scripts/sync-versions.sh` reads the version from `.release-please-manifest.json` and updates the plugin header `Version:`, the `WP_PRESENCE_VERSION` constant, and `readme.txt`'s `Stable tag:`. The release-please workflow runs it on every release PR; you can run it locally too:

```bash
bash scripts/sync-versions.sh
```
