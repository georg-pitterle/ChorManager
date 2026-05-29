---
name: squash-branch-commits
description: Squash all commits on the current branch since it diverged from main into one commit with a supplied or suggested one-line commit message.
---

# squash-branch-commits

Use when you want to collapse the current branch history into a single clean commit before finalizing work.

Rules:
1. Determine the branch divergence point from `main` and identify commits only present on the current branch.
2. Expect a single parameter: a one-line commit message.
3. If no message is provided, suggest a concise one based on the changes made in german.
4. Perform the squash using an interactive rebase or equivalent Git command so the branch ends with exactly one commit since `main`.
5. Never push or interact with remote repositories; keep the change local.
6. Report the exact git command(s) executed and the resulting branch state.
7. If there are no commits to squash, report that the branch is already in sync with `main`.
8. Keep output short, clear, and focused on the squash action.
9. Exit if there are uncommitted changes in the working directory to avoid data loss, and report this to the user.
