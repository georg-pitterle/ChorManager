# Git Push Guard

- Never execute `git push` or any variant (e.g. `git push --force`, `git push origin`).
- Pushing to remote repositories is a manual action reserved for the developer.
- Stop after the local commit and inform the user.
