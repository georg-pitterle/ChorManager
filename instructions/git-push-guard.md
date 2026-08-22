# Git Push Guard

## Default: no push

- Never execute `git push` or any variant (e.g. `git push --force`, `git push origin`).
- Pushing to remote repositories is a manual action reserved for the developer.
- Stop after the local commit and inform the user.

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
