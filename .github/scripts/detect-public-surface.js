'use strict';

// A hook, route, constant, CLI command, or browser global is a promise:
// once a site depends on one, renaming it breaks that site. This flags the pull
// request that adds one and names the ones with nothing written above them.
//
// It compares the full set of surfaces at each end rather than diff lines, so
// moving, reindenting, or renaming the function around a hook reads as no change.

// The plugin ships what is in these paths. `tests/` calls `apply_filters` and
// `do_action` constantly without publishing anything, and built assets under
// `assets/js/build/` duplicate the surfaces already counted in `src/`.
const SCANNED = [/^includes\//, /^assets\/js\//, /^src\//, /^(presence-api|uninstall)\.php$/];
const IGNORED = [/^assets\/js\/build\//, /\/test\//, /\.test\.js$/];

// WordPress's own, which a plugin reads without ever owning. `ABSPATH` alone
// opens every file in the tree, so without this the surfaces that are the
// plugin's drown in guards that promise a site nothing.
const CORE_CONSTANTS = new Set([
  // Bootstrap and paths.
  'ABSPATH', 'WPINC', 'WP_LANG_DIR', 'WP_CONTENT_DIR', 'WP_CONTENT_URL',
  'WP_PLUGIN_DIR', 'WP_PLUGIN_URL', 'WPMU_PLUGIN_DIR', 'WPMU_PLUGIN_URL',
  // Debugging.
  'WP_DEBUG', 'WP_DEBUG_LOG', 'WP_DEBUG_DISPLAY', 'SCRIPT_DEBUG', 'SAVEQUERIES',
  // Which kind of request this is.
  'DOING_AJAX', 'DOING_CRON', 'DOING_AUTOSAVE', 'REST_REQUEST', 'XMLRPC_REQUEST',
  'WP_ADMIN', 'WP_NETWORK_ADMIN', 'WP_USER_ADMIN', 'WP_CLI', 'WP_INSTALLING',
  'WP_INSTALLING_NETWORK', 'WP_SETUP_CONFIG', 'WP_REPAIRING', 'WP_SANDBOX_SCRAPING',
  'WP_UNINSTALL_PLUGIN',
  // Multisite.
  'MULTISITE', 'SUBDOMAIN_INSTALL', 'DOMAIN_CURRENT_SITE', 'PATH_CURRENT_SITE',
  'SITE_ID_CURRENT_SITE', 'BLOG_ID_CURRENT_SITE',
  // Behaviour a site configures in `wp-config.php`, WordPress's rather than ours.
  'WP_CACHE', 'WP_ENVIRONMENT_TYPE', 'WP_DEVELOPMENT_MODE', 'WP_MEMORY_LIMIT',
  'WP_MAX_MEMORY_LIMIT', 'DISALLOW_FILE_EDIT', 'DISALLOW_FILE_MODS', 'DISABLE_WP_CRON',
  'ALTERNATE_WP_CRON', 'WP_CRON_LOCK_TIMEOUT', 'EMPTY_TRASH_DAYS', 'AUTOSAVE_INTERVAL',
  'WP_POST_REVISIONS', 'MEDIA_TRASH', 'FORCE_SSL_ADMIN', 'COOKIE_DOMAIN',
]);

const PHP_RULES = [
  [
    /\b(apply_filters|do_action)(?:_ref_array|_deprecated)?\s*\(\s*(['"])(.*?)\2/g,
    (m) => `${'apply_filters' === m[1] ? 'filter' : 'action'}:${m[3]}`,
  ],
  // Every constant the plugin asks after, in either form a site can reach it:
  // the guarded define, where a value already in `wp-config.php` wins, and the
  // bare read of one only a site ever sets. Renaming either breaks that site.
  [/\bdefined\s*\(\s*(['"])([A-Z][A-Z0-9_]*)\1\s*\)/g, (m) => `constant:${m[2]}`],
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

// `@access private` in PHP per core's standards, `@private` in JS.
const PRIVATE = /@(?:private\b|access\s+private\b)/;

// The workflow greps for this literal to edit its own comment in place.
const MARKER = '<!-- presence-api:public-surface -->';

function isScanned(path) {
  return !IGNORED.some((re) => re.test(path)) && SCANNED.some((re) => re.test(path));
}

// `'/' . $this->rest_base . '/rooms'` reads back as `/$this->rest_base/rooms`.
function route(raw) {
  return raw.replace(/['"]/g, '').replace(/\s*\.\s*/g, '').replace(/\s+/g, ' ').trim();
}

// The docblock directly above `index`, or an empty string when the nearest one
// belongs to something else. Only the opening of the statement may sit between
// them, which covers `$ttl = apply_filters( ... )` and `window.x = function () {}`
// while stopping a docblock from leaking onto whatever statement follows the one
// it describes.
function docblockAbove(source, index) {
  const before = source.slice(0, index);
  const close = before.lastIndexOf('*/');

  // A statement terminator or a brace between the two means the docblock
  // belongs to something else.
  if (-1 === close || /[;{}]/.test(before.slice(close + 2))) {
    return '';
  }

  const open = before.lastIndexOf('/**', close);

  return -1 === open ? '' : before.slice(open, close);
}

// A docblock of nothing but tags is a signature, not a write-up: the bar is a
// sentence saying what the surface is for, which is what core's code reference
// renders. Documenting the hook where it fires beats a hand-kept list, which
// drifts the first time nobody updates it.
function describes(block) {
  return block
    .replace(/^\/\*\*/, '')
    .split('\n')
    .map((line) => line.replace(/^\s*\*+\s*/, '').trim())
    .some((line) => '' !== line && !line.startsWith('@'));
}

// The constants this file defines outright. A `define()` with no `! defined()`
// guard anywhere leaves a site nothing to set, so the name is the plugin's own
// rather than a promise to anyone, however often the code reads it back.
function ownConstants(source) {
  const named = (regex) => new Set([...source.matchAll(regex)].map((m) => m[2]));
  const own = named(/\bdefine\s*\(\s*(['"])([A-Z][A-Z0-9_]*)\1/g);

  for (const name of named(/!\s*defined\s*\(\s*(['"])([A-Z][A-Z0-9_]*)\1/g)) {
    own.delete(name);
  }

  return own;
}

// Identifiers are `kind:name` and carry no path, so the same hook found at a new
// location is the same surface. Each one also reports whether the docblock above
// it describes it; a private marker in that docblock withdraws it entirely.
function findSurfaces(source, path) {
  const rules = path.endsWith('.php') ? PHP_RULES : path.endsWith('.js') ? JS_RULES : [];
  const own = ownConstants(source);

  // A constant is only a promise if a site can set it.
  const reachable = (id) => {
    if (!id.startsWith('constant:')) {
      return true;
    }

    const name = id.slice('constant:'.length);

    return !CORE_CONSTANTS.has(name) && !own.has(name);
  };

  return rules.flatMap(([regex, build]) =>
    [...source.matchAll(regex)]
      .map((m) => ({ id: build(m), block: docblockAbove(source, m.index) }))
      .filter(({ id, block }) => !id.endsWith(':') && !PRIVATE.test(block) && reachable(id))
      .map(({ id, block }) => ({ id, documented: describes(block) }))
  );
}

// Additions only. A hook fired from two places is documented once and
// cross-referenced from the other, the way core does it, so one write-up
// anywhere at the head covers the surface.
function newSurfaces(base, head) {
  const before = new Set(base.map((s) => s.id));
  const fresh = new Map();

  for (const { id, documented } of head) {
    if (!before.has(id)) {
      fresh.set(id, documented || Boolean(fresh.get(id)));
    }
  }

  return [...fresh]
    .map(([id, documented]) => ({ id, documented }))
    .sort((a, b) => a.id.localeCompare(b.id));
}

// Kind first, so every line opens on the same kind of token instead of a name
// of arbitrary length, and what is left to do trails the name in italics so it
// reads as an aside rather than part of the surface.
function formatAudit(surfaces) {
  return surfaces
    .map(({ id, documented }) => {
      const at = id.indexOf(':');

      return `- (${id.slice(0, at)}) \`${id.slice(at + 1)}\`${documented ? '' : ' - *requires documentation*'}`;
    })
    .join('\n');
}

// An h3 heading, matching the Playground preview comment, so the two bots read
// the same way down a thread.
function formatComment(surfaces) {
  return [
    MARKER,
    `### 🚩 New public surface${1 === surfaces.length ? '' : 's'}`,
    '',
    formatAudit(surfaces),
    '',
  ].join('\n');
}

module.exports = findSurfaces;
module.exports.findSurfaces = findSurfaces;
module.exports.newSurfaces = newSurfaces;
module.exports.formatAudit = formatAudit;
module.exports.formatComment = formatComment;
module.exports.isScanned = isScanned;
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

  const surfaces = newSurfaces(scan(baseRef), scan(headRef));

  if (surfaces.length) {
    console.log(`Found ${surfaces.length} new public ${1 === surfaces.length ? 'surface' : 'surfaces'}:`);
    console.log(formatAudit(surfaces));
    fs.writeFileSync('comment.md', formatComment(surfaces));
  } else {
    console.log('No new public surfaces.');
  }

  if (process.env.GITHUB_OUTPUT) {
    fs.appendFileSync(process.env.GITHUB_OUTPUT, `count=${surfaces.length}\n`);
  }
}
