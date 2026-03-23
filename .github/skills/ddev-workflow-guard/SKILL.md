---
name: ddev-workflow-guard
description: Ensure project commands use DDEV and remain Windows PowerShell friendly.
---

# ddev-workflow-guard

Use for all project command execution.

Rules:
- Use DDEV for project commands.
- On Windows host, prefer PowerShell-compatible commands.
- Inside DDEV/container, use project-appropriate shell commands.
