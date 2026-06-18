#!/usr/bin/env bash
#
# Reads the canonical version from .release-please-manifest.json and syncs it
# into every place WordPress (and WordPress.org) reads it verbatim:
#
#   - presence-api.php plugin header `* Version:`
#   - presence-api.php `WP_PRESENCE_VERSION` define
#   - readme.txt `Stable tag:`
#
# Called from .github/workflows/release-please.yml after release-please opens
# (or updates) its release PR. Also runnable locally:
#
#   bash scripts/sync-versions.sh
#
# Each target line is grep-checked before the sed so a silent miss fails loudly.

set -euo pipefail

cd "$(dirname "$0")/.."

command -v jq >/dev/null 2>&1 || { echo "jq is required to run scripts/sync-versions.sh" >&2; exit 1; }
VERSION=$(jq -r '."."' .release-please-manifest.json)

if [[ -z "$VERSION" || "$VERSION" == "null" ]]; then
	echo "Could not read version from .release-please-manifest.json" >&2
	exit 1
fi

grep -q '^ \* Version: ' presence-api.php \
	|| { echo "Plugin header 'Version:' line not found in presence-api.php" >&2; exit 1; }
grep -q "^define( 'WP_PRESENCE_VERSION'" presence-api.php \
	|| { echo "WP_PRESENCE_VERSION define not found in presence-api.php" >&2; exit 1; }
grep -q '^Stable tag: ' readme.txt \
	|| { echo "'Stable tag:' line not found in readme.txt" >&2; exit 1; }

# `sed -i.bak` works on both GNU sed (Linux CI) and BSD sed (macOS dev).
sed -i.bak "s|^ \* Version: .*$| * Version: ${VERSION}|" presence-api.php
sed -i.bak "s|^\(define( 'WP_PRESENCE_VERSION', '\)[^']*\(' );\)|\1${VERSION}\2|" presence-api.php
sed -i.bak "s|^Stable tag: .*$|Stable tag: ${VERSION}|" readme.txt

rm -f presence-api.php.bak readme.txt.bak

echo "Synced all version references to ${VERSION}"
