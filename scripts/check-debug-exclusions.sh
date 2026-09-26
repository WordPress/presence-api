#!/usr/bin/env bash
#
# includes/db-viewer.php and includes/debugger-widget.php are WP_DEBUG-only
# developer tools, excluded from measurement/distribution in three places:
#
#   - .distignore        keeps them out of the distributed zip
#   - phpunit.xml.dist   keeps them out of PHPUnit coverage
#   - codecov.yml        keeps them out of the Codecov report
#
# Nothing enforces that the three lists agree. If phpunit.xml.dist excludes a
# file that codecov.yml does not, Codecov reports it as 0% covered and drags
# the project total down for a file deliberately left unmeasured. This script
# fails unless phpunit.xml.dist and codecov.yml exclude the same includes/*.php
# files and every one in .distignore is among them; the two lists can also hold
# files that ship but cannot be measured, such as the default-filters files.
#
# Called from .github/workflows/phpcs.yml. Also runnable locally:
#
#   bash scripts/check-debug-exclusions.sh

set -euo pipefail

cd "$(dirname "$0")/.."

distignore=$(grep -E '^includes/.*\.php$' .distignore | sort)
phpunit=$(grep -oE '<file>includes/[^<]+\.php</file>' phpunit.xml.dist | sed -E 's#<file>(.*)</file>#\1#' | sort)
codecov=$(grep -oE '"includes/[^"]+\.php"' codecov.yml | tr -d '"' | sort)

if [[ -z "$distignore" || -z "$phpunit" || -z "$codecov" ]]; then
	echo "One of the three exclusion lists is empty — check the grep patterns still match." >&2
	exit 1
fi

status=0

if [[ "$phpunit" != "$codecov" ]]; then
	echo "includes/*.php entries differ between phpunit.xml.dist and codecov.yml:" >&2
	diff <(echo "$phpunit") <(echo "$codecov") >&2 || true
	status=1
fi

missing=$(comm -23 <(echo "$distignore") <(echo "$phpunit"))
if [[ -n "$missing" ]]; then
	echo "In .distignore but not excluded from coverage:" >&2
	echo "$missing" >&2
	status=1
fi

if [[ $status -eq 0 ]]; then
	echo "Coverage exclusions agree across .distignore, phpunit.xml.dist, and codecov.yml."
fi

exit $status
