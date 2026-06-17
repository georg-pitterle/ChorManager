# Squash Branch Commits

Squash all commits on the current branch since it diverged from `main` into one clean commit.

## Use when
You want to collapse the current branch history into a single clean commit before finalizing work.

## Do not use when
The branch has already been pushed to a shared remote and others may depend on the commit history.

## Steps
1. Check for uncommitted changes — exit and report if any exist to avoid data loss.
2. Determine the branch divergence point from `main`.
3. Identify all commits on the current branch not present on `main`.
4. If there are no commits to squash, report that the branch is already in sync with `main` and stop.
5. Expect a single parameter: a one-line commit message.
   - If no message is provided, suggest a concise one in German based on the changes.
6. Squash using `git rebase` so the branch ends with exactly one commit since `main`.
7. Never push or interact with remote repositories — keep the change local.
8. Report the exact git commands executed and the resulting branch state.
