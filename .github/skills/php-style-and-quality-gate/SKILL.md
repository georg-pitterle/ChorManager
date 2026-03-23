---
name: php-style-and-quality-gate
description: Apply PSR-12 and repository formatting limits, and run style checks for substantial changes.
---

# php-style-and-quality-gate

Use for substantial PHP changes.

Rules:
- Follow PSR-12.
- Use 4 spaces, no tabs.
- Line length: soft 120, hard 130.
- Run style checks for substantial changes:
  - `ddev composer phpcs`
  - `ddev composer phpcbf` (if needed)
