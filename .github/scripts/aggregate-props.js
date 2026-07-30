'use strict';

const MARKER = '<!-- presence-api:release-props -->';
const RELEASE_BRANCH = 'release-please--';
// Plugin releases are tagged vX.Y.Z. The releases list also holds Playground
// preview releases (preview-pr-N) and release-please drafts, neither of which
// may set the cutoff.
const RELEASE_TAG = /^v\d+\.\d+\.\d+$/;

function findCutoff(releases) {
  return releases
    .filter(r => !r.draft && r.published_at && RELEASE_TAG.test(r.tag_name || ''))
    .map(r => r.published_at)
    .sort()
    .pop();
}

function parsePropsNames(body) {
  const match = body.match(/Props ([^.]+)\./);
  if (!match) return [];
  return match[1].split(', ').map(n => n.trim()).filter(Boolean);
}

function sortProps(names, sortLast) {
  const unique = [...new Set(names)];
  if (!sortLast) return unique;
  return [...unique.filter(n => n !== sortLast), ...(unique.includes(sortLast) ? [sortLast] : [])];
}

// Mirrors the props-bot comment layout so the props line is a copyable code
// block rather than prose.
function buildComment(names) {
  return [
    MARKER,
    '',
    'Core Committers: Use this line as a base for the props when committing in SVN:',
    '',
    '```',
    `Props ${names.join(', ')}.`,
    '```',
  ].join('\n');
}

async function run({ github, context, core, env = process.env }) {
  const { owner, repo } = context.repo;
  const sortLast = env.PROPS_SORT_LAST || '';

  // 1. Resolve the release PR. release-please only reports it on runs where it
  //    touched the PR, so fall back to whichever release PR is open. Without
  //    this, contributors merged after the last touch are never aggregated.
  let prNumber = Number(env.PR_NUMBER);
  if (!prNumber) {
    const { data: openPRs } = await github.rest.pulls.list({
      owner, repo, state: 'open', base: 'main', per_page: 100,
    });
    prNumber = openPRs.find(pr => pr.head.ref.startsWith(RELEASE_BRANCH))?.number ?? 0;
  }
  if (!prNumber) { core.info('No open release PR; skipping comment.'); return; }

  // 2. Get cutoff from the latest published plugin release.
  const { data: releases } = await github.rest.repos.listReleases({ owner, repo, per_page: 100 });
  const cutoff = findCutoff(releases);

  // 3. List merged PRs since cutoff, skipping the release PR and bot-managed branches.
  const allClosed = await github.paginate(
    github.rest.pulls.list,
    { owner, repo, state: 'closed', base: 'main', per_page: 100 }
  );
  const mergedPRs = allClosed.filter(pr =>
    pr.merged_at &&
    pr.number !== prNumber &&
    !pr.head.ref.startsWith(RELEASE_BRANCH) &&
    !pr.head.ref.startsWith('docs/add-') &&
    (!cutoff || pr.merged_at >= cutoff)
  );

  // 4. Collect props from each merged PR's latest props-bot comment.
  const allNames = (
    await Promise.all(
      mergedPRs.map(pr =>
        github.rest.issues.listComments({ owner, repo, issue_number: pr.number, per_page: 100 })
      )
    )
  ).flatMap(({ data: comments }) => {
    const propsComment = comments.findLast(
      c => c.user.login === 'github-actions[bot]' && c.body.includes('Props ')
    );
    return propsComment ? parsePropsNames(propsComment.body) : [];
  });

  if (allNames.length === 0) {
    core.info('No props found across merged PRs; skipping comment.');
    return;
  }

  // 5. Deduplicate and sort.
  const sorted = sortProps(allNames, sortLast);

  // 6. Find or create sticky comment on the release PR.
  const { data: releaseComments } = await github.rest.issues.listComments({
    owner, repo, issue_number: prNumber,
  });
  const existing = releaseComments.find(c => c.body.includes(MARKER));
  const body = buildComment(sorted);

  if (existing) {
    await github.rest.issues.updateComment({ owner, repo, comment_id: existing.id, body });
  } else {
    await github.rest.issues.createComment({ owner, repo, issue_number: prNumber, body });
  }
}

module.exports = run;
module.exports.findCutoff = findCutoff;
module.exports.parsePropsNames = parsePropsNames;
module.exports.sortProps = sortProps;
module.exports.buildComment = buildComment;
module.exports.MARKER = MARKER;
