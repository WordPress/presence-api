#!/usr/bin/env bash
#
# Regenerates plugins/presence-api/languages/presence-api.pot from the source
# that ships, so the template never lags the release it is bundled with.
#
# Everything in the plugin's .distignore is excluded too, which keeps strings
# from the WP_DEBUG-only developer tools (db-viewer, debugger) out of the
# template; translators would otherwise be asked for text no user ever sees.
#
# Called from .github/workflows/release-please.yml right after
# scripts/sync-versions.sh, so `Project-Id-Version` carries the new version.
# Also runnable locally:
#
#   bash scripts/make-pot.sh
#
# A template whose only change is `POT-Creation-Date` is reverted, so the
# release PR only gets a commit when a translatable string actually changed.

set -euo pipefail

cd "$(dirname "$0")/.."

command -v wp >/dev/null 2>&1 || { echo "WP-CLI is required to run scripts/make-pot.sh" >&2; exit 1; }

PLUGIN='plugins/presence-api'
POT="${PLUGIN}/languages/presence-api.pot"

# .distignore entries are paths relative to the plugin, the same base
# `wp i18n make-pot --exclude` resolves against. Trailing slashes are dropped
# because make-pot matches directories by name.
EXCLUDE=$(grep -vE '^\s*(#|$)' "${PLUGIN}/.distignore" | sed 's#/$##' | paste -sd, -)

wp i18n make-pot "$PLUGIN" "$POT" \
	--slug=presence-api \
	--domain=presence-api \
	--exclude="$EXCLUDE"

if git diff --quiet -I '^"POT-Creation-Date: ' -- "$POT"; then
	git checkout -- "$POT"
	echo "No translatable strings changed; left ${POT} as it was"
else
	echo "Regenerated ${POT}"
fi
