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
