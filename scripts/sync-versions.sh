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
# Each target line is grep-checked before the sed and verified afterwards so a miss fails loudly.

set -euo pipefail

cd "$(dirname "$0")/.."

command -v jq      >/dev/null 2>&1 || { echo "jq is required to run scripts/sync-versions.sh" >&2; exit 1; }
command -v python3 >/dev/null 2>&1 || { echo "python3 is required to run scripts/sync-versions.sh" >&2; exit 1; }
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

grep -qFx " * Version: ${VERSION}" presence-api.php \
	|| { echo "Failed to update plugin header version in presence-api.php" >&2; exit 1; }
grep -qFx "define( 'WP_PRESENCE_VERSION', '${VERSION}' );" presence-api.php \
	|| { echo "Failed to update WP_PRESENCE_VERSION define in presence-api.php" >&2; exit 1; }
grep -qFx "Stable tag: ${VERSION}" readme.txt \
	|| { echo "Failed to update 'Stable tag:' line in readme.txt" >&2; exit 1; }

rm -f presence-api.php.bak readme.txt.bak

# Rewrite the == Changelog == section in readme.txt from CHANGELOG.md.
# Skips the Dependencies subsection, strips GitHub commit links, deduplicates bullets.
python3 - <<'PYTHON'
import re, sys

with open('CHANGELOG.md') as f:
    changelog_md = f.read()

with open('readme.txt') as f:
    readme = f.read()

if '== Changelog ==' not in readme:
    sys.exit('== Changelog == section not found in readme.txt')

blocks = re.split(r'\n(?=## )', changelog_md.strip())
entries = []

for block in blocks:
    lines = block.splitlines()
    if not lines:
        continue
    m = re.match(r'^## \[?(\d+\.\d+\.\d+)\]?', lines[0])
    if not m:
        continue
    version = m.group(1)

    # Entries matching any of these patterns are silently dropped as non-user-facing.
    SKIP_PATTERNS = [
        r'sync.?versions\.sh',       # internal release tooling
        r'changelog\.md',            # references to the changelog itself
        r'dropped by autofix',       # autofix noise
        r'\.claude\b',               # internal .claude directory
        r'merge conflict',           # git housekeeping
        r'^\*\*test[^*]*:\*\*',     # **test:** scoped commits
    ]

    def _skip(text):
        return any(re.search(p, text, re.IGNORECASE) for p in SKIP_PATTERNS)

    def _stem(text):
        """First 4 normalised words — used to deduplicate near-identical entries."""
        words = re.sub(r'[^\w\s]', '', text.lower()).split()
        return ' '.join(words[:4])

    in_skip = False
    bullets = []
    seen_exact = set()
    seen_stems = set()
    for line in lines[1:]:
        if re.match(r'^### ', line):
            in_skip = 'Dependencies' in line
            continue
        if in_skip:
            continue
        bm = re.match(r'^[*-] (.+)', line)
        if not bm:
            continue
        text = bm.group(1)
        # Strip trailing commit link(s): ([abc123](url))
        text = re.sub(r'\s+\(\[[\da-f]+\]\([^)]+\)(?:,\s*\[\w+\]\([^)]+\))*\)$', '', text)
        if _skip(text):
            continue
        # Strip leading **scope:** prefix added by release-please for scoped commits
        # release-please format: **scope:** text  (colon is inside the bold markers)
        text = re.sub(r'^\*\*[^*]+:\*\*\s*', '', text)
        text = (text[0].upper() + text[1:]) if text else text
        if text and text[-1] not in '.!?':
            text += '.'
        exact = text.lower()
        stem  = _stem(text)
        if exact not in seen_exact and stem not in seen_stems:
            seen_exact.add(exact)
            seen_stems.add(stem)
            bullets.append(f'* {text}')

    if not bullets:
        continue
    entry = f'= {version} =\n' + '\n'.join(bullets)
    entries.append(entry)

new_section = '== Changelog ==\n\n' + '\n\n'.join(entries) + '\n'
new_readme = re.sub(r'== Changelog ==.*', new_section, readme, flags=re.DOTALL)

with open('readme.txt', 'w') as f:
    f.write(new_readme)

print('Synced == Changelog == section in readme.txt')
PYTHON

echo "Synced all version references to ${VERSION}"
