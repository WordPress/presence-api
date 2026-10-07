#!/usr/bin/env bash
#
# The includes/*.php files that ship but only register hooks (the default-filters
# files) are excluded from measurement in two places:
#
#   - phpunit.xml.dist   keeps them out of PHPUnit coverage
#   - codecov.yml        keeps them out of the Codecov report
#
# Nothing enforces that the two lists agree. If phpunit.xml.dist excludes a
# file that codecov.yml does not, Codecov reports it as 0% covered and drags
# the project total down for a file deliberately left unmeasured. This script
# fails unless phpunit.xml.dist and codecov.yml exclude the same includes/*.php
# files.
#
# Called from .github/workflows/phpcs.yml. Also runnable locally:
#
#   bash scripts/check-debug-exclusions.sh

set -euo pipefail

cd "$(dirname "$0")/.."

phpunit=$(grep -oE '<file>plugins/presence-api/includes/[^<]+\.php</file>' phpunit.xml.dist | sed -E 's#<file>plugins/presence-api/(.*)</file>#\1#' | sort || true)
codecov=$(grep -oE '"plugins/presence-api/includes/[^"]+\.php"' codecov.yml | tr -d '"' | sed 's#^plugins/presence-api/##' | sort || true)

if [[ -z "$phpunit" || -z "$codecov" ]]; then
	echo "phpunit.xml.dist or codecov.yml exclusion list is empty — check the grep patterns still match." >&2
	exit 1
fi

if [[ "$phpunit" != "$codecov" ]]; then
	echo "includes/*.php entries differ between phpunit.xml.dist and codecov.yml:" >&2
	diff <(echo "$phpunit") <(echo "$codecov") >&2 || true
	exit 1
fi

echo "Coverage exclusions agree between phpunit.xml.dist and codecov.yml."
