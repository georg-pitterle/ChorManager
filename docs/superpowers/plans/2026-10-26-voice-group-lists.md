# Stimmgruppen-Listen Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stimmvertretungen führen für ihre Stimmgruppe eigene Listen mit selbst angelegten Häkchen- und Notizspalten, eine Zeile pro Mitglied; Mitglieder und alle übrigen Rechte sehen davon nichts.

**Architecture:** Neues Rollenrecht `can_keep_voice_group_lists` plus eigene Policy, die Recht **und** Mitgliedschaft in der Stimmgruppe der Liste verlangt. Drei Tabellen (Liste, Spalte, Eintrag); welche Mitglieder Zeilen sind, berechnet ein eigener Dienst bei jedem Aufruf, Einträge verweisen nur auf Spalte und Mitglied. Ein Controller mit POST-Formularen (CSRF wird global eingespritzt), zwei Twig-Seiten. Häkchen und Notizen speichern ohne Neuladen: `public/js/voice-group-lists.js` schickt dieselben Formulare mit `Accept: application/json`, der Controller antwortet über `RequestFormat::expectsJson()` mit JSON statt Redirect.

**Tech Stack:** PHP 8 / Slim 4, Eloquent, Twig, Phinx, PHPUnit 13, ddev.

**Spec:** `docs/superpowers/specs/2026-10-26-voice-group-lists-design.md`

## Global Constraints

- Recht: Schlüssel `can_keep_voice_group_lists`, Label „Stimmgruppen-Listen führen“. Bestehende Rollen bekommen es **nicht** (Spalte `DEFAULT 0`, kein Backfill), auch die erste Admin-Rolle in `AuthController::processSetup` nicht. Seed: nur „Stimmvertretung“ und „Ersatzvertretung“ = 1.
- Zugriff = Recht **und** `voice_group_id` der Liste ∈ `$_SESSION['voice_group_ids']`. Kein anderes Recht öffnet etwas (`can_manage_own_voice_group`, `can_manage_users`, `can_manage_attendance_all` ausdrücklich nicht).
- Fremde und nicht existierende Liste/Spalte antworten **identisch**: HTTP 404, Text „Liste nicht gefunden.“ (Builder-Entscheidung aus der Spec, hier festgelegt).
- Zeilen = aktive Mitglieder (`is_active = 1`) der Stimmgruppe über `user_voice_groups`, alle Teilstimmen; mit `project_id` zusätzlich ∩ `project_users`. Geschrieben werden darf nur für Mitglieder, die gerade Zeile sind.
- Spaltenarten genau `checkbox` und `note`. Art nach dem Anlegen unveränderlich. Notiz ≤ 1000 Zeichen nach `trim`; leer nach `trim` = Eintrag löschen. Häkchen zurücknehmen = Eintrag löschen.
- Häkchen und Notizen: **kein Neuladen, keine Scrollposition verloren**. Fehlschlag zeigt den alten Stand plus Meldung in der Zelle. Bei aktivem Filter „nur offene“ bleibt eine abgehakte Zeile bis zum nächsten Laden stehen. Ohne JS funktionieren die Formulare mit Redirect weiter. Spalten- und Listenpflege darf neu laden.
- JSON-Vertrag von `saveEntry` (nur bei `expectsJson`): Erfolg 200 `{"ok": true, "column_id": int, "user_id": int, "checked": bool, "note": string|null, "tooltip": string|null, "progress": {"done": int, "total": int}|null}` (`progress` nur bei Häkchen-Spalten, `tooltip` = „Abgehakt von <Name> am <d.m.Y>“ bzw. „Zuletzt geändert von <Name> am <d.m.Y>“); Fehler `{"ok": false, "error": string}` mit 404 (fremd/fehlend, Text „Liste nicht gefunden.“), 409 (archiviert), 422 (keine Zeile, Notiz zu lang, ungültige Eingabe).
- Archivierte Listen (`archived_at` gesetzt) sind nur lesbar; jede Änderung außer „Zurückholen“ und „Löschen“ wird abgewiesen.
- `project_id` FK `SET NULL`; `voice_group_id`, `list_id`, `column_id`, `user_id` FK `CASCADE`; `created_by_user_id`, `updated_by_user_id` FK `SET NULL`.
- Logs: Events `voice_group_list.created|updated|archived|restored|deleted`, `voice_group_list_column.created|renamed|moved|deleted`; Kontext nur Ids (`list_id`, `voice_group_id`, `column_id`, `actor_user_id`). **Nie** Titel, Spaltenname oder Notiztext. Einzelne Einträge werden nicht geloggt.
- Bezeichner englisch, UI-Texte/Kommentare/Testbeschreibungen deutsch mit echten Umlauten (`instructions/naming.md`).
- Kein Inline-JS/-CSS in Templates; Twig mit doppelten Anführungszeichen; Zeilenlänge 120/130.
- Kein Hilfethema, kein Hilfe-Knopf (es gibt kein Thema).
- Nach jedem Task: Checkboxen dieses Plans im selben Commit abhaken (`AGENT.md`).
- Testbefehle gefiltert: `ddev exec php vendor/bin/phpunit --filter <Name>`; volle Suite nur in Task 8.

## Review Focus

1. Spalte einer Liste A mit einem Mitglied, das nur in Liste B Zeile ist: muss abgewiesen werden (Test in Task 6: `testAnEntryForAMemberWhoIsNoRowIsRejected`).
2. Mitglied in zwei Stimmgruppen: steht in beiden Listen, Häkchen in der einen färbt die andere nicht (Test in Task 4: `testAMemberOfTwoVoiceGroupsHasIndependentRowsInBothLists`).
3. Notiz nur aus Leerzeichen: löscht den Eintrag statt einen leeren zu speichern (Task 6: `testAWhitespaceNoteRemovesTheEntry`).
4. Notiz über 1000 Zeichen: Fehlermeldung, bisherige Notiz bleibt unverändert (Task 6: `testATooLongNoteIsRejectedAndTheOldOneStays`).
5. Fremde oder unpassende Projekt-Id beim Anlegen/Ändern: abgewiesen, Liste unverändert (Task 5: `testAProjectWithoutMembersOfTheVoiceGroupIsRejected`).

---

### Task 1: Rollenrecht `can_keep_voice_group_lists`

**Files:**
- Create: `db/migrations/20261026120000_add_can_keep_voice_group_lists_to_roles.php`
- Modify: `src/Models/Role.php` (`PERMISSIONS`), `src/Controllers/RoleController.php` (`buildPermissionFlags`, Create- und Update-Arrays samt Log-Kontext), `src/Services/RolePermissionCatalog.php` (Gruppe „Mitgliederverwaltung“, direkt nach `can_manage_own_voice_group`), `templates/roles/index.twig` (Checkbox in beiden Modals: `id="can_keep_voice_group_lists"` / `id="edit_can_keep_voice_group_lists"`), `templates/roles/_overview.twig` (`data-keep-voice-group-lists="0|1"`), `public/js/roles.js` (Edit-Modal befüllen), `src/Middleware/RoleMiddleware.php` (Gate `requiresVoiceGroupLists`), `src/Services/DevSeedService.php` (alle 7 Rollen bekommen den Schlüssel; nur Stimmvertretung/Ersatzvertretung 1), `tests/Unit/Services/SessionAuthServicePermissionMappingTest.php` (`EXPECTED_SESSION_KEYS`), `tests/js/role-overview.test.mjs` (falls es die Katalogliste spiegelt)
- Test: `tests/Feature/RoleKeepVoiceGroupListsFeatureTest.php`

**Interfaces:**
- Produces: Sitzungsschlüssel `can_keep_voice_group_lists` (bool, gesetzt von `SessionAuthService` über `Role::PERMISSIONS`); `new RoleMiddleware(requiresVoiceGroupLists: true)` mit Meldung „Zugriff verweigert: Sie haben keine Berechtigung für die Stimmgruppen-Listen.“

- [x] **Step 1: Failing test schreiben** – nach dem Muster von `RoleAssignOwnVoiceGroupProjectUiFeatureTest`:

```php
public function testBuildPermissionFlagsIncludesKeepVoiceGroupLists(): void
{
    $this->assertSame(1, RoleController::buildPermissionFlags(['can_keep_voice_group_lists' => '1'])['can_keep_voice_group_lists']);
    $this->assertSame(0, RoleController::buildPermissionFlags([])['can_keep_voice_group_lists']);
}
public function testRolesTemplateOffersCheckboxInBothModals(): void   // id=, edit_id=, name=, data-keep-voice-group-lists=
public function testCatalogListsTheRight(): void                      // ['key' => 'can_keep_voice_group_lists', 'label' => 'Stimmgruppen-Listen führen']
public function testRolesJsPopulatesTheRightOnEdit(): void            // 'data-keep-voice-group-lists' und 'edit_can_keep_voice_group_lists' in roles.js
public function testANewRoleWithoutTheFieldDoesNotHoldTheRight(): void // Role::create([...ohne Feld...])->fresh()->can_keep_voice_group_lists === false (DB, Transaktion)
public function testSetupDoesNotGrantTheRightToTheFirstAdmin(): void  // AuthController.php enthält 'can_keep_voice_group_lists' nicht
public function testOnlyTheRepresentativeSeedRolesHoldTheRight(): void // DevSeedService-Quelle: genau 2× "'can_keep_voice_group_lists' => 1"
```

Dazu `'can_keep_voice_group_lists'` in `EXPECTED_SESSION_KEYS` ergänzen.

- [x] **Step 2: Rot prüfen** – `ddev exec php vendor/bin/phpunit --filter 'RoleKeepVoiceGroupLists|SessionAuthServicePermissionMapping'` → FAIL.
- [x] **Step 3: Migration schreiben und ausführen** (`/phinx-migration`): `ALTER TABLE roles ADD COLUMN can_keep_voice_group_lists TINYINT(1) NOT NULL DEFAULT 0 AFTER can_assign_own_voice_group_to_project`; `down()` droppt die Spalte. Danach `ddev composer migrate`.
- [x] **Step 4: Recht an allen oben genannten Stellen verdrahten.** Gate-Eintrag in `RoleMiddleware::GATES` und gleichnamiger Konstruktorparameter (vor `?LoggerInterface $logger`) – `RoleMiddlewareGateTableFeatureTest` prüft beide Hälften.
- [x] **Step 5: Grün prüfen** – Filter aus Step 2 plus `RoleMiddlewareGateTable|RoleAccessConsistency|RoleAssignOwnVoiceGroupProjectUi` → PASS; `ddev exec node --test tests/js/role-overview.test.mjs` → PASS.
- [x] **Step 6: Commit** (`/git-commit`), Plan-Checkboxen von Task 1 abhaken.

---

### Task 2: Schema und Modelle

**Files:**
- Create: `db/migrations/20261026121000_create_voice_group_lists.php`, `src/Models/VoiceGroupList.php`, `src/Models/VoiceGroupListColumn.php`, `src/Models/VoiceGroupListEntry.php`
- Test: `tests/Feature/VoiceGroupListSchemaFeatureTest.php`

**Interfaces:**
- Produces:
  - `VoiceGroupList` (`voice_group_lists`): `voice_group_id`, `title`, `project_id?`, `archived_at?` (datetime), `created_by_user_id?`, Timestamps; `columns(): HasMany` sortiert `sort_order, id`; `voiceGroup(): BelongsTo`; `project(): BelongsTo`; `isArchived(): bool`.
  - `VoiceGroupListColumn` (`voice_group_list_columns`): `list_id`, `label`, `type`, `sort_order`, Timestamps; Konstanten `TYPE_CHECKBOX = 'checkbox'`, `TYPE_NOTE = 'note'`, `TYPES`; `list(): BelongsTo`; `entries(): HasMany`.
  - `VoiceGroupListEntry` (`voice_group_list_entries`): `column_id`, `user_id`, `checked_at?` (datetime), `note?` (TEXT), `updated_by_user_id?`, Timestamps; Unique `(column_id, user_id)`; `updatedBy(): BelongsTo User`.

- [x] **Step 1: Failing tests**

```php
public function testDeletingAColumnDeletesItsEntries(): void
public function testDeletingAListDeletesColumnsAndEntries(): void
public function testDeletingAMemberDeletesTheirEntries(): void
public function testDeletingTheProjectKeepsTheListWithoutProject(): void   // project_id danach null, Liste + Einträge da
public function testAMemberHasAtMostOneEntryPerColumn(): void             // zweites create wirft QueryException
public function testColumnsComeInSortOrder(): void
```

- [x] **Step 2: Rot prüfen** – `--filter VoiceGroupListSchema` → FAIL (Klasse fehlt).
- [x] **Step 3: Migration** mit FKs laut Global Constraints, Index `(list_id, sort_order)`, `(voice_group_id)`, Unique `(column_id, user_id)`; `type` als `enum('checkbox','note')`. `down()` bricht ab, solange eine der drei Tabellen Zeilen hat – Muster und Meldung aus `20261023121000_add_sponsoring_deliverables.php`. Ausführen.
- [x] **Step 4: Modelle** anlegen (Casts: `archived_at`, `checked_at` → `datetime`; Ids → `integer`).
- [x] **Step 5: Grün prüfen** – `--filter VoiceGroupListSchema` → PASS.
- [x] **Step 6: Commit**, Checkboxen abhaken.

---

### Task 3: `VoiceGroupListPolicy`

**Files:**
- Create: `src/Policies/VoiceGroupListPolicy.php`
- Modify: `src/Dependencies.php` (Fabrik `new VoiceGroupListPolicy($_SESSION)`), `tests/Unit/Policies/PoliciesReceiveTheSessionTest.php` (falls dort Policies einzeln instanziiert werden)
- Test: `tests/Unit/Policies/VoiceGroupListPolicyTest.php`

**Interfaces:**
- Produces:
  - `__construct(array $session)` – liest `user_id`, `can_keep_voice_group_lists` (truthy), `voice_group_ids`.
  - `accessibleVoiceGroupIds(): list<int>` – leer ohne Recht, sonst die eigenen Stimmgruppen.
  - `canUseVoiceGroup(int $voiceGroupId): bool`
  - `canAccessList(VoiceGroupList $list): bool` – `canUseVoiceGroup($list->voice_group_id)`.
  - `currentUserId(): int`

- [x] **Step 1: Failing tests** (ohne DB, `VoiceGroupList` per `new` mit gesetztem `voice_group_id`):

```php
public function testTheRightAndTheVoiceGroupOpenTheList(): void            // ['can_keep_voice_group_lists'=>true,'voice_group_ids'=>[3]], Liste vg 3 → true
public function testAForeignVoiceGroupStaysClosed(): void                  // vg 4 → false
public function testWithoutTheRightTheOwnVoiceGroupStaysClosed(): void
public function testOtherRightsDoNotOpenTheList(): void                    // je can_manage_own_voice_group / can_manage_users / can_manage_attendance_all = true, ohne neues Recht → false
public function testWithoutAVoiceGroupNothingIsAccessible(): void          // accessibleVoiceGroupIds() === []
public function testARepresentativeOfTwoVoiceGroupsReachesBoth(): void     // [3,5] → beide true
```

- [x] **Step 2: Rot prüfen** – `--filter VoiceGroupListPolicy` → FAIL.
- [x] **Step 3: Policy implementieren.** Klassenkommentar deutsch: warum ein eigenes Recht (Spec, Abschnitt „Wer Zugriff hat“) und dass Lesen = Schreiben.
- [x] **Step 4: Grün prüfen** – `--filter 'VoiceGroupListPolicy|PoliciesReceiveTheSession'` → PASS.
- [x] **Step 5: Schärfeprobe** – in `canUseVoiceGroup()` die Stimmgruppenprüfung probeweise durch `return $this->canKeep;` ersetzen, Tests laufen lassen → `testAForeignVoiceGroupStaysClosed` muss FAIL zeigen; zurückbauen.
- [x] **Step 6: Commit**, Checkboxen abhaken.

---

### Task 4: Zeilen und Fortschritt – `VoiceGroupListRows`

**Files:**
- Create: `src/Services/VoiceGroupListRows.php`
- Test: `tests/Feature/VoiceGroupListRowsFeatureTest.php`

**Interfaces:**
- Consumes: Modelle aus Task 2.
- Produces:
  - `members(VoiceGroupList $list): list<array{user: User, sub_voice_name: ?string}>` – Regel aus Global Constraints; sortiert nach Teilstimme (alphabetisch, ohne Teilstimme zuletzt), darin nach Nachname, Vorname.
  - `memberIds(VoiceGroupList $list): list<int>`
  - `isRow(VoiceGroupList $list, int $userId): bool`
  - `entriesByColumnAndUser(VoiceGroupList $list): array<int, array<int, VoiceGroupListEntry>>` – nur Einträge aktueller Zeilen, mit `updatedBy` geladen.
  - `progress(VoiceGroupList $list): array<int, array{done: int, total: int}>` – je Häkchen-Spalte; zählt nur aktuelle Zeilen.

- [x] **Step 1: Failing tests**

```php
public function testAllSubVoicesOfTheVoiceGroupAreRows(): void            // Sopran 1 + Sopran 2 + ohne Teilstimme
public function testArchivedMembersAreNoRows(): void
public function testMembersOfOtherVoiceGroupsAreNoRows(): void
public function testWithAProjectOnlyProjectMembersAreRows(): void
public function testAMemberWhoComesBackHasTheirEntriesAgain(): void        // archivieren → nicht in entriesByColumnAndUser/progress; reaktivieren → wieder da
public function testProgressCountsOnlyCurrentRows(): void                  // 2 von 3 abgehakt, einer davon archiviert → ['done'=>1,'total'=>2]
public function testAMemberOfTwoVoiceGroupsHasIndependentRowsInBothLists(): void
public function testRowsAreOrderedBySubVoiceThenName(): void
```

- [x] **Step 2: Rot prüfen** – `--filter VoiceGroupListRows` → FAIL.
- [x] **Step 3: Dienst implementieren** (Eloquent-Abfrage über `user_voice_groups` mit `sub_voice_id` → `sub_voices.name`; Projektfilter per `whereHas('projects', …)` bzw. Join auf `project_users`).
- [x] **Step 4: Grün prüfen** – `--filter VoiceGroupListRows` → PASS.
- [x] **Step 5: Commit**, Checkboxen abhaken.

---

### Task 5: Listen anlegen, ansehen, pflegen

**Files:**
- Create: `src/Controllers/VoiceGroupListController.php`, `templates/voice_group_lists/index.twig`, `templates/voice_group_lists/show.twig`
- Modify: `src/Routes.php`, `src/Dependencies.php`, `public/css/style.css` (nur falls Utility-Klassen nicht reichen)
- Test: `tests/Feature/VoiceGroupListFeatureTest.php`

**Interfaces:**
- Consumes: `VoiceGroupListPolicy` (Task 3), `VoiceGroupListRows` (Task 4).
- Produces: `VoiceGroupListController(Twig $view, VoiceGroupListPolicy $policy, VoiceGroupListRows $rows, ?LoggerInterface $logger = null)` mit `findAccessibleList(int $id): ?VoiceGroupList` (null für fremd **und** fehlend) und `notFound(Response): Response` (404, „Liste nicht gefunden.“) – Task 6 nutzt beide.
- Routen (Gruppe `/voice-group-lists`, `->add(new RoleMiddleware(requiresVoiceGroupLists: true))`):
  - `GET ''` index · `POST ''` create
  - `GET '/{id:[0-9]+}'` show (Query `open=<columnId>` filtert auf offene Zeilen dieser Häkchen-Spalte)
  - `POST '/{id:[0-9]+}'` update (Titel, Projekt) · `POST '/{id}/archive'` · `POST '/{id}/restore'` · `POST '/{id}/delete'`
- Formfelder create: `title`, `voice_group_id` (nur Pflicht bei mehreren eigenen Stimmgruppen, sonst die eine), `project_id` (leer = ohne Projekt). Erlaubte Projekte: die mit mindestens einem Mitglied der Stimmgruppe in `project_users`.

- [x] **Step 1: Failing tests** (Muster `SponsoringDeliverablesFeatureTest`: Controller direkt aufrufen, `$_SESSION` setzen, `TestHttpHelpers`, `TwigViewStubs`):

```php
public function testARepresentativeCreatesAListForTheOwnVoiceGroup(): void      // Redirect auf /voice-group-lists/{id}, created_by gesetzt, Log voice_group_list.created ohne 'title' im Kontext
public function testAListForAForeignVoiceGroupIsRejected(): void              // voice_group_id fremd → keine Liste, Fehlermeldung
public function testAProjectWithoutMembersOfTheVoiceGroupIsRejected(): void   // create und update; Liste unverändert
public function testTheOverviewShowsOnlyListsOfOwnVoiceGroups(): void         // Titel eigener Liste im HTML, fremder nicht; archivierte getrennt unter „Archiviert“
public function testAForeignListAnswersLikeAMissingOne(): void                // show/update/archive/delete auf fremde Id und auf Id 999999: beide 404, gleicher Body
public function testAMemberWithoutTheRightIsStoppedByTheMiddleware(): void     // RoleMiddleware(requiresVoiceGroupLists: true) mit Sitzung ohne Recht → 403
public function testTheListPageShowsRowsColumnsAndProgress(): void            // Mitgliedsname, Spaltenlabel, „1 / 2“
public function testTheOpenFilterShowsOnlyMembersWithoutTick(): void
public function testArchiveRestoreAndDelete(): void                          // archived_at gesetzt/null; delete entfernt Liste samt Einträgen
public function testAnArchivedListCannotBeRenamed(): void
public function testLogsNeverContainTheTitle(): void                          // für created/updated/archived/deleted: Kontext ohne Titelstring
```

- [x] **Step 2: Rot prüfen** – `--filter VoiceGroupListFeatureTest` → FAIL.
- [x] **Step 3: Controller-Methoden** `index`, `create`, `show`, `update`, `archive`, `restore`, `delete` umsetzen; Eingaben über `InputValidator`, Flash über `$_SESSION['success'|'error']`, `active_nav => 'voice_group_lists'`.
- [x] **Step 4: Templates.** `index.twig`: Listen gruppiert nach Stimmgruppe (Reihenfolge über `App\Util\VoiceGroupOrder`), Formular „Neue Liste“, Archiv-Abschnitt. `show.twig`: Tabelle `table-responsive-cards` mit `data-label`, eine Zeile pro Mitglied (Name, Teilstimme), eine Spalte pro Spalte (Zellen in Task 6), Fortschritt und Link „nur offene“ im Spaltenkopf, Aktionen Umbenennen/Projekt/Archivieren/Löschen (`data-confirm` wie in `projects/members.twig`). Bei Archiv: Hinweis „Archiviert – nur lesbar“ und keine Schreibformulare.
- [x] **Step 5: Routen und DI** eintragen.
- [x] **Step 6: Grün prüfen** – `--filter 'VoiceGroupListFeatureTest|RoleAccessConsistency'` → PASS; `ddev composer twigcs` → 0 Fehler.
- [x] **Step 7: Commit**, Checkboxen abhaken.

---

### Task 6: Spalten und Einträge

**Files:**
- Modify: `src/Controllers/VoiceGroupListController.php`, `templates/voice_group_lists/show.twig`, `src/Routes.php`
- Test: `tests/Feature/VoiceGroupListColumnsAndEntriesFeatureTest.php`

**Interfaces:**
- Consumes: `findAccessibleList()`, `notFound()` aus Task 5; `VoiceGroupListRows::isRow()`.
- Routen (selbe Gruppe):
  - `POST '/{id}/columns'` addColumn (`label`, `type`)
  - `POST '/columns/{column_id}'` renameColumn (`label`)
  - `POST '/columns/{column_id}/move'` moveColumn (`direction` = `up`|`down`, tauscht `sort_order` mit dem Nachbarn)
  - `POST '/columns/{column_id}/delete'` deleteColumn
  - `POST '/columns/{column_id}/entries/{user_id}'` saveEntry – Häkchen: Feld `checked` (`1` setzt `checked_at = now`, `0` löscht); Notiz: Feld `note`
- Jeder Spaltenweg lädt die Spalte, prüft `canAccessList($column->list)` → sonst `notFound`; archivierte Liste → Fehlermeldung „Die Liste ist archiviert und nur lesbar.“ und Redirect.
- `saveEntry` antwortet bei `RequestFormat::expectsJson($request)` nach dem JSON-Vertrag aus den Global Constraints (auch alle Fehlerfälle als JSON), sonst Redirect auf `/voice-group-lists/{id}` mit Flash.
- Markup-Haken für Task 7: Zellformular `form[data-vgl-entry]`, Zelle `[data-vgl-cell="{columnId}-{userId}"]`, Fortschritt `[data-vgl-progress="{columnId}"]`, Meldungsplatz `[data-vgl-error]` je Zelle.

- [x] **Step 1: Failing tests**

```php
public function testAddRenameMoveAndDeleteAColumn(): void
public function testAnInvalidColumnTypeIsRejected(): void
public function testTheTypeOfAColumnCannotBeChanged(): void                  // renameColumn mit type=note auf checkbox-Spalte ändert type nicht
public function testTickingAndUntickingAMember(): void                       // checked_at + updated_by gesetzt; danach Eintrag weg
public function testSavingAndClearingANote(): void
public function testAWhitespaceNoteRemovesTheEntry(): void
public function testATooLongNoteIsRejectedAndTheOldOneStays(): void          // 1001 Zeichen → Fehlermeldung „Eine Notiz darf höchstens 1000 Zeichen lang sein.“
public function testAnEntryForAMemberWhoIsNoRowIsRejected(): void            // fremde Stimmgruppe; und bei Projektliste: Stimmgruppe ja, Projekt nein
public function testAForeignColumnAnswersLikeAMissingOne(): void             // alle fünf Routen: 404, keine Änderung
public function testAnArchivedListAcceptsNoColumnOrEntryChanges(): void
public function testLogsNeverContainColumnLabelOrNoteText(): void
public function testTheTickCellNamesWhoTickedItAndWhen(): void               // show-HTML enthält „Abgehakt von <Name> am <d.m.Y>“ im title-Attribut
public function testATickAsJsonAnswersWithStateTooltipAndProgress(): void    // Accept: application/json → 200, ok/checked/tooltip, progress done+1
public function testJsonErrorsCarryStatusAndMessage(): void                  // fremde Spalte 404 „Liste nicht gefunden.“, archiviert 409, Nicht-Zeile 422, zu lange Notiz 422 – nie Redirect
public function testTheCellsCarryTheScriptHooks(): void                      // show-HTML: data-vgl-entry, data-vgl-cell="c-u", data-vgl-progress="c", Script voice-group-lists.js eingebunden
```

- [x] **Step 2: Rot prüfen** – `--filter VoiceGroupListColumnsAndEntries` → FAIL.
- [x] **Step 3: Controller-Methoden** umsetzen. Einträge über `updateOrCreate` bzw. `delete`; kein Log je Eintrag.
- [x] **Step 4: Zellen in `show.twig`.** Häkchen: kleines Formular mit einem Button (`bi-check-square` / `bi-square`), `title` mit Person und Datum. Notiz: gekürzter Text plus Bearbeiten über ein Formular in einem Bootstrap-Modal oder `<details>`; Wahl beim Builder, ohne Inline-JS. Spaltenverwaltung im Spaltenkopf (Umbenennen, ←/→, Löschen mit `data-confirm` „Spalte samt allen Einträgen löschen?“), Formular „Spalte hinzufügen“ (Name, Art: Häkchen/Notiz).
- [x] **Step 5: Grün prüfen** – `--filter 'VoiceGroupListColumnsAndEntries|VoiceGroupListFeatureTest'` → PASS; `ddev composer twigcs` → 0 Fehler.
- [x] **Step 6: Schärfeprobe** – in `saveEntry` die `isRow()`-Prüfung probeweise entfernen → `testAnEntryForAMemberWhoIsNoRowIsRejected` muss FAIL zeigen; zurückbauen.
- [x] **Step 7: Commit**, Checkboxen abhaken.

---

### Task 7: Speichern ohne Neuladen

**Files:**
- Create: `public/js/voice-group-lists.js`
- Modify: `templates/voice_group_lists/show.twig` (`{% block scripts %}` mit `asset_path('/js/voice-group-lists.js')`, falls in Task 6 noch nicht geschehen)
- Test: `tests/js/voice-group-lists.test.mjs`

**Interfaces:**
- Consumes: JSON-Vertrag (Global Constraints), Markup-Haken (Task 6).
- Produces: Datei im Stil von `users-bulk-confirm.js` – IIFE fürs DOM, im Node-Kontext `module.exports = { progressLabel, cellState }`:
  - `progressLabel({done, total}): string` → `"18 / 30"`
  - `cellState(result: object, previous: {checked: bool, note: string|null, tooltip: string|null}): {checked: bool, note: string|null, tooltip: string|null, error: string|null}` – bei `ok: true` die Werte der Antwort, sonst `previous` plus `error` (Antworttext oder „Speichern fehlgeschlagen. Bitte erneut versuchen.“ bei Netzwerkfehler/kein JSON).

- [x] **Step 1: Failing tests** (`node:test`):

```js
test('Fortschritt: done / total', () => assert.equal(progressLabel({ done: 18, total: 30 }), '18 / 30'));
test('Erfolg übernimmt Häkchen, Tooltip und Notiz der Antwort', ...);
test('Fehler behält den alten Stand und nennt die Meldung', ...);          // { ok:false, error:'Die Liste ist archiviert und nur lesbar.' }
test('Kaputte Antwort behält den alten Stand mit Standardmeldung', ...);   // cellState(null, prev).error === 'Speichern fehlgeschlagen. Bitte erneut versuchen.'
```

- [x] **Step 2: Rot prüfen** – `ddev exec node --test tests/js/voice-group-lists.test.mjs` → FAIL.
- [x] **Step 3: Implementieren.** Delegierter `submit`-Listener auf `form[data-vgl-entry]`: `preventDefault`, `fetch(form.action, {method: 'POST', body: new FormData(form), headers: {Accept: 'application/json'}, credentials: 'same-origin'})` (CSRF-Feld steckt im Formular, `csrf-token-sync.js` hält es aktuell). Während des Speicherns Button `disabled`. Danach Zelle per `cellState` setzen: Häkchen-Icon, verstecktes Feld `checked` auf den Gegenwert, `title`, Notiztext; Fortschritt per `progressLabel`; Fehler in `[data-vgl-error]` anzeigen, beim nächsten Erfolg leeren. Bei aktivem `open`-Filter keine Zeile entfernen.
- [x] **Step 4: Grün prüfen** – `ddev exec node --test tests/js/voice-group-lists.test.mjs` → PASS.
- [x] **Step 5: Sichtprüfung headless** im ddev-Container (Playwright, kein Fenster): Liste mit mindestens 30 Zeilen, nach unten scrollen, drei Häkchen nacheinander setzen → kein Seitenwechsel (gleiche `performance.navigation`/URL), `window.scrollY` unverändert, Fortschritt zählt mit; ein Häkchen zurücknehmen; Notiz speichern. Screenshot ansehen.
  In Task 8, Step 6 nachgeholt (Seed-Daten nötig): 25 Zeilen statt 30 (so viele Sopran-Mitglieder hat der Seed), Seite nicht neu geladen, Scrollposition 400 → 400, Fortschritt in Kopf und Spaltenblock zählt mit, Notiz übersteht Neuladen.
- [x] **Step 6: Commit**, Checkboxen abhaken.

---

### Task 8: Navigation und Seed-Daten

**Files:**
- Modify: `src/Navigation/NavigationBuilder.php` (Bereich `people`, nach „Mitglieder“: Label „Stimmgruppen-Listen“, URL `/voice-group-lists`, Icon `bi-card-checklist`, `navKeys` `['voice_group_lists']`, Keywords `['checkliste', 'notizen', 'mitgliedsbeitrag', 'stimmvertretung']`, sichtbar mit `can_keep_voice_group_lists`), `tests/Feature/NavigationBuilderFeatureTest.php`, `tests/e2e/data/roleAccessMatrix.mjs` (`{ path: '/voice-group-lists', requires: ['can_keep_voice_group_lists'] }`), `src/Services/DevSeedService.php` (`seedVoiceGroupLists(...)` nach `seedProjectMembers`, Zähler `voice_group_lists`, `voice_group_list_columns`, `voice_group_list_entries`)
- Test: `tests/Feature/VoiceGroupListSeedFeatureTest.php`

- [x] **Step 1: Failing tests**

```php
// NavigationBuilderFeatureTest
public function testVoiceGroupListsAreVisibleOnlyWithTheirOwnRight(): void   // mit Recht sichtbar; nur can_manage_own_voice_group → nicht
// VoiceGroupListSeedFeatureTest (Quelltextprüfung wie StorageSeedCoverageFeatureTest)
public function testTheSeedCreatesListsColumnsAndEntries(): void            // '$this->seedVoiceGroupLists(', "'Beitrag bezahlt'", "'Kleidergröße'", 'archived_at'
```

- [x] **Step 2: Rot prüfen** – `--filter 'NavigationBuilderFeatureTest|VoiceGroupListSeed'` → FAIL.
- [x] **Step 3: Navigation** eintragen.
- [x] **Step 4: Seed** nach `/dev-seed-completeness`: für mindestens zwei Stimmgruppen je eine Liste mit Projektbezug (Spalten „Beitrag bezahlt“ = Häkchen, „Kleidergröße“ = Notiz, etwa die Hälfte ausgefüllt, `updated_by_user_id` = ein Mitglied der Stimmgruppe), je eine ohne Projekt („Notenausgabe“), dazu eine archivierte Liste. Idempotent bei `append` (vorhandene Liste gleichen Titels in der Stimmgruppe überspringen).
- [x] **Step 5: Grün prüfen** – Filter aus Step 2 → PASS; `ddev php bin/dev_seed.php --mode=reset-and-seed` läuft durch und meldet die drei Zähler > 0. Bei Produktivdaten in der Dev-DB vorher fragen.
- [x] **Step 6: Sichtprüfung** – headless im ddev-Container als Stimmvertretung `/voice-group-lists` und eine Liste öffnen, Desktop- und Handybreite, Screenshot ansehen (kein Browserfenster).
- [x] **Step 7: Commit**, Checkboxen abhaken.

---

### Task 9: Abschluss

- [x] **Step 1:** `ddev composer phpcs` → 0 Fehler (sonst `ddev composer phpcbf`).
- [x] **Step 2:** `ddev composer twigcs` → 0 Fehler.
- [x] **Step 3:** volle Suite einmal: `ddev composer test` und `ddev exec npm run test:js` → grün; Ergebnis berichten.
- [x] **Step 4:** Zeilenenden der neuen/geänderten Dateien auf LF prüfen (`eol:check` läuft in Step 3 mit).
- [x] **Step 5:** Abschluss über `/git-commit` (Squash, Merge nach `main`; kein Push).
