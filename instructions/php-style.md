# PHP Style and Quality Gate

Applies to: `**/*.php`

- Follow PSR-12.
- Use 4 spaces and no tabs.
- Keep line length at a soft limit of 120 and a hard limit of 130.
- For substantial PHP changes, run `ddev composer phpcs`.
- If formatting fixes are needed, run `ddev composer phpcbf`.
- Format also files that are not changed.
