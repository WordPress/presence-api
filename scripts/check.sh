#!/usr/bin/env bash
#
# Runs every check that works without wp-env or Docker, in the order CI runs
# them, and stops at the first one that fails.
#
# Called by `npm run check`. Needs `composer install` and `npm install` first.

set -eu

cd "$(dirname "$0")/.."

if [ ! -d vendor ]; then
	echo "vendor/ is missing: run \`composer install\` first, then \`npm run check\` again." >&2
	exit 1
fi

step() {
	printf '\n==> %s\n' "$*"
	"$@"
}

step composer phpcs
step php scripts/check-private-calls.php
step composer phpstan
step npm run lint:js
step npm run test:unit
step npm run test:scripts

printf '\nAll checks passed.\n'
