'use strict';

const { test } = require('node:test');
const assert = require('node:assert/strict');

const findSurfaces = require('./detect-public-surface.js');
const { newSurfaces, auditSurfaces, formatAudit, formatComment, isScanned, MARKER } = findSurfaces;

const php = (source) => findSurfaces(source, 'includes/functions.php');
const js = (source) => findSurfaces(source, 'assets/js/presence-ping.js');

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
// newSurfaces
// ---------------------------------------------------------------------------

test('a hook moved to another file is not new', () => {
  // The identifier carries no path, so both sides collapse to the same entry.
  const base = findSurfaces("apply_filters( 'a', 1 );", 'includes/one.php');
  const head = findSurfaces("apply_filters( 'a', 1 );", 'includes/two.php');
  assert.deepEqual(newSurfaces(base, head), []);
});

test('reports additions, ignores removals, and sorts', () => {
  assert.deepEqual(newSurfaces(['filter:a', 'action:gone'], ['filter:a', 'filter:c', 'action:b']), [
    'action:b',
    'filter:c',
  ]);
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
// auditSurfaces
// ---------------------------------------------------------------------------

test('sends each kind to the section that documents it', () => {
  const audit = auditSurfaces(['filter:a', 'action:b', 'rest:/c', 'cli:d'], '');
  assert.deepEqual(
    audit.map((s) => s.section),
    ['### Filters', '### Actions', '## REST API', '## WP-CLI']
  );
});

test('a name already in the README counts as documented', () => {
  const doc = '### Filters\n#### `wp_presence_default_ttl`\nFilters the TTL.';
  const [documented, missing] = auditSurfaces(
    ['filter:wp_presence_default_ttl', 'filter:wp_presence_new_hook'],
    doc
  );
  assert.equal(documented.documented, true);
  assert.equal(missing.documented, false);
});

test('a global is matched on its last segment, since prose drops the window prefix', () => {
  const [surface] = auditSurfaces(
    ['js-global:wp.presence.markScreenStale'],
    'Bump the revision from JS via `wp.presence.markScreenStale()`.'
  );
  assert.equal(surface.documented, true);
});

test('splits on the first colon so a namespaced hook name survives', () => {
  const [surface] = auditSurfaces(['js-filter:presence.entry'], '');
  assert.equal(surface.name, 'presence.entry');
});

// ---------------------------------------------------------------------------
// formatAudit
// ---------------------------------------------------------------------------

test('names the section for anything undocumented', () => {
  const report = formatAudit(auditSurfaces(['filter:wp_presence_new_hook'], ''));
  assert.equal(report, '- `wp_presence_new_hook` (filter): add it under `### Filters`');
});

test('drops the per-entry status once everything is documented', () => {
  const report = formatAudit(auditSurfaces(['filter:a', 'action:b'], 'a b'));
  assert.equal(report, '- `a` (filter)\n- `b` (action)');
});

// ---------------------------------------------------------------------------
// formatComment
// ---------------------------------------------------------------------------

test('leads with the marker so the workflow can find its own comment', () => {
  assert.ok(formatComment(auditSurfaces(['filter:a'], '')).startsWith(MARKER));
});

test('asks for a README update only while something is missing', () => {
  const missing = formatComment(auditSurfaces(['filter:a'], ''));
  const covered = formatComment(auditSurfaces(['filter:a'], 'a'));
  assert.match(missing, /adds 1 extension point that sites can depend on\. Update `README\.md`/);
  assert.match(covered, /adds 1 extension point that sites can depend on\. It is already in/);
  // A heading would rule off the thread; the title carries inline instead.
  assert.doesNotMatch(missing, /^#/m);
});

test('agrees in number', () => {
  const two = formatComment(auditSurfaces(['filter:a', 'action:b'], 'a b'));
  assert.match(two, /adds 2 extension points that sites can depend on\. They are already in/);
});
