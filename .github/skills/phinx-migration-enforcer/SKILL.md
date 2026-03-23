---
name: phinx-migration-enforcer
description: Enforce schema changes through Phinx migrations and run migration commands with reporting.
---

# phinx-migration-enforcer

Use for any database schema change.

Rules:
- All schema changes must be done via Phinx migrations.
- Default command: `ddev exec ./vendor/bin/phinx migrate`.
- Run migrations automatically for schema changes.
- Always report migration outcome (success or error with cause).
- Ask before running only if environment is production/unclear, migration is destructive/potentially destructive, or DB access is missing.
