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
- Every `DROP COLUMN` / `DROP TABLE` / `MODIFY ... NOT NULL` / `DELETE FROM` /
  `TRUNCATE` is preceded by a completeness check that aborts with a
  `RuntimeException`, and that check runs **before** the destructive statement,
  never after. Exempt is only a **column** the same method body created itself -
  a `removeColumn()` in `down()` on a column its `up()` added.
  `tests/Unit/Migrations/DestructiveStepNeedsGuardTest` checks this statically
  for every migration; `tests/Feature/DestructiveMigrationGuardTest` runs the
  count queries of the guarded ones against throwaway tables.
- **Eine Tabelle zu löschen ist nie ausgenommen, auch nicht im eigenen `down()`.**
  Dass das `up()` sie angelegt hat, bedeutet nur, dass sie *am Anfang* leer war -
  ihr Inhalt kam im Betrieb dazu und steht nirgends sonst. Ein `drop()` oder
  `DELETE FROM` im `down()` zählt deshalb vorher die Zeilen und bricht ab, wenn
  welche drin sind. Dasselbe gilt für `TRUNCATE`.
- **Gezählt wird, was wirklich verloren geht - nicht stumpf alles.** Schreibt das
  `down()` die Werte vorher woandershin zurück, zählt die Prüfung nur den Rest;
  und Zeilen, die das `up()` selbst eingesetzt hat (Vorgabewerte, Stammdaten),
  zählen nicht mit. Ohne diese beiden Einschränkungen wäre eine frische
  Installation nie mehr zurückzubauen, weil die Prüfung sofort auf die eigenen
  Vorgaben anschlägt.
- A `down()` that restores a column whose values now live elsewhere writes those
  values back before the owning table is dropped by an earlier migration's `down()`.
- Reference patterns: `20260421120000_drop_songs_project_id` (guard),
  `20260820120000_require_finance_account_on_finances` (guard),
  `20260513220000_add_newsletter_recipient_sources` (restore in `down()`),
  `20261005090100_add_channel_to_user_notification_settings` (guard before
  deleting rows in `down()`),
  `20261005090000_create_user_notifications` (bloße Zeilenzählung vor dem
  `drop()` im `down()`),
  `20260830120000_allow_multiple_task_assignees` (zählt nur die Zuweisungen, die
  der Rückbau nicht unterbringt),
  `20260815140000_create_finance_accounts` (lässt die unberührten Vorgabekonten
  aus der Zählung heraus),
  `20260314130000_initial` (zählt den Bestand, nicht die eigenen Vorgaben).

## Warum die Reihenfolge zählt

**Die Prüfung steht vor den `DROP`s, nie danach.** Greift sie erst danach, sind die
Daten schon weg und der Lauf endet auf halbem Weg — nachholen lässt sich das nicht,
weil Phinx den Eintrag in `phinxlog` bereits gesetzt bzw. entfernt hat. So geschehen
in `20260421100000_add_repertoire_tables`, dort inzwischen korrigiert.

**Nimmt ein `down()` eine Spalte zurück, deren Werte inzwischen woanders stehen,
müssen sie zurückgeschrieben werden.** Muster: `20260513220000` für
`newsletters.event_id`, `20260722130000` für `events.project_id`.

**Zeilen zu löschen zählt mit.** `DELETE FROM` und `TRUNCATE` nehmen keine
Struktur, aber Inhalt - und der ist genauso weg. Die Regel nannte lange nur
Spalten und Tabellen; aufgefallen ist die Lücke in Lauf 41, als der Rückbau von
`20261005090100` die Glocken-Einstellungen aller Mitglieder still gelöscht
hätte. Ausnahme bleibt, was ohne Wert ist: Zeilen, die über keinen Weg mehr
auffindbar sind, dürfen ungeprüft weg - begründet im Kommentar, wie in
`20260901122000_drop_plaintext_calendar_subscription_tokens`.

**"Die Tabelle hat mein `up()` doch selbst angelegt" trägt nicht.** Das stimmt am
Tag der Migration und wird mit jedem Betriebstag falscher: Angelegt hat sie das
`up()`, gefüllt haben sie die Mitglieder. Aufgefallen ist die Lücke in Lauf 48 am
`down()` von `20261012120000`, das die ausgehandelten Gegenleistungen und
Zahlungsstände aller Sponsoren mitgenommen hätte - Werte, die in keiner anderen
Spalte stehen und nach dem Rückbau aus nichts zurückzuholen sind. Die Ausnahme
gilt seither nur noch für Spalten; jedes `drop()` auf eine Tabelle zählt vorher
ihre Zeilen.

Dabei zählt nur, was wirklich verloren geht. Zwei Dinge gehören ausdrücklich
nicht in die Zählung, sonst blockiert die Prüfung den Rückbau einer frischen
Installation: die Zeilen, die das `up()` selbst eingesetzt hat
(`20260314130000_initial` mit Rollen und Stimmgruppen, `20260815140000` mit den
zwei Vorgabekonten), und alles, was das `down()` vorher woandershin
zurückschreibt (`20260830120000` legt je Aufgabe eine Zuweisung zurück nach
`tasks.assigned_to`, `20260621120000` die Gruppennamen nach
`budget_categories.group_name`). Die Zählabfrage dieser Fälle steht als
`public const` in der Migration, damit `DestructiveMigrationGuardTest` genau die
Anweisung gegen Wegwerf-Tabellen prüfen kann, die auch läuft.

**Warum Spalten ausgenommen bleiben.** Für eine Spalte gilt dasselbe Argument -
`sponsorships.paid_at` war in Lauf 48 Teil desselben Befundes, und 29 weitere
Migrationen nehmen im `down()` eine Spalte zurück, deren Werte erst im Betrieb
entstanden sind. Nachgerüstet wird das trotzdem nicht; so entschieden in der
Nacharbeit zu Lauf 48.

Der Grund ist das Prädikat. Bei einer Tabelle ist "ist da was drin" eindeutig,
bei einer Spalte nicht: Was bei `paid_at` `IS NOT NULL` heißt, heißt bei
`event_types.is_highlighted` `= 1` und bei einem Freitextfeld `<> ''`. Ein
pauschaler statischer Zwang erzeugt damit reihenweise Prüfungen, deren Prädikat
jemand von Hand richtig raten muss - genau die toten Prüfungen, aus denen
`DestructiveStepNeedsGuardTest` schon `MODIFY ... NOT NULL` heraushält.

Trägt eine Spalte erkennbar eingetragenen Inhalt - ein Datum, einen Betrag,
einen Freitext -, ist eine Zählabfrage davor willkommen; `20261012120000` zählt
`paid_at` aus diesem Grund mit. Pflicht ist sie nicht, und kein Test verlangt
sie. Bei einem Schalter mit Vorgabewert wäre sie ohnehin nur Zeremonie.

**Phinx-Ketten abschließen.** `addColumn()`, `removeColumn()`, `addIndex()`, `drop()`
und Konsorten reihen die Aktion nur ein. Ausgeführt wird sie erst durch `create()`,
`save()` oder `update()`. Fehlt der Abschluss, meldet der Lauf trotzdem Erfolg und die
Änderung findet nie statt.
