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

## Warum die Reihenfolge zählt

**Die Prüfung steht vor den `DROP`s, nie danach.** Greift sie erst danach, sind die
Daten schon weg und der Lauf endet auf halbem Weg — nachholen lässt sich das nicht,
weil Phinx den Eintrag in `phinxlog` bereits gesetzt bzw. entfernt hat. So geschehen
in `20260421100000_add_repertoire_tables`, dort inzwischen korrigiert.

**Nimmt ein `down()` eine Spalte zurück, deren Werte inzwischen woanders stehen,
müssen sie zurückgeschrieben werden.** Muster: `20260513220000` für
`newsletters.event_id`, `20260722130000` für `events.project_id`.

**Phinx-Ketten abschließen.** `addColumn()`, `removeColumn()`, `addIndex()`, `drop()`
und Konsorten reihen die Aktion nur ein. Ausgeführt wird sie erst durch `create()`,
`save()` oder `update()`. Fehlt der Abschluss, meldet der Lauf trotzdem Erfolg und die
Änderung findet nie statt.
