'use strict';

const MARKER = '<!-- presence-api:release-props -->';

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

function buildComment(names) {
  return `${MARKER}\n\nProps ${names.join(', ')}.`;
}

async function run({ github, context, core, env = process.env }) {
  const { owner, repo } = context.repo;
  const prNumber = Number(env.PR_NUMBER);
  if (!prNumber) { core.setFailed('PR_NUMBER is missing or not a valid number'); return; }
  const sortLast = env.PROPS_SORT_LAST || '';

  // 1. Get cutoff from latest published release.
  const { data: releases } = await github.rest.repos.listReleases({ owner, repo, per_page: 1 });
  const cutoff = releases[0]?.published_at;

  // 2. List merged PRs since cutoff, skipping the release PR and bot-managed branches.
  const allClosed = await github.paginate(
    github.rest.pulls.list,
    { owner, repo, state: 'closed', base: 'main', per_page: 100 }
  );
  const mergedPRs = allClosed.filter(pr =>
    pr.merged_at &&
    pr.number !== prNumber &&
    !pr.head.ref.startsWith('release-please--') &&
    !pr.head.ref.startsWith('docs/add-') &&
    (!cutoff || pr.merged_at >= cutoff)
  );

  // 3. Collect props from each merged PR's latest props-bot comment.
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

  // 4. Deduplicate and sort.
  const sorted = sortProps(allNames, sortLast);

  // 5. Find or create sticky comment on the release PR.
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
module.exports.parsePropsNames = parsePropsNames;
module.exports.sortProps = sortProps;
module.exports.buildComment = buildComment;
module.exports.MARKER = MARKER;
