---
applyTo: "**"
---

# Git Push Guard

- The agent must never execute `git push` or any variant of it (e.g. `git push --force`, `git push origin`).
- Pushing to remote repositories is a manual action reserved for the developer.
- If a task would normally conclude with a push, stop after the local commit and inform the user instead.
