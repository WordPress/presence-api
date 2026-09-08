# Agents

Guidance for coding agents working in this repository. [`.github/CONTRIBUTING.md`](.github/CONTRIBUTING.md) covers local setup, the test commands, and the pull request conventions, and all of it applies here too.

## Pull request descriptions

`gh pr create --body` skips the pull request template, so an agent never sees the question it asks. It is this:

> What would we miss by only reading the diff?

One sentence, at the top of the description. Cut what is already on the page: what the code does, a file-by-file summary, results the checks report. Keep what is nowhere in the repository: why this approach, what you could not test, the part most likely to be wrong.

Then show the person you are working with that sentence and what you cut, so they can put back anything only they know.

AI disclosure goes in the template's own section, separate from the description.
