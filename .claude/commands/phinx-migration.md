# Phinx Migration Enforcer

Enforce all schema changes through Phinx migrations and run them with proper reporting.

## Use when
Any database schema change is required (new table, column, index, foreign key, or drop).

## Do not use when
The change is purely application logic with no schema modification.

## Rules
- All schema changes must be done via Phinx migrations — never modify the schema directly.
- Default migration command: `ddev exec ./vendor/bin/phinx migrate`
- Run migrations automatically for schema changes.
- Always report migration outcome (success or error with cause).
- Ask before running migrations only if:
  - the environment is production or unclear
  - the migration is destructive or potentially destructive
  - DB access/connectivity is missing
