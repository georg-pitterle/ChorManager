# Kasse: mehrere Gruppen pro Buchung – Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Eine Buchung der Kasse trägt beliebig viele Gruppen, höchstens eine davon budgetrelevant im
Geschäftsjahr der Buchung; Gruppen lassen sich in der Buchungsliste per Stapelbearbeitung hinzufügen
und entfernen.

**Architecture:** Neue Zuordnungstabelle `finance_group_assignments` (Buchung–Gruppe), befüllt aus den
bisherigen Spalten; alle Lese- und Schreibwege wechseln darauf, danach entfallen `finances.group_name`
und `finances.finance_group_id`. Die Budget-Regel steckt in einer eigenen Klasse
`FinanceGroupBudgetRule`, die `FinanceBookingService` (Speichern, Import, Stapel) und ein neuer
`BudgetWriteService` (Kategorie anlegen/umhängen) aufrufen.

**Tech Stack:** PHP 8 / Slim 4 / Eloquent (Illuminate) / Twig / Phinx / PHPUnit / Tom Select
(lokal, `public/js/tom-select-init.js`) / Bootstrap 5.

**Spec:** `docs/superpowers/specs/2026-11-22-finance-multiple-groups-design.md`

## Global Constraints

- Schemaänderungen nur per Phinx; destruktive Schritte mit vorgeschalteter Zählprüfung (`/phinx-migration`), Muster `20260830120000_allow_multiple_task_assignees`.
- Kein Schreibzugriff und keine Listenabfrage im Controller (`instructions/data-access.md`); die Sperrklinke in `tests/Unit/Controllers/ControllerDataAccessTest.php` wird mitgezogen, sobald `FinanceController`/`BudgetController` Zugriffe abgeben.
- Bezeichner englisch, Texte/Kommentare deutsch mit echten Umlauten (`instructions/naming.md`).
- Keine Inline-Skripte/-Styles, nur lokale Assets; Twig: doppelte Anführungszeichen, `ddev composer twigcs`.
- PHP: `ddev composer phpcs` grün; Zeilenlänge 130.
- Tests vor dem Code (TDD), Datenbanktests in Transaktion über `Tests\Unit\Bootstrap` (`/writing-tests`); Schärfeprobe je Regeltest, Ergebnis in die Commit-Nachricht.
- Gefilterte Testläufe während der Arbeit (`ddev php vendor/bin/phpunit --filter "<Klasse>"`), volle Suite (`ddev composer test:parallel`) einmal am Ende.
- Alle Dateien LF.
- Nach jedem Task: Checkboxen dieses Plans im selben Commit abhaken.
- Kein Hilfethema.

## Review Focus

1. **Gruppenname mit Groß-/Kleinschreibung oder Leerzeichen-Varianten** („Konzert“, „konzert “): Muss auf dieselbe Gruppe treffen wie heute `firstOrCreate` mit `utf8mb4_general_ci`, nicht eine Dublette erzeugen oder doppelt zuordnen. → Test in Task 2.
2. **Dieselbe Gruppe zweimal im Formular** (Mehrfachauswahl + Neuanlage gleichen Namens): Eine Zuordnung, kein Fehler am UNIQUE-Index. → Test in Task 2.
3. **Offener Posten ohne Zahldatum**: Geschäftsjahr aus dem Rechnungsdatum; wird er später mit Zahldatum in ein anderes Jahr bezahlt, prüft die Regel das neue Jahr. → Test in Task 3.
4. **Stapelaktion über manipulierte IDs** (fremde, nicht existierende, doppelte IDs im POST): Nicht existierende werden ignoriert, doppelte einmal behandelt, nichts wirft. → Test in Task 6.
5. **Altes Journal**: Einträge mit `group_name`/`finance_group_id` zeigen nach dem Spalten-Drop weiterhin lesbare Namen. → Test in Task 2.

---

### Task 1: Zuordnungstabelle anlegen und befüllen

**Files:**
- Create: `db/migrations/20261122120000_create_finance_group_assignments.php`
- Modify: `src/Models/Finance.php` (Beziehung `groups()`, Methode `groupNames()`)
- Test: `tests/Feature/FinanceGroupAssignmentBackfillFeatureTest.php`

**Interfaces:**
- Produces: Tabelle `finance_group_assignments` (`id`, `finance_id` → `finances.id` ON DELETE CASCADE, `finance_group_id` → `finance_groups.id` ON DELETE RESTRICT, UNIQUE `(finance_id, finance_group_id)`, `created_at`). Beide Ziel-`id`s sind `int(11)` signed (SQL-Zeit) – Spalten also signed.
- Produces: `Finance::groups(): BelongsToMany` (Pivot `finance_group_assignments`), `Finance::groupNames(): list<string>` – Namen der geladenen/zugeordneten Gruppen, alphabetisch (`strcasecmp`-natürlich, `SORT_NATURAL | SORT_FLAG_CASE`).
- Produces: `CreateFinanceGroupAssignments::BACKFILL_SQL` (öffentliche Konstante, die Übertrags-Anweisung).

- [x] **Step 1: Failing Test** `testBackfillLinksEveryBookingToItsFormerGroup`: Buchung A mit `finance_group_id`, Buchung B nur mit `group_name` (ohne ID, Gruppe existiert), Buchung C ohne Gruppe. `BACKFILL_SQL` ausführen (Tabelle ist nach `phinx migrate` vorhanden; Testdaten in Transaktion). Zusicherung: A und B haben je genau eine Zuordnung zur richtigen Gruppe, C keine; zweimal ausführen erzeugt keine Dubletten (`INSERT IGNORE`).
- [x] **Step 2: Failing Test** `testGroupNamesAreSortedAlphabetically`: Buchung mit Gruppen „reise“, „Konzert“, „Ausflug 2“ → `['Ausflug 2', 'Konzert', 'reise']`.
- [x] **Step 3: Run** `ddev php vendor/bin/phpunit --filter FinanceGroupAssignmentBackfillFeatureTest` → FAIL (Tabelle/Konstante fehlt).
- [x] **Step 4: Migration schreiben.** `up()`: Tabelle anlegen, dann `BACKFILL_SQL`: Zuordnung aus `finance_group_id`, für Zeilen ohne ID per Namensabgleich `finance_groups.name = finances.group_name COLLATE utf8mb4_general_ci`. `down()`: Zählprüfung vor dem `drop()` – gezählt werden nur Zuordnungen, die die alten Spalten nicht aufnehmen können (Buchungen mit mehr als einer Gruppe sowie Zuordnungen, die von `finances.finance_group_id` abweichen); bei > 0 `RuntimeException`. Die Zählabfrage als `public const SURPLUS_ASSIGNMENTS_SQL`, Fall in `tests/Feature/DestructiveMigrationGuardTest.php` ergänzen (Paar „findet“/„bleibt still“ wie `testAssigneeGuard…`).
- [x] **Step 5: Migration ausführen** `ddev exec ./vendor/bin/phinx migrate` → Erfolg melden. `ddev exec ./vendor/bin/phinx migrate -e testing` bzw. wie in `/test-runs` beschrieben für die Testdatenbanken.
- [x] **Step 6: Beziehung und `groupNames()` am Modell.** `group_name`/`finance_group_id` bleiben vorerst im `$fillable`.
- [x] **Step 7: Run** `ddev php vendor/bin/phpunit --filter "FinanceGroupAssignmentBackfillFeatureTest|DestructiveMigrationGuardTest|MigrationChainCompletionTest|DestructiveStepNeedsGuardTest"` → PASS.
- [x] **Step 8: Commit** (Plan-Checkboxen mit abhaken).

### Task 2: Alle Lese- und Schreibwege auf die Zuordnung umstellen

Ein Task, weil Leser und Schreiber nur gemeinsam konsistent sind: Danach liest und schreibt nichts mehr
`group_name`/`finance_group_id` außer dem Journal für Alteinträge.

**Files:**
- Modify: `src/Services/FinanceBookingService.php` (`save`, `importRows`, `reverse`, private `resolveGroupIds`)
- Modify: `src/Services/FinanceJournalService.php` (Feld `groups`)
- Modify: `src/Services/BudgetService.php` (`computeActual`, `computeReversed`)
- Modify: `src/Services/FinanceCsvExportService.php` (Spalte „Gruppe“)
- Modify: `src/Controllers/FinanceController.php` (`save`, `importConfirm`, `report`, `index`: `with('groups')`)
- Modify: `templates/finances/index.twig`, `templates/finances/report.twig`, `public/js/finances.js` (Anzeige, Sortierwert, Dialog-Vorbelegung)
- Test: `tests/Feature/FinanceGroupAssignmentsFeatureTest.php`; bestehende Tests in `tests/Feature/Finance*`, `BudgetFinanceGroupLinkTest`, `BudgetServiceBehaviorTest`, `FinanceCsvExportServiceTest` auf Zuordnungen umstellen

**Interfaces:**
- Consumes: `Finance::groups()`, `Finance::groupNames()` (Task 1).
- Produces: `FinanceBookingService::save(?int $id, array $recordData, list<string> $groupNames, ?int $actorId): ?Finance` – `$recordData` enthält kein `group_name`/`finance_group_id` mehr; `null` heißt weiterhin „gesperrt“.
- Produces: `importRows(array $rows, FinanceAccount $account, ?int $actorId): int` – jede Zeile trägt `group_names: list<string>` statt `group_name`.
- Produces: Journalfeld `groups` (Label „Gruppen“), Wert = `implode('; ', groupNames())` oder `null` ohne Gruppen.
- Produces: `FinanceBookingService::syncGroups(Finance $finance, list<int> $groupIds): void` (privat oder intern, wird von Task 6 wiederverwendet).

- [x] **Step 1: Failing Tests** in `FinanceGroupAssignmentsFeatureTest`:
  - `testSavingABookingStoresAllGivenGroups`: `save(null, …, ['Konzert', 'Herbstkonzert 2026'], $uid)` → beide Zuordnungen; unbekannter Name legt die Gruppe an.
  - `testGroupNamesDifferingOnlyInCaseOrSpacesHitTheSameGroup` (Review Focus 1): Gruppe „Konzert“ existiert; `['konzert ', 'Konzert']` → genau eine Zuordnung zur bestehenden Gruppe, keine neue Gruppe.
  - `testUpdatingReplacesTheGroupsAndJournalsOneGroupsChange`: von `['A','B']` auf `['B','C']` → Zuordnungen B, C; ein Journaleintrag mit Feld `groups`, `from` „A; B“, `to` „B; C“. Unveränderte Gruppen → kein `groups`-Eintrag.
  - `testReversalCopiesAllGroupsOfTheOriginal`.
  - `testImportedRowsGetTheirGroups`.
  - `testBudgetActualCountsBookingsByAssignment`: Buchung mit Gruppen X (budgetiert) und Y → `computeActual(X…)` enthält den Betrag, `computeActual(Y…)` ebenso (die Regel kommt erst in Task 3).
  - `testReportTotalsCountABookingInEachOfItsGroups`: Bericht rendert Gruppensummen; 100 € in A+B → A 100, B 100; Hinweistext „Eine Buchung mit mehreren Gruppen zählt in jeder dieser Gruppen. Die Summe der Gruppen kann deshalb vom Kassenergebnis abweichen.“ ist vorhanden, sobald eine Buchung mehr als eine Gruppe hat (sonst nicht).
  - `testCsvJoinsGroupsAlphabetically`: „B; A“ → Zelle `A; B`.
  - `testOldJournalEntriesStillShowGroupNames` (Review Focus 5): Revision mit `finance_group_id` von/zu IDs → `describeChanges` liefert die Gruppennamen.
- [x] **Step 2: Run** `ddev php vendor/bin/phpunit --filter FinanceGroupAssignmentsFeatureTest` → FAIL.
- [x] **Step 3: `FinanceBookingService` umbauen.** `resolveGroupIds(list<string>): list<int>`: trimmen, Leere verwerfen, case-insensitiv deduplizieren, `FinanceGroup::firstOrCreate(['name' => …])`, IDs eindeutig. Zuordnung schreiben über `$finance->groups()->sync($ids)` innerhalb der bestehenden Transaktion; Journal-Snapshot vor/nach inkl. `groups`. Storno: `$reversal->groups()->sync($original->groups->pluck('id'))`.
- [x] **Step 4: Journal.** `TRACKED_FIELDS`: `group_name`/`finance_group_id` durch `groups` ersetzen; `snapshot()` liest `groups` aus `groupNames()`. `FIELD_LABELS` und `formatValue` behalten `group_name`/`finance_group_id` für Alteinträge, `groups` → „Gruppen“.
- [x] **Step 5: Budget, CSV, Bericht, Liste.** `computeActual`/`computeReversed`: `whereHas('groups', fn($q) => $q->whereKey($financeGroupId))`. Bericht: je Buchung über `groupNames()`, ohne Gruppe „(Keine Gruppe)“; Hinweis in `report.twig` und im PDF unter der Gruppentabelle. Liste: ein Badge je Gruppe; `data-sort-group_name` = verkettete Namen in Kleinbuchstaben; JS-Daten `item.group_names` (Array) statt `item.group_name`.
- [x] **Step 6: Controller.** `save`: liest vorerst weiter das Einzelfeld `group_name` und reicht `[$name]` bzw. `[]` an `save()` (Mehrfachauswahl folgt in Task 4). `importConfirm`: `group_names = [$name]` bzw. `[]` (folgt in Task 5).
- [x] **Step 7: Bestehende Tests nachziehen**, die Gruppen über die Spalten anlegen oder prüfen (`grep -rln "group_name\|finance_group_id" tests/` – Budget-Kategorien behalten ihr `finance_group_id`!).
- [x] **Step 8: Run** `ddev php vendor/bin/phpunit --filter "Finance|Budget"` → PASS. `ddev composer phpcs`, `ddev composer twigcs` → grün.
- [x] **Step 9: Schärfeprobe** (z. B. `sync` auskommentieren, Sortierung in `groupNames()` entfernen) → rot, zurück → grün.
- [x] **Step 10: Commit.**

### Task 3: Budget-Regel „höchstens eine budgetrelevante Gruppe“

**Files:**
- Create: `src/Services/FinanceGroupBudgetRule.php`
- Create: `src/Services/FinanceGroupConflictException.php`
- Modify: `src/Services/FinanceBookingService.php` (Regel in `save` und `importRows`; Storno ausgenommen)
- Modify: `src/Dependencies.php` (Verdrahtung, falls nicht autowired)
- Test: `tests/Feature/FinanceGroupBudgetRuleFeatureTest.php`

**Interfaces:**
- Consumes: `BudgetService::getFiscalConfig()`, `BudgetService::datesForYear()`.
- Produces: `FinanceGroupBudgetRule::fiscalYearOf(?string $paymentDate, string $invoiceDate): int` – Startjahr des Geschäftsjahres; Zahldatum, sonst Rechnungsdatum.
- Produces: `FinanceGroupBudgetRule::conflictMessage(list<int> $groupIds, int $fiscalYear): ?string` – `null` ohne Konflikt, sonst: `Die Gruppen „{A}“ und „{B}“ sind im Geschäftsjahr {Jahr} beide im Budget. Eine Buchung darf nur zu einer davon gehören.` – A/B alphabetisch, die ersten beiden kollidierenden; `{Jahr}` = Startjahr, bei Geschäftsjahresbeginn ≠ 01.01. als `2026/27`.
- Produces: `FinanceGroupBudgetRule::conflictingBookingIds(int $groupId, int $fiscalYear): list<int>` – Buchungen des Jahres (Zahldatum im Jahr, bei offenen Posten Rechnungsdatum), die `$groupId` **und** eine andere in diesem Jahr budgetierte Gruppe tragen, ohne stornierte Buchungen und ohne Storno-Gegenbuchungen. Wird in Task 8 vor dem Anlegen einer Kategorie für `$groupId` aufgerufen.
- Produces: `FinanceGroupConflictException extends \RuntimeException` mit `public readonly array $rowMessages` (`array<int, string>`, Schlüssel = Index der Importzeile; leer beim Einzelspeichern).

- [x] **Step 1: Failing Tests:**
  - `testTwoBudgetedGroupsInTheSameFiscalYearConflict`: Kategorien für X und Y im Jahr 2026 (beliebige Art) → Meldung nennt beide und das Jahr.
  - `testABudgetedAndAnUnbudgetedGroupDoNotConflict`.
  - `testAGroupBudgetedOnlyInAnotherYearIsNotRelevant`: X in 2025 budgetiert, Y in 2026, Buchung 2026 mit X+Y → kein Konflikt.
  - `testTheTypeOfTheCategoryDoesNotMatter`: X nur als Einnahme, Y nur als Ausgabe budgetiert → Konflikt.
  - `testOpenItemsUseTheInvoiceDate` (Review Focus 3): ohne Zahldatum, Rechnungsdatum 2026 → Jahr 2026; mit Zahldatum 2027 → Jahr 2027. Mit Geschäftsjahresbeginn 01.07.: 15.03.2027 → 2026.
  - `testSavingAConflictingBookingThrowsAndWritesNothing`: `save()` wirft `FinanceGroupConflictException`, keine Buchung, kein Journal.
  - `testSavingUnchangedGroupsAndYearSkipsTheCheck`: Bestandsbuchung mit X+Y (direkt angelegt), Beschreibung ändern → gespeichert.
  - `testMovingABookingIntoAYearWithAConflictIsRejected`: Datum aus 2025 nach 2026, wo X und Y budgetiert sind → Exception.
  - `testAReversalIsExemptFromTheRule`: Original mit X+Y, Storno landet in Jahr mit beiden budgetiert → Storno wird angelegt.
  - `testImportRejectsTheWholeStatementAndNamesTheConflictingRows`: zwei Zeilen, Index 1 kollidiert → Exception mit `rowMessages = [1 => …]`, keine Buchung angelegt.
  - `testConflictingBookingIdsIgnoresReversalPairs`.
- [x] **Step 2: Run** `ddev php vendor/bin/phpunit --filter FinanceGroupBudgetRuleFeatureTest` → FAIL.
- [x] **Step 3: Regel implementieren.** Budgetierte Gruppen eines Jahres: `BudgetCategory::where('fiscal_year_start', $y)->pluck('finance_group_id')` (in der Regel-Klasse, nicht im Controller). In `save()`: Prüfung nur, wenn sich die Gruppen-ID-Menge oder `fiscalYearOf(...)` gegenüber dem Bestand ändert (bei Neuanlage immer). In `importRows()`: alle Zeilen vor dem ersten Schreiben prüfen, Meldungen sammeln, dann werfen.
- [x] **Step 4: Run** → PASS; Schärfeprobe (Jahresvergleich entfernen, Storno-Ausnahme entfernen, Reversal-Filter entfernen) → je rot.
- [x] **Step 5: Commit.**

### Task 4: Mehrfachauswahl im Buchungsdialog

**Files:**
- Modify: `templates/finances/index.twig` (Gruppenfeld ab Zeile ~674)
- Modify: `public/js/finances.js` (`handleGroupSelect`, Vorbelegung beim Bearbeiten/Duplizieren ~265/314)
- Modify: `public/js/tom-select-init.js` (Option `create` über `data-tom-select-create`)
- Modify: `src/Controllers/FinanceController.php` (`save`: `group_names[]`; Konfliktmeldung; `prepareNextBooking`)
- Test: `tests/Feature/FinanceBookingFormFeatureTest.php` (erweitern)

**Interfaces:**
- Consumes: `save(..., list<string> $groupNames, ...)`, `FinanceGroupConflictException` (Tasks 2, 3).
- Produces: Formularfeld `group_names[]` (Mehrfach-`select` mit Tom Select, neue Einträge erlaubt).

- [x] **Step 1: Failing Tests:** `testPostedGroupNamesAreAllStored` (POST mit `group_names[]=A&group_names[]=B` → zwei Zuordnungen); `testAConflictReopensTheDialogWithTheMessageAndTheEnteredGroups` (Konflikt → 302 zurück, `rejectBooking`-Zustand enthält Meldung und beide Gruppen, keine Buchung); `testTheDialogPreselectsAllGroupsOfARejectedForm` (Rendern mit Formularzustand → beide `option` `selected`).
- [x] **Step 2: Run** `--filter FinanceBookingFormFeatureTest` → FAIL.
- [x] **Step 3: UI-Entwurf mit Impeccable** (`/impeccable`): Gruppenfeld als Mehrfachauswahl mit Neuanlage im bestehenden Dialog, Handy und Desktop. Ersetzt das bisherige Select + „Neue Gruppe eingeben…“-Textfeld.
- [x] **Step 4: Controller und Template umsetzen**; `handleGroupSelect` und das Feld `group_name` entfallen. Optional: Budget-Gruppen des laufenden Jahres in der Auswahl mit Hinweis kennzeichnen, wenn es der Entwurf vorsieht.
- [x] **Step 5: Run** → PASS; `ddev composer twigcs`, `phpcs`; Sichtprüfung headless im ddev-Container (Screenshot Dialog Desktop + 390 px).
- [x] **Step 6: Commit.**

### Task 5: Mehrfachauswahl und Konfliktanzeige im Import

**Files:**
- Modify: `templates/finances/import.twig` (Zeile ~174: `group[index]` → `group_names[index][]`)
- Modify: `public/js/finance-import.js` (falls die Gruppenauswahl dort gesteuert wird)
- Modify: `src/Controllers/FinanceController.php` (`importConfirm`: Mehrfachgruppen; bei Konflikt Vorschau erneut rendern)
- Test: `tests/Feature/FinanceImportFeatureTest.php` (erweitern)

**Interfaces:**
- Consumes: `importRows` mit `group_names`, `FinanceGroupConflictException::$rowMessages`.

- [x] **Step 1: Failing Tests:** `testEachImportRowStoresAllChosenGroups`; `testAConflictingRowRerendersThePreviewWithTheChoicesAndMarksTheRow`: Antwort 422, Sitzungs-Payload bleibt erhalten, die gewählten Zeilen sind weiterhin angehakt, Gruppen vorausgewählt, die Konfliktzeile trägt die Meldung, keine Buchung angelegt.
- [x] **Step 2: Run** `--filter FinanceImportFeatureTest` → FAIL.
- [x] **Step 3: Umsetzen.** Das Rendern der Vorschau aus `importPreview` in eine private Methode `renderImportPreview(Response, array $payload, array $selected, array $postedGroups, array $rowMessages, int $status)` ziehen, die beide Wege nutzen. Gestaltung der Mehrfachauswahl je Zeile und der Zeilenmarkierung mit Impeccable, passend zu Task 4.
- [x] **Step 4: Run** → PASS; twigcs/phpcs; Sichtprüfung headless.
- [x] **Step 5: Commit.**

### Task 6: Stapelbearbeitung – Schreibweg und Route

**Files:**
- Create: `src/Services/FinanceBulkGroupResult.php`
- Modify: `src/Services/FinanceBookingService.php` (`bulkChangeGroup`)
- Modify: `src/Controllers/FinanceController.php` (`bulkGroups`)
- Modify: `src/routes.php` (Schreibgruppe der Kasse: `POST /finances/bulk-groups`)
- Test: `tests/Feature/FinanceBulkGroupFeatureTest.php`

**Interfaces:**
- Consumes: `FinanceGroupBudgetRule`, `syncGroups`, Journal (Tasks 2, 3).
- Produces: `FinanceBookingService::bulkChangeGroup(list<int> $financeIds, string $groupName, string $mode, ?int $actorId): FinanceBulkGroupResult`; `$mode` ∈ `FinanceBookingService::BULK_ADD = 'add'`, `BULK_REMOVE = 'remove'`.
- Produces: `FinanceBulkGroupResult` (readonly): `int $changed`, `int $unchanged`, `list<int> $skippedLocked`, `list<int> $skippedReversal`, `list<int> $skippedBudgetConflict` (jeweils laufende Nummern) und `message(): string`.
- Produces: Route `POST /finances/bulk-groups`, Felder `finance_ids[]`, `group_name`, `mode`; Antwort 302 auf `/finances?year=<aus Referer-Query oder Vorgabe>` mit Flash.

- [x] **Step 1: Failing Tests:**
  - `testAddingAGroupKeepsTheOtherGroupsAndJournalsEachBooking`.
  - `testRemovingAGroupLeavesBookingsWithoutItUntouched` (kein Journaleintrag für sie, zählt als `unchanged`).
  - `testLockedReversedAndConflictingBookingsAreSkippedAndNamed`: je eine Buchung in gesperrtem Zeitraum, eine stornierte, eine Gegenbuchung, eine mit Budget-Konflikt → nicht geändert, laufende Nummern in den richtigen Listen.
  - `testUnknownAndDuplicateIdsAreIgnored` (Review Focus 4).
  - `testAddingCreatesANewGroup`; `testRemovingAnUnknownGroupChangesNothing`.
  - `testTheResultMessageCountsChangesAndSkips`: z. B. „3 Buchungen geändert. Übersprungen: 1 im abgeschlossenen Zeitraum (Nr. 12), 1 storniert (Nr. 15), 1 mit zweiter Budget-Gruppe (Nr. 18).“ – Teile ohne Treffer entfallen; ab 10 Nummern „Nr. 1, 2, … und 5 weitere“.
  - `testBulkRouteNeedsFinanceWriteRight`: Nutzer nur mit Leserecht → 403/Weiterleitung wie andere Schreibrouten, nichts geändert.
- [x] **Step 2: Run** `--filter FinanceBulkGroupFeatureTest` → FAIL.
- [x] **Step 3: Umsetzen.** Eine Transaktion; Buchungen mit `lockForUpdate` laden; je Buchung: gesperrt? → skip; storniert oder Gegenbuchung? → skip; neue ID-Menge berechnen; unverändert → `unchanged`; Regel prüfen → skip bei Konflikt; sonst `syncGroups` + Journal. Controller validiert `mode`, leeren Namen (Fehlermeldung „Bitte eine Gruppe angeben.“) und leere Auswahl („Bitte mindestens eine Buchung auswählen.“).
- [x] **Step 4: Run** → PASS; Sperrklinke `ControllerDataAccessTest` grün; Schärfeprobe (Sperrprüfung, Storno-Prüfung entfernen).
- [x] **Step 5: Commit.**

### Task 7: Stapelbearbeitung – Oberfläche (Impeccable)

**Files:**
- Modify: `templates/finances/index.twig` (Checkboxen in gebuchten und offenen Posten, Aktionsleiste, Formular auf `/finances/bulk-groups`)
- Create: `public/js/finance-bulk-groups.js`
- Modify: `public/css/style.css`
- Test: `tests/Feature/FinanceTemplateRenderTest.php` (erweitern), `tests/js/finance-bulk-groups.test.mjs` (nur falls die JS-Datei eigene Logik hat, z. B. Zählen der sichtbaren Auswahl)

**Interfaces:**
- Consumes: Route und Felder aus Task 6.

- [x] **Step 1: Entwurf mit Impeccable** (`/impeccable`): Auswahl je Zeile und „alle sichtbaren“ (folgt Suche/Filter der Tabellenleiste), Aktionsleiste mit Anzahl, „Gruppe hinzufügen“/„Gruppe entfernen“, Gruppenauswahl mit Neuanlage beim Hinzufügen, Verhalten in der Kartenansicht auf dem Handy, Ergebnismeldung. Bestehende Muster ansehen: Mitgliederliste (`users-bulk-confirm.js`), Lied-Anhänge (`song-attachments-bulk.js`).
- [x] **Step 2: Failing Test** `testWritersSeeTheBulkSelectionAndReadersDoNot`: Rendern mit Schreibrecht → Checkboxen und Formular `action="/finances/bulk-groups"`; nur Leserecht → beides fehlt.
- [x] **Step 3: Run** `--filter FinanceTemplateRenderTest` → FAIL.
- [x] **Step 4: Umsetzen** nach Entwurf; „alle sichtbaren“ markiert nur Zeilen, die die Tabellenleiste gerade zeigt.
- [x] **Step 5: Run** → PASS; `npm run test:js` falls JS-Test; twigcs/phpcs; Sichtprüfung headless (Desktop + 390 px, mit und ohne Auswahl, Ergebnismeldung mit Übersprungenen).
- [x] **Step 6: Commit.**

### Task 8: Budget-Kategorie anlegen/umhängen blockiert bei Konflikt

**Files:**
- Create: `src/Services/BudgetWriteService.php`
- Create: `src/Services/BudgetCategoryConflictException.php`
- Modify: `src/Controllers/BudgetController.php` (`createCategory`, `updateCategory`, `resolveFinanceGroupId` → Service)
- Modify: `tests/Unit/Controllers/ControllerDataAccessTest.php` (Zähler `BudgetController` senken)
- Test: `tests/Feature/BudgetCategoryConflictFeatureTest.php`; `tests/Feature/BudgetFeatureTest.php` bleibt grün

**Interfaces:**
- Consumes: `FinanceGroupBudgetRule::conflictingBookingIds()` (Task 3).
- Produces: `BudgetWriteService::createCategory(int $fiscalYear, int $financeGroupId, string $type): BudgetCategory`, `BudgetWriteService::changeCategoryGroup(BudgetCategory $category, int $financeGroupId): void`, `BudgetWriteService::resolveFinanceGroupId(array $data): ?int`. Beide Schreibmethoden werfen `BudgetCategoryConflictException` (mit `list<int> $runningNumbers`) bzw. eine `\DomainException` mit der bisherigen Duplikat-Meldung.

- [x] **Step 1: Failing Tests:**
  - `testCreatingACategoryIsBlockedWhenBookingsWouldCountTwice`: Y im Jahr budgetiert; Buchung mit X+Y im Jahr; Kategorie für X anlegen → abgewiesen, keine Kategorie, Meldung: „Die Kategorie lässt sich nicht anlegen: {n} Buchungen gehören auch zu einer anderen Budget-Gruppe und würden doppelt zählen (Nr. …). Bitte zuerst in der Kasse bereinigen.“ mit Link auf `/finances?year={Jahr}`.
  - `testAddingASecondTypeForAnAlreadyBudgetedGroupIsAllowed`: X schon als Einnahme budgetiert → X als Ausgabe anlegen geht, auch wenn Buchungen X+Z (Z unbudgetiert) tragen.
  - `testReversalPairsDoNotBlockACategory`.
  - `testChangingTheGroupOfACategoryIsBlockedTheSameWay`.
  - `testCategoriesOfOtherYearsAreUnaffected`.
- [x] **Step 2: Run** `--filter BudgetCategoryConflictFeatureTest` → FAIL.
- [x] **Step 3: Umsetzen.** Prüfung: Ist X im Jahr schon budgetiert (irgendeine Art), ändert sich nichts → kein Block; sonst `conflictingBookingIds(X, Jahr)` auf Basis „X + eine andere budgetierte Gruppe“. Controller fängt die Exceptions und setzt die Flash-Meldung; Logging (`budget.category.created`/`updated`) wandert mit.
- [x] **Step 4: Run** `--filter "Budget|ControllerDataAccessTest"` → PASS; Schärfeprobe.
- [x] **Step 5: Commit.**

### Task 9: Alte Spalten entfernen, Seed-Daten

**Files:**
- Create: `db/migrations/20261122130000_drop_group_columns_from_finances.php`
- Modify: `src/Models/Finance.php` (`group_name`, `finance_group_id`, `financeGroup()` entfernen; `FinanceGroup::finances()` auf `belongsToMany`)
- Modify: `src/Services/DevSeedService.php` (Buchungen ~Zeile 2160)
- Test: `tests/Feature/DestructiveMigrationGuardTest.php` (Fall ergänzen), Seed-Test falls vorhanden (`grep -rln DevSeed tests/`)

**Interfaces:**
- Consumes: alles Vorige; danach darf `grep -rn "group_name\|finance_group_id" src templates public/js` nur noch Budget-Kategorien und die Journal-Anzeige für Alteinträge treffen.

- [x] **Step 1: Failing Guard-Tests** für `UNMIGRATED_GROUPS_SQL`: zählt Buchungen, deren `finance_group_id` (oder per Name auflösbares `group_name`) keine Zuordnung hat. „Findet“/„bleibt still“.
- [x] **Step 2: Migration.** `up()`: Zählprüfung vor dem Drop → `RuntimeException` bei > 0; FK `fk_finances_finance_group`, Index und beide Spalten entfernen. `down()`: Spalten wieder anlegen und aus der Zuordnung zurückschreiben (eine Gruppe je Buchung; vorher zählen, wie viele Buchungen mehr als eine haben, und abbrechen, wenn > 0).
- [x] **Step 3: Migration ausführen** (`ddev exec ./vendor/bin/phinx migrate`, Testdatenbanken laut `/test-runs`) und Ergebnis melden.
- [x] **Step 4: Seed** (`/dev-seed-completeness`): Anlass-Gruppen „Herbstkonzert 2026“ und „Chorreise Wien“; ein Teil der Buchungen trägt Kostenart + Anlass; „Chorreise Wien“ bekommt eine Budget-Kategorie nur im laufenden Geschäftsjahr, und keine Seed-Buchung verletzt die Regel. Seed laufen lassen (Backup-Verzeichnis umlenken, siehe Memory „Seed-Reset leert Backup-Pool“).
  Abweichung: Projektname als Anlass statt fest „Herbstkonzert 2026“; Reisekosten tragen zusätzlich „Chorreise Wien“.
- [x] **Step 5: Run** `--filter "Finance|Budget|DevSeed|DestructiveMigrationGuardTest|ModelSchemaConsistencyTest"` → PASS; dann einmal `ddev composer test:parallel`, `ddev composer phpcs`, `ddev composer twigcs`.
- [x] **Step 6: Commit.**
