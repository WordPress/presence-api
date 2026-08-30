'use strict';

const { test } = require('node:test');
const assert = require('node:assert/strict');

const findSurfaces = require('./detect-public-surface.js');
const { newSurfaces, formatAudit, formatComment, isScanned, MARKER } = findSurfaces;

const ids = (surfaces) => surfaces.map((s) => s.id);
const php = (source) => ids(findSurfaces(source, 'includes/functions.php'));
const js = (source) => ids(findSurfaces(source, 'assets/js/presence-ping.js'));
const surface = (id, documented = false) => ({ id, documented });

// ---------------------------------------------------------------------------
// PHP hooks
// ---------------------------------------------------------------------------

test('reads filters and actions apart', () => {
  assert.deepEqual(php("apply_filters( 'wp_presence_default_ttl', 30 );"), [
    'filter:wp_presence_default_ttl',
  ]);
  assert.deepEqual(php("do_action( 'wp_presence_admin_room_changed' );"), [
    'action:wp_presence_admin_room_changed',
  ]);
});

test('reads the _ref_array and _deprecated variants', () => {
  assert.deepEqual(
    php("apply_filters_ref_array( 'a', $x ); do_action_deprecated( 'b', $y, '1.0' );"),
    ['filter:a', 'action:b']
  );
});

test('keeps an interpolated hook name whole', () => {
  assert.deepEqual(php('do_action( "wp_presence_{$room}_joined" );'), [
    'action:wp_presence_{$room}_joined',
  ]);
});

// ---------------------------------------------------------------------------
// Constants, REST, CLI
// ---------------------------------------------------------------------------

test('counts a guarded constant, ignores a bare define and core guards', () => {
  const source = `
    if ( ! defined( 'ABSPATH' ) ) {
      exit;
    }
    if ( ! defined( 'WP_PRESENCE_DEFAULT_TTL' ) ) {
      define( 'WP_PRESENCE_DEFAULT_TTL', 30 );
    }
    define( 'WP_PRESENCE_INTERNAL', 5 );
  `;
  assert.deepEqual(php(source), ['constant:WP_PRESENCE_DEFAULT_TTL']);
});

test('reads a route through a variable namespace', () => {
  assert.deepEqual(
    php("register_rest_route( $this->namespace, '/presence', array() );"),
    ['rest:/presence']
  );
});

test('reads a concatenated route whole, not just its first literal', () => {
  const source = `register_rest_route(
    $this->namespace,
    '/' . $this->rest_base . '/rooms',
    array()
  );`;
  assert.deepEqual(php(source), ['rest:/$this->rest_base/rooms']);
});

test('reads a CLI command', () => {
  assert.deepEqual(php("WP_CLI::add_command( 'presence', $class );"), ['cli:presence']);
});

// ---------------------------------------------------------------------------
// JavaScript
// ---------------------------------------------------------------------------

test('reads browser globals and the wp.presence namespace', () => {
  assert.deepEqual(js('window.wpPresenceCreateTabCoordinator = function () {};'), [
    'js-global:window.wpPresenceCreateTabCoordinator',
  ]);
  assert.deepEqual(js('window.wp.presence.markScreenStale = function () {};'), [
    'js-global:wp.presence.markScreenStale',
  ]);
});

test('a comparison against a global is not a declaration', () => {
  assert.deepEqual(js('if ( window.wpPresenceThing === undefined ) {}'), []);
});

test('reads wp.hooks calls', () => {
  assert.deepEqual(js("wp.hooks.applyFilters( 'presence.entry', x );"), [
    'js-filter:presence.entry',
  ]);
});

test('php rules do not run against a js file, or the reverse', () => {
  assert.deepEqual(js("apply_filters( 'not_php', 1 );"), []);
  assert.deepEqual(php('window.wpPresenceThing = 1;'), []);
});

// ---------------------------------------------------------------------------
// @private
// ---------------------------------------------------------------------------

test('a @private docblock keeps an internal seam out', () => {
  const source = `/**
 * Builds an avatar stack.
 *
 * @private
 */
window.wpPresenceBuildAvatarStack = function ( users, max ) {};`;
  assert.deepEqual(js(source), []);
  assert.deepEqual(js(source.replace(' * @private\n', '')), [
    'js-global:window.wpPresenceBuildAvatarStack',
  ]);
});

test('@private applies to the statement it documents, not the next one', () => {
  const source = `/**
 * @private
 */
window.wpPresenceInternal = function () {};
window.wpPresencePublic = function () {};`;
  assert.deepEqual(js(source), ['js-global:window.wpPresencePublic']);
});

test('@access private withdraws a surface the same way', () => {
  const source = `/**
 * Fires internally.
 *
 * @since 0.1.0
 * @access private
 */
do_action( 'wp_presence_internal' );`;
  assert.deepEqual(php(source), []);
  assert.deepEqual(php(source.replace(' * @access private\n', '')), ['action:wp_presence_internal']);
});

test('@private reads the same above a php hook', () => {
  const source = `/**
 * Fires internally.
 *
 * @private
 */
do_action( 'wp_presence_internal' );`;
  assert.deepEqual(php(source), []);
});

// ---------------------------------------------------------------------------
// The documented bar
// ---------------------------------------------------------------------------

test('a docblock saying what the hook is for documents it, through the assignment', () => {
  const [long] = findSurfaces(
    `/**
 * Filters the default TTL.
 *
 * @since 0.1.0
 * @param int $ttl Seconds.
 */
$ttl = apply_filters( 'wp_presence_default_ttl', 30 );`,
    'includes/functions.php'
  );
  const [short] = findSurfaces(
    "/** Filters the TTL. */\napply_filters( 'a', 1 );",
    'includes/functions.php'
  );
  assert.equal(long.documented, true);
  assert.equal(short.documented, true);
});

test('tags with no sentence are a signature, not a write-up', () => {
  const [tagged] = findSurfaces(
    "/**\n * @since 0.1.0\n */\napply_filters( 'a', 1 );",
    'includes/functions.php'
  );
  const [bare] = findSurfaces("apply_filters( 'a', 1 );", 'includes/functions.php');
  assert.equal(tagged.documented, false);
  assert.equal(bare.documented, false);
});

// ---------------------------------------------------------------------------
// newSurfaces
// ---------------------------------------------------------------------------

test('a hook moved to another file is not new', () => {
  // The identifier carries no path, so both sides collapse to the same entry.
  const base = findSurfaces("apply_filters( 'a', 1 );", 'includes/one.php');
  const head = findSurfaces("apply_filters( 'a', 1 );", 'includes/two.php');
  assert.deepEqual(newSurfaces(base, head), []);
});

test('reports additions, ignores removals, and sorts', () => {
  const fresh = newSurfaces(
    [surface('filter:a'), surface('action:gone')],
    [surface('filter:a'), surface('filter:c'), surface('action:b')]
  );
  assert.deepEqual(ids(fresh), ['action:b', 'filter:c']);
});

test('one write-up covers a hook fired from two places', () => {
  const [only] = newSurfaces([], [surface('filter:a'), surface('filter:a', true)]);
  assert.equal(only.documented, true);
});

// ---------------------------------------------------------------------------
// isScanned
// ---------------------------------------------------------------------------

test('scans shipped code only', () => {
  for (const path of ['includes/functions.php', 'presence-api.php', 'assets/js/ping.js']) {
    assert.equal(isScanned(path), true, path);
  }
  // `tests/` is the loudest false-positive source: it calls hooks constantly
  // without publishing any. Built assets duplicate `src/`.
  for (const path of [
    'tests/test-functions.php',
    'assets/js/build/index.js',
    'src/utils/test/coordinator.test.js',
    '.github/scripts/detect-public-surface.js',
  ]) {
    assert.equal(isScanned(path), false, path);
  }
});

// ---------------------------------------------------------------------------
// formatAudit
// ---------------------------------------------------------------------------

test('marks what is undocumented and leaves the rest bare', () => {
  const report = formatAudit([surface('filter:wp_presence_new_hook'), surface('action:b', true)]);
  assert.equal(report, '- ⚠️ (filter) `wp_presence_new_hook`\n- (action) `b`');
});

test('splits on the first colon so a namespaced hook name survives', () => {
  assert.match(formatAudit([surface('js-filter:presence.entry', true)]), /\(js-filter\) `presence\.entry`/);
});

// ---------------------------------------------------------------------------
// formatComment
// ---------------------------------------------------------------------------

test('leads with the marker so the workflow can find its own comment', () => {
  assert.ok(formatComment([surface('filter:a')]).startsWith(`${MARKER}\n### 🚩 New public surface\n`));
});

test('names the gap only while something is undocumented', () => {
  const missing = formatComment([surface('filter:a')]);
  const covered = formatComment([surface('filter:a', true)]);
  assert.match(missing, /adds 1 extension point that sites can depend on, 1 without a docblock\./);
  assert.match(covered, /adds 1 extension point that sites can depend on\./);
});

// The count is what is left to do, not the total, so a branch that documents
// one of two is not told both are missing.
test('counts only the undocumented surfaces', () => {
  const mixed = formatComment([surface('filter:a', true), surface('action:b')]);
  assert.match(mixed, /adds 2 extension points that sites can depend on, 1 without a docblock\./);
});

test('agrees in number', () => {
  const two = formatComment([surface('filter:a', true), surface('action:b', true)]);
  assert.match(two, /^### 🚩 New public surfaces$/m);
  assert.match(two, /adds 2 extension points that sites can depend on\./);
});
