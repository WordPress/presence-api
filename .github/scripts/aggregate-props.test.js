'use strict';

const { test, mock } = require('node:test');
const assert = require('node:assert/strict');

const run = require('./aggregate-props.js');
const { parsePropsNames, sortProps, buildComment, MARKER } = run;

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

const RELEASE_PR = 99;
const context = { repo: { owner: 'WordPress', repo: 'presence-api' } };

function makePR(number, ref, merged_at = '2026-07-10T00:00:00Z') {
  return { number, merged_at, head: { ref } };
}

function propsComment(body) {
  return { id: 1, user: { login: 'github-actions[bot]' }, body };
}

function buildGithub({ releases = [], prs = [], commentsByPR = {} } = {}) {
  return {
    rest: {
      repos: {
        listReleases: async () => ({ data: releases }),
      },
      pulls: { list: async () => ({ data: prs }) },
      issues: {
        listComments: async ({ issue_number }) => ({ data: commentsByPR[issue_number] ?? [] }),
        createComment: mock.fn(async () => {}),
        updateComment: mock.fn(async () => {}),
      },
    },
    paginate: async (_fn, _params) => prs,
  };
}

function makeEnv(overrides = {}) {
  return { PR_NUMBER: String(RELEASE_PR), PROPS_SORT_LAST: '', ...overrides };
}

// ---------------------------------------------------------------------------
// parsePropsNames
// ---------------------------------------------------------------------------

test('parsePropsNames: extracts a single name', () => {
  assert.deepEqual(parsePropsNames('Props alice.'), ['alice']);
});

test('parsePropsNames: extracts multiple comma-separated names', () => {
  assert.deepEqual(parsePropsNames('Props alice, bob, carol.'), ['alice', 'bob', 'carol']);
});

test('parsePropsNames: returns empty array when no Props line is present', () => {
  assert.deepEqual(parsePropsNames('No props here.'), []);
});

test('parsePropsNames: handles Props line embedded in a longer comment body', () => {
  const body = [
    'Thank you for the contribution!',
    '',
    'Props alice, bob.',
    '',
    '## Unlinked Accounts',
  ].join('\n');
  assert.deepEqual(parsePropsNames(body), ['alice', 'bob']);
});

test('parsePropsNames: trims whitespace around names', () => {
  assert.deepEqual(parsePropsNames('Props  alice ,  bob .'), ['alice', 'bob']);
});

// ---------------------------------------------------------------------------
// sortProps
// ---------------------------------------------------------------------------

test('sortProps: deduplicates repeated names', () => {
  assert.deepEqual(sortProps(['alice', 'bob', 'alice'], ''), ['alice', 'bob']);
});

test('sortProps: moves sortLast to the end', () => {
  assert.deepEqual(
    sortProps(['alice', 'maintainer', 'bob'], 'maintainer'),
    ['alice', 'bob', 'maintainer']
  );
});

test('sortProps: leaves order unchanged when sortLast is not in the list', () => {
  assert.deepEqual(sortProps(['alice', 'bob'], 'maintainer'), ['alice', 'bob']);
});

test('sortProps: leaves order unchanged when sortLast is empty string', () => {
  assert.deepEqual(sortProps(['alice', 'bob', 'carol'], ''), ['alice', 'bob', 'carol']);
});

test('sortProps: deduplicates before applying sortLast', () => {
  assert.deepEqual(
    sortProps(['maintainer', 'alice', 'maintainer', 'bob'], 'maintainer'),
    ['alice', 'bob', 'maintainer']
  );
});

// ---------------------------------------------------------------------------
// buildComment
// ---------------------------------------------------------------------------

test('buildComment: starts with the sticky marker', () => {
  assert.ok(buildComment(['alice', 'bob']).startsWith(MARKER));
});

test('buildComment: formats the Props line correctly', () => {
  assert.ok(buildComment(['alice', 'bob']).includes('Props alice, bob.'));
});

// ---------------------------------------------------------------------------
// run()
// ---------------------------------------------------------------------------

test('run: creates a new comment when no sticky comment exists', async () => {
  const github = buildGithub({
    prs: [makePR(10, 'feature/foo')],
    commentsByPR: {
      10: [propsComment('Props alice, bob.')],
      [RELEASE_PR]: [],
    },
  });
  const core = { info: () => {}, setFailed: mock.fn() };

  await run({ github, context, core, env: makeEnv() });

  assert.equal(github.rest.issues.createComment.mock.calls.length, 1);
  const { issue_number, body } = github.rest.issues.createComment.mock.calls[0].arguments[0];
  assert.equal(issue_number, RELEASE_PR);
  assert.ok(body.startsWith(MARKER));
  assert.ok(body.includes('Props alice, bob.'));
});

test('run: updates an existing sticky comment', async () => {
  const stale = { id: 55, user: { login: 'github-actions[bot]' }, body: `${MARKER}\n\nProps old.` };
  const github = buildGithub({
    prs: [makePR(10, 'feature/foo')],
    commentsByPR: {
      10: [propsComment('Props carol.')],
      [RELEASE_PR]: [stale],
    },
  });
  const core = { info: () => {}, setFailed: mock.fn() };

  await run({ github, context, core, env: makeEnv() });

  assert.equal(github.rest.issues.updateComment.mock.calls.length, 1);
  const { comment_id, body } = github.rest.issues.updateComment.mock.calls[0].arguments[0];
  assert.equal(comment_id, 55);
  assert.ok(body.includes('Props carol.'));
});

test('run: skips posting when no merged PRs have props comments', async () => {
  const github = buildGithub({
    prs: [makePR(10, 'feature/foo')],
    commentsByPR: { 10: [], [RELEASE_PR]: [] },
  });
  const core = { info: mock.fn(), setFailed: mock.fn() };

  await run({ github, context, core, env: makeEnv() });

  assert.equal(github.rest.issues.createComment.mock.calls.length, 0);
  assert.equal(github.rest.issues.updateComment.mock.calls.length, 0);
});

test('run: excludes PRs merged before the cutoff date', async () => {
  const github = buildGithub({
    releases: [{ published_at: '2026-07-15T00:00:00Z' }],
    prs: [makePR(10, 'feature/old', '2026-07-01T00:00:00Z')],
    commentsByPR: {
      10: [propsComment('Props alice.')],
      [RELEASE_PR]: [],
    },
  });
  const core = { info: mock.fn(), setFailed: mock.fn() };

  await run({ github, context, core, env: makeEnv() });

  assert.equal(github.rest.issues.createComment.mock.calls.length, 0);
});

test('run: excludes the release PR itself', async () => {
  const github = buildGithub({
    prs: [makePR(RELEASE_PR, 'feature/foo')],
    commentsByPR: { [RELEASE_PR]: [propsComment('Props alice.')] },
  });
  const core = { info: mock.fn(), setFailed: mock.fn() };

  await run({ github, context, core, env: makeEnv() });

  assert.equal(github.rest.issues.createComment.mock.calls.length, 0);
});

test('run: excludes release-please-- and docs/add- branches', async () => {
  const github = buildGithub({
    prs: [
      makePR(20, 'release-please--branches--main'),
      makePR(21, 'docs/add-alice-to-contributors'),
    ],
    commentsByPR: {
      20: [propsComment('Props alice.')],
      21: [propsComment('Props bob.')],
      [RELEASE_PR]: [],
    },
  });
  const core = { info: mock.fn(), setFailed: mock.fn() };

  await run({ github, context, core, env: makeEnv() });

  assert.equal(github.rest.issues.createComment.mock.calls.length, 0);
});

test('run: applies PROPS_SORT_LAST and deduplicates across PRs', async () => {
  const github = buildGithub({
    prs: [makePR(10, 'feature/foo'), makePR(11, 'feature/bar')],
    commentsByPR: {
      10: [propsComment('Props alice, maintainer.')],
      11: [propsComment('Props bob, alice.')],
      [RELEASE_PR]: [],
    },
  });
  const core = { info: () => {}, setFailed: mock.fn() };

  await run({ github, context, core, env: makeEnv({ PROPS_SORT_LAST: 'maintainer' }) });

  const body = github.rest.issues.createComment.mock.calls[0].arguments[0].body;
  assert.ok(body.includes('Props alice, bob, maintainer.'));
});

test('run: calls setFailed when PR_NUMBER is invalid', async () => {
  const github = buildGithub();
  const core = { info: () => {}, setFailed: mock.fn() };

  await run({ github, context, core, env: { PR_NUMBER: '', PROPS_SORT_LAST: '' } });

  assert.equal(core.setFailed.mock.calls.length, 1);
  assert.equal(github.rest.issues.createComment.mock.calls.length, 0);
});
