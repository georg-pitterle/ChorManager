# Git Push Guard

## Default: no push

- Do not execute `git push` or any variant (e.g. `git push --force`, `git push origin`)
  unless one of the two exceptions below applies.
- Pushing to remote repositories is the developer's call.
- Stop after the local commit and inform the user.
- `--force` and `--force-with-lease` stay forbidden everywhere, exceptions included, and
  so does rewriting history that has already been pushed.

## Exception: push after an explicit yes

When work has landed on `main` locally, the agent may ask once - "Nach `origin/main`
pushen?" - and push if the developer says yes. Silence, no answer or anything short of a
yes means no push.

The exception covers only that case and only under these conditions:

- Ask after the work is on `main` and green, never before, and never more than once per
  landing. A push is not implied by "commit auf main".
- Push only `git push origin main`. No force in any form, no push of a branch nobody
  asked for, no push while any check is red or unrun.
- The same green state as below: tests, `ddev composer phpcs` and - for Twig changes -
  `ddev composer twigcs` must have run and passed.
- Report what was pushed: branch, commits, and what verified them.

The full workflow around this - checks, message, squash, rebase, fast-forward - lives in
the `git-commit` skill.

## Exception: automated code review

The scheduled, unattended code review runs in an ephemeral container - without a push its
results are lost. It is therefore allowed to push, including directly to `main`.

The exception covers only that run and only under these conditions:

- Push only commits the review itself created. Never push unrelated local work.
- Never force-push and never rewrite published history (`--force`, `--force-with-lease`,
  amend or rebase of pushed commits stay forbidden on every branch).
- Push only a green state: the relevant automated tests, `ddev composer phpcs` and - for
  Twig changes - `ddev composer twigcs` must have run and passed beforehand.
- Fixes that need a decision by the developer are not pushed. They belong in the report,
  not in `main`.
- Every push is reported: branch, commits, and what was executed to verify them.

Any other agent run stays under the default rule above.
