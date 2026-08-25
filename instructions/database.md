# Database

- All schema changes must be done via Phinx migrations.
- Default migration command: `ddev exec ./vendor/bin/phinx migrate`.
- Agents should run migrations automatically for schema changes.
- Agents must report migration outcome (success or error with cause).
- Ask before running migrations only if:
  - environment is production or unclear,
  - migration is destructive/potentially destructive,
  - access/connectivity is missing.

## Destruktive Schritte absichern

Gilt für jedes `DROP COLUMN`, `DROP TABLE` und jedes `MODIFY` auf `NOT NULL`.

- Vor dem destruktiven Schritt prüfen, ob die Umschreibung vollständig ist, und
  sonst mit `RuntimeException` abbrechen. Muster: `20260421120000_drop_songs_project_id`
  zählt Songs ohne passende Zuordnung, `20260820120000_require_finance_account_on_finances`
  zählt Buchungen ohne Konto.
- Die Prüfung steht **vor** den `DROP`s, nie danach. Greift sie erst danach, sind
  die Daten schon weg und der Lauf endet auf halbem Weg — nachholen lässt sich das
  nicht, weil Phinx den Eintrag in `phinxlog` bereits gesetzt bzw. entfernt hat.
  (So passiert in `20260421100000_add_repertoire_tables`, dort inzwischen korrigiert.)
- Nimmt ein `down()` eine Spalte zurück, deren Werte inzwischen woanders stehen,
  müssen sie zurückgeschrieben werden. Muster: `20260513220000` für
  `newsletters.event_id`, `20260722130000` für `events.project_id`.

## Phinx-Ketten abschließen

`addColumn()`, `removeColumn()`, `addIndex()`, `drop()` und Konsorten reihen die
Aktion nur ein. Ausgeführt wird sie erst durch `create()`, `save()` oder `update()`.
Fehlt der Abschluss, meldet der Lauf trotzdem Erfolg und die Änderung findet nie
statt. `tests/Unit/Migrations/MigrationChainCompletionTest` prüft das statisch für
alle Migrationen.
