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

## Checklist before finishing

- Every `$this->table(...)` chain ends in `create()`, `save()` or `update()`.
  Without it the action is queued and silently never runs.
  `tests/Unit/Migrations/MigrationChainCompletionTest` checks this statically.
- Every `DROP COLUMN` / `DROP TABLE` / `MODIFY ... NOT NULL` is preceded by a
  completeness check that aborts with a `RuntimeException`, and that check runs
  **before** the destructive statement, never after.
- A `down()` that restores a column whose values now live elsewhere writes those
  values back before the owning table is dropped by an earlier migration's `down()`.
- Reference patterns: `20260421120000_drop_songs_project_id` (guard),
  `20260820120000_require_finance_account_on_finances` (guard),
  `20260513220000_add_newsletter_recipient_sources` (restore in `down()`).

Details and rationale: `instructions/database.md`.
