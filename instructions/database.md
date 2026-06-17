# Database

- All schema changes must be done via Phinx migrations.
- Default migration command: `ddev exec ./vendor/bin/phinx migrate`.
- Agents should run migrations automatically for schema changes.
- Agents must report migration outcome (success or error with cause).
- Ask before running migrations only if:
  - environment is production or unclear,
  - migration is destructive/potentially destructive,
  - access/connectivity is missing.
