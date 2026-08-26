'use strict';

// A hook, route, guarded constant, CLI command, or browser global is a promise:
// once a site depends on one, renaming it breaks that site. This flags the pull
// request that adds one and names the README section still missing it.
//
// It compares the full set of surfaces at each end rather than diff lines, so
// moving, reindenting, or renaming the function around a hook reads as no change.

// The plugin ships what is in these paths. `tests/` calls `apply_filters` and
// `do_action` constantly without publishing anything, and built assets under
// `assets/js/build/` duplicate the surfaces already counted in `src/`.
const SCANNED = [/^includes\//, /^assets\/js\//, /^src\//, /^(presence-api|uninstall)\.php$/];
const IGNORED = [/^assets\/js\/build\//, /\/test\//, /\.test\.js$/];

const PHP_RULES = [
  [
    /\b(apply_filters|do_action)(?:_ref_array|_deprecated)?\s*\(\s*(['"])(.*?)\2/g,
    (m) => `${'apply_filters' === m[1] ? 'filter' : 'action'}:${m[3]}`,
  ],
  // Only the guarded form, and only the plugin's own prefix: a bare `define()`
  // is an internal a site cannot override, and every file opens with `ABSPATH`.
  [/if\s*\(\s*!\s*defined\s*\(\s*(['"])(WP_PRESENCE_[A-Z0-9_]*)\1\s*\)\s*\)/g, (m) => `constant:${m[2]}`],
  // Routes are usually concatenated (`'/' . $this->rest_base . '/rooms'`), so
  // take the whole second argument rather than the first literal inside it.
  [/register_rest_route\s*\(\s*[^,]+,\s*([^,]+?)\s*,/g, (m) => `rest:${route(m[1])}`],
  [/WP_CLI::add_command\s*\(\s*(['"])(.*?)\1/g, (m) => `cli:${m[2]}`],
];

const JS_RULES = [
  [/\bwindow\.(wpPresence[\w$]*)\s*=(?!=)/g, (m) => `js-global:window.${m[1]}`],
  [/\bwindow\.wp\.presence\.([\w$]+)\s*=(?!=)/g, (m) => `js-global:wp.presence.${m[1]}`],
  [
    /\b(?:wp\.hooks\.)?(applyFilters|doAction)\s*\(\s*(['"`])(.*?)\2/g,
    (m) => `${'applyFilters' === m[1] ? 'js-filter' : 'js-action'}:${m[3]}`,
  ],
];

// The workflow greps for this literal to edit its own comment in place.
const MARKER = '<!-- presence-api:public-surface -->';

// README.md is the only canonical reference for extension points. src/README.md
// covers the `usePresenceUsers` React hook alone; readme.txt lists no hooks.
const DOC_FILE = 'README.md';
const DEFAULT_SECTION = '## Extension Points';
const DOC_SECTIONS = {
  filter: '### Filters',
  action: '### Actions',
  rest: '## REST API',
  cli: '## WP-CLI',
};

function isScanned(path) {
  return !IGNORED.some((re) => re.test(path)) && SCANNED.some((re) => re.test(path));
}

// `'/' . $this->rest_base . '/rooms'` reads back as `/$this->rest_base/rooms`.
function route(raw) {
  return raw.replace(/['"]/g, '').replace(/\s*\.\s*/g, '').replace(/\s+/g, ' ').trim();
}

// Identifiers are `kind:name` and carry no path, so the same hook found at a new
// location is the same surface.
function findSurfaces(source, path) {
  const rules = path.endsWith('.php') ? PHP_RULES : path.endsWith('.js') ? JS_RULES : [];

  return rules.flatMap(([regex, build]) =>
    [...source.matchAll(regex)].map(build).filter((s) => !s.endsWith(':'))
  );
}

function newSurfaces(base, head) {
  const before = new Set(base);
  return [...new Set(head)].filter((s) => !before.has(s)).sort();
}

// A name appearing anywhere in README.md counts, which is deliberately loose:
// the bar is writing a hook down at all, not writing it up well.
function auditSurfaces(surfaces, doc) {
  return surfaces.map((surface) => {
    const at = surface.indexOf(':');
    const kind = surface.slice(0, at);
    const name = surface.slice(at + 1);

    return {
      kind,
      name,
      section: DOC_SECTIONS[kind] || DEFAULT_SECTION,
      // Globals are written `window.x` here but `x` in prose.
      documented: doc.includes(name.split('.').pop()),
    };
  });
}

function formatAudit(audit) {
  return audit
    .map(({ kind, name, section, documented }) =>
      documented
        ? `- ${kind} \`${name}\`: documented`
        : `- ${kind} \`${name}\`: **missing from \`${DOC_FILE}\`**, add it under \`${section}\``
    )
    .join('\n');
}

function formatComment(audit) {
  const one = 1 === audit.length;
  const headline = audit.every((s) => s.documented)
    ? `${one ? 'It is' : 'They are'} already in \`${DOC_FILE}\`.`
    : `Update \`${DOC_FILE}\` before this merges.`;

  return [
    MARKER,
    '## New public surface',
    '',
    `This branch adds ${audit.length} extension point${one ? '' : 's'} that sites can depend on. ${headline}`,
    '',
    formatAudit(audit),
    '',
    'Also update `readme.txt` under `= For Developers =` if the change is user-facing.',
    '',
  ].join('\n');
}

module.exports = findSurfaces;
module.exports.findSurfaces = findSurfaces;
module.exports.newSurfaces = newSurfaces;
module.exports.auditSurfaces = auditSurfaces;
module.exports.formatAudit = formatAudit;
module.exports.formatComment = formatComment;
module.exports.isScanned = isScanned;
module.exports.DOC_FILE = DOC_FILE;
module.exports.MARKER = MARKER;

// CLI: `node detect-public-surface.js <base-ref> <head-ref>`. Reads both trees
// through git, never the working directory, so the head is never checked out.
// Writes `count` to $GITHUB_OUTPUT and the comment body to comment.md.
if (require.main === module) {
  const { execFileSync } = require('node:child_process');
  const fs = require('node:fs');

  const [baseRef, headRef] = process.argv.slice(2);

  if (!baseRef || !headRef) {
    console.error('Usage: node detect-public-surface.js <base-ref> <head-ref>');
    process.exit(1);
  }

  const git = (args) => execFileSync('git', args, { encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 });

  const read = (ref, path) => {
    try {
      return git(['show', `${ref}:${path}`]);
    } catch {
      return '';
    }
  };

  const scan = (ref) =>
    git(['ls-tree', '-r', '--name-only', ref])
      .split('\n')
      .filter(isScanned)
      .flatMap((path) => findSurfaces(read(ref, path), path));

  // README.md is read at the head, so a branch adding a hook and its write-up
  // together reports the surface as already covered.
  const audit = auditSurfaces(newSurfaces(scan(baseRef), scan(headRef)), read(headRef, DOC_FILE));

  if (audit.length) {
    console.log(`Found ${audit.length} new public ${1 === audit.length ? 'surface' : 'surfaces'}:`);
    console.log(formatAudit(audit));
    fs.writeFileSync('comment.md', formatComment(audit));
  } else {
    console.log('No new public surfaces.');
  }

  if (process.env.GITHUB_OUTPUT) {
    fs.appendFileSync(process.env.GITHUB_OUTPUT, `count=${audit.length}\n`);
  }
}
