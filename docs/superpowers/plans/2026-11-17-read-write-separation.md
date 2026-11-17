# Getrennte Lese- und Schreibschicht – Umsetzungsplan

> **Für agentische Bearbeiter:** REQUIRED SUB-SKILL: `superpowers:subagent-driven-development`
> (empfohlen) oder `superpowers:executing-plans`. Der Plan wird Task für Task abgearbeitet.
> Schritte nutzen Checkbox-Syntax (`- [ ]`); nach jedem Task werden seine Kästchen im selben
> Commit abgehakt (Regel aus `AGENT.md`, Abschnitt "Pläne").

**Ziel:** Controller schreiben nicht mehr selbst in die Datenbank und lesen Listen nicht mehr
selbst; eine festgeschriebene Regel und ein Wächter mit Sperrklinke sorgen dafür, dass der
Bestand schrumpft und nichts dazukommt.

**Architektur:** Keine neue Technik, sondern eine Regel über die vorhandenen Schichten:
Schreiben geht über `src/Persistence` (einfach) oder einen Schreibdienst in `src/Services`
(mit Fachregeln), Lesen über `src/Queries`. Der Wächter `ControllerDataAccessTest` zählt je
Controller die direkten Zugriffe und vergleicht mit einer Liste. Drei Controller werden in
diesem Plan umgestellt, die übrigen beim nächsten Anfassen.

**Tech-Stack:** PHP 8.5, Slim 4, PHP-DI, Eloquent (Capsule), PHPUnit über DDEV.

**Spec:** Keine eigene Datei. Grundlage ist die Absicht vom Projektbeginn - getrennte Lese-
und Schreibschicht - und die Bestandsaufnahme vom 17.11.2026:

| | Schicht | direkte Zugriffe in Controllern (Stand `8086ca48`) |
|---|---|---|
| Schreiben | `src/Persistence` (3 Klassen) und Schreibdienste | 128 Stellen in 27 Controllern |
| Lesen | `src/Queries` (5 Klassen) | rund 180 Stellen in 37 Controllern |

Die Zahlen zählt der Algorithmus aus Task 1; maßgeblich ist, was er beim ersten Lauf findet.

## Die Regel (wird in Task 1 festgeschrieben)

1. **Ein Controller schreibt nie selbst.** Verboten sind Schreibaufrufe (`save`, `update`,
   `delete`, `create`, `sync`, `attach`, `detach`, `upsert`, `updateOrCreate`, `firstOrCreate`
   und verwandte) auf Modellen, Abfragen und Relationen. Erlaubt ist derselbe Methodenname auf
   einer injizierten Abhängigkeit (`$this->taskWriter->update(...)`).
2. **Wohin Schreibwege gehen:**
   - `src/Persistence/<Bereich>Persistence` für Schreibwege ohne Fachregel: anlegen, ändern,
     löschen, Zuordnungen setzen, auch mehrere Schritte in einer Transaktion.
   - `src/Services/<Bereich>WriteService` für Schreibwege mit Fachregel: Sperren,
     Nummernkreis, Verlaufseinträge, Zustandsübergänge.
   - Bestehende Ausnahmen im Namen bleiben: `FinanceBookingService`, `ParticipationWriter`.
3. **Ein Controller liest Listen nicht selbst.** Verboten sind statische Aufrufe auf einer
   Modellklasse (`Song::with(...)`, `Category::orderBy(...)`, `Sponsorship::where(...)`).
   Erlaubt bleiben:
   - `Model::find($id)` und `Model::findOrFail($id)` für den einen Datensatz einer Route,
   - dasselbe mit vorgeladenen Beziehungen: `Model::with([...])->find($id)` bzw. `findOrFail`,
   - Zugriffe über ein schon geladenes Modell (`$list->columns()->get()`).
   Alles andere geht in `src/Queries/<Bereich>Query`.
4. Dienste in `src/Services` und die Abfrageschicht selbst sind von der Regel nicht betroffen.

## Globale Vorgaben

- **Verhalten bleibt gleich.** Kein Task ändert Meldungen, Weiterleitungen, Statuscodes,
  Log-`event`-Schlüssel oder die Reihenfolge von Listen. Rümpfe und Begründungskommentare
  ziehen unverändert mit.
- **Neue Abhängigkeit = Pflichtparameter** vor dem ersten optionalen Parameter des
  Konstruktors, ohne Rückfall `?? new`. Drei Stellen je Parameter: Konstruktor, eine Vorgabe
  in `tests/Feature/BuildsControllers.php`, ein Argument im Eintrag in `src/Dependencies.php`
  (sofern der Controller einen hat).
- **Ein Controller, der einen neuen Parameter bekommt, wird vorher auf den Baustein
  umgestellt**, falls er noch nicht in `ControllersAreBuiltThroughBuildersTest::MIGRATED`
  steht (Vorgehen wie im Plan `2026-11-16-controller-test-builders.md`, Ablauf a bis f
  samt a2 "Namenskollisionen").
- **Tests zuerst** (`/writing-tests`). Neue Persistence- und Query-Klassen bekommen
  Feature-Tests mit Datenbank in einer Transaktion; was nur verschoben wird und schon über
  den Controller getestet ist, bekommt keinen zweiten Test derselben Regel.
- **Schärfeprobe** für jeden neuen Test, Ergebnis in die Commit-Nachricht.
- **Testläufe** (`/test-runs`): gefiltert während der Arbeit, die volle Suite
  `ddev composer test:parallel` nur in Task 5. Nach jedem Task `ddev composer phpcs`.
- **PHP mit Namespaces nur über Write/Edit schreiben**, nie per `sed`, `printf` oder Heredoc
  im Bash-Tool - dort verschwinden Backslashes (`use AppServicesFoo;`).
- **Bezeichner englisch, Texte deutsch mit echten Umlauten**, Zeilenenden LF.
- **Git:** Branch `refactor/read-write-separation` von `main`. Ein Commit je Task nach
  `/git-commit`, Abschnitte 1 bis 5; kein Squash, kein Merge, kein Push.
- **Kein Schema, keine neue Entität:** keine Migration, keine Seed-Daten, kein Twig.

## Review-Schwerpunkte

1. **Wächter übersieht eine Schreibform.** `(clone $relation)->delete()` oder
   `$existing?->delete()` müssen als direkte Schreibzugriffe zählen. Test in Task 1.
2. **Wächter meldet einen erlaubten Aufruf.** `$this->attachments->deleteForEntity(...)` und
   `Song::with([...])->find($id)` dürfen nicht zählen. Test in Task 1.
3. **Spaltenreihenfolge nach dem Verschieben.** Gleiche `sort_order`-Werte aus einem Altbestand
   müssen sich trennen lassen; das Neudurchnummerieren zieht in die Persistenz. Test in Task 2.
4. **Doppelter Haken.** Ein schon gesetztes Häkchen bleibt unverändert, wenn eine zweite
   Stimmvertretung erneut abhakt. Test in Task 2.
5. **Leistungszeilen behalten ihren Haken**, wenn ihr Text bei einer Korrektur gleich bleibt.
   Test in Task 4.

---

### Task 1: Regel festschreiben und Wächter

**Dateien:**
- Anlegen: `instructions/data-access.md`
- Ändern: `AGENT.md` (Abschnitt "Code and Style": Verweis `@instructions/data-access.md`)
- Anlegen: `tests/Unit/Controllers/ControllerDataAccessTest.php`

**Schnittstellen:**
- Liefert: die Konstanten `KNOWN_DIRECT_WRITES` und `KNOWN_DIRECT_READS`
  (`array<string, int>`: Controller-Kurzname => Zahl der Fundstellen), die Task 2 bis 4
  verkleinern, und die öffentlichen statischen Helfer `directWrites(string $file): int` und
  `directReads(string $file): int`.

- [x] **Schritt 1: Branch anlegen.** `git switch -c refactor/read-write-separation`
- [x] **Schritt 2: Regel schreiben.** `instructions/data-access.md` enthält den Abschnitt
  "Die Regel" dieses Plans in vier nummerierten Punkten, dazu einen Absatz "Warum": Die
  Schichtung vom 16.11.2026 hat gezeigt, dass die Schreibwege mit Regeln (Terminserie,
  Mitglied) im Controller nicht atomar waren und sich ohne Controller nicht prüfen ließen. In
  `AGENT.md` unter "Code and Style" eine Zeile `@instructions/data-access.md`.
- [x] **Schritt 3: Tests für den Zähler schreiben.** Sie prüfen den Algorithmus an kleinen
  PHP-Schnipseln in einer Temporärdatei (`sys_get_temp_dir()`, im `tearDown()` gelöscht):

  | Testname | Schnipsel (im Rumpf einer Methode, mit `use App\Models\Song;`) | erwartet |
  |---|---|---|
  | `testWritesOnModelsAndRelationsCount` | `$song->update([]); $song->categories()->sync([]); Song::create([]); (clone $relation)->delete(); $existing?->delete();` | `directWrites` = 5 |
  | `testWritesThroughAnInjectedDependencyDoNotCount` | `$this->attachments->deleteForEntity('x', 1, 2); $this->songs->update($song, []);` | `directWrites` = 0 |
  | `testListReadsOnModelsCount` | `Song::with(['a'])->orderBy('title')->get(); Category::orderBy('name')->get();` (mit `use App\Models\Category;`) | `directReads` = 2 |
  | `testSingleRecordLookupsDoNotCount` | `Song::find(1); Song::findOrFail(1); Song::with(['a'])->find(1); Song::with(['a'])->findOrFail(1); Song::class;` | `directReads` = 0 |
  | `testStaticCallsOnNonModelsDoNotCount` | `Carbon::now(); InputValidator::asString($x);` | `directReads` = 0 |

- [x] **Schritt 4: Rotlauf.** `ddev php vendor/bin/phpunit --filter ControllerDataAccessTest`
  → rot, Methoden fehlen.
- [x] **Schritt 5: Zähler schreiben.** Der Algorithmus arbeitet auf `token_get_all()` ohne
  Leerzeichen und Kommentare. Modellklassen sind die per `use App\Models\X;` importierten
  Kurznamen der Datei.

  ```php
  private const WRITE_METHODS = [
      'save', 'delete', 'update', 'sync', 'syncWithoutDetaching', 'attach', 'detach', 'toggle',
      'create', 'createMany', 'upsert', 'updateOrCreate', 'firstOrCreate', 'insert', 'insertGetId',
      'destroy', 'forceDelete', 'restore', 'increment', 'decrement', 'updateExistingPivot', 'push', 'touch',
  ];
  private const SINGLE_RECORD = ['find', 'findOrFail'];
  ```

  - **Schreiben:** Ein `T_STRING` aus `WRITE_METHODS`, dem `->`, `?->` oder `::` vorausgeht
    und `(` folgt, zählt, wenn der Anfang seiner Aufrufkette **nicht** `$this->eigenschaft`
    ist und nicht `self`, `static` oder `parent`. Den Anfang findet ein Rückwärtslauf: über
    ausbalancierte `(...)` und `[...]` springen, über `->name`, `?->name` und `::name` weiter
    zurück, bis ein Variablen-, Klassen- oder anderer Token kommt. `$this` gefolgt von
    `->name` ohne `(` heißt "Eigenschaft"; `$this->methode()` zählt nicht als Eigenschaft.
  - **Lesen:** Ein statischer Aufruf `Modell::methode(` zählt, wenn `methode` weder in
    `SINGLE_RECORD` noch in `WRITE_METHODS` steht (Schreiben zählt schon oben) - außer
    `methode` ist `with` und der Kette folgt direkt nach der schließenden Klammer
    `->find(` oder `->findOrFail(`.

  Die Wächter-Methode `testNoControllerTouchesTheDatabaseBeyondTheKnownCases()` läuft über
  `src/Controllers/*.php` und `src/Controllers/Oidc/*.php`, baut beide Maps (nur Einträge mit
  Zahl > 0, nach Schlüssel sortiert) und vergleicht sie per `assertSame` mit den Konstanten.

- [x] **Schritt 6: Ausgangslisten eintragen.** Den Wächter einmal mit leeren Konstanten laufen
  lassen und die gefundenen Maps wörtlich in `KNOWN_DIRECT_WRITES` und `KNOWN_DIRECT_READS`
  übernehmen. Erwartet sind rund 128 Schreibstellen in 27 Controllern, darunter
  `VoiceGroupListController` 11, `SongLibraryController` 9, `SponsorshipController` 8.
  Weichen die Zahlen deutlich ab, unter diesem Schritt notieren, welche Form der Zähler
  anders behandelt als erwartet. Klassenkommentar: die Regel in einem Satz, Verweis auf
  `instructions/data-access.md`, Sperrklinke wie bei `ControllerLayeringTest` - eine
  gesunkene Zahl macht den Test ebenso rot wie eine gestiegene, bis die Liste nachgezogen ist.
  Notiz: Gefunden wurden 132 Schreibstellen in 27 und 174 Listenabfragen in 37 Controllern (Plan: rund 128/180).
  Der Zähler wertet unbekannte Kettenanfänge wie `(clone $x)->delete()` als Schreibzugriff und statische
  Aufrufe nur auf importierten Modellen bzw. `Capsule`/`DB`; `SponsorshipController` stand mit 10 statt 8 da.
- [x] **Schritt 7: Läufe.** `--filter ControllerDataAccessTest` → grün; `ddev composer phpcs`.
- [x] **Schritt 8: Schärfeprobe.** In `CategoryController` testweise einen zusätzlichen
  `Category::orderBy('name')->get();` einfügen → Wächter rot. Zurücknehmen.
- [x] **Schritt 9: Commit.** Betreff:
  `test(controllers): Regel für getrennte Lese- und Schreibschicht mit Wächter`

---

### Task 2: VoiceGroupListController

**Dateien:**
- Anlegen: `src/Persistence/VoiceGroupListPersistence.php`, `src/Queries/VoiceGroupListQuery.php`
- Anlegen: `tests/Feature/VoiceGroupListPersistenceFeatureTest.php`,
  `tests/Feature/VoiceGroupListQueryFeatureTest.php`
- Ändern: `src/Controllers/VoiceGroupListController.php`, `src/Dependencies.php` (Zeile 623),
  `tests/Feature/BuildsControllers.php`, die zwei Testdateien mit `new VoiceGroupListController(`,
  `tests/Unit/TestSuite/ControllersAreBuiltThroughBuildersTest.php`,
  `tests/Unit/Controllers/ControllerDataAccessTest.php`

**Schnittstellen:**
- Liefert:
  ```php
  namespace App\Persistence;

  class VoiceGroupListPersistence
  {
      public function createList(int $voiceGroupId, string $title, ?int $projectId, ?int $createdByUserId): VoiceGroupList;
      public function updateList(VoiceGroupList $list, string $title, ?int $projectId): void;
      public function archiveList(VoiceGroupList $list): void;      // archived_at = jetzt
      public function restoreList(VoiceGroupList $list): void;      // archived_at = null
      public function deleteList(VoiceGroupList $list): void;
      public function addColumn(VoiceGroupList $list, string $label, string $type): VoiceGroupListColumn;
      public function renameColumn(VoiceGroupListColumn $column, string $label): void;
      /** @param list<VoiceGroupListColumn> $columns in der neuen Reihenfolge */
      public function renumberColumns(array $columns): void;
      public function deleteColumn(VoiceGroupListColumn $column): void;
      public function storeEntry(VoiceGroupListColumn $column, int $userId, bool|string $value, ?int $actorId): ?VoiceGroupListEntry;
  }

  namespace App\Queries;

  class VoiceGroupListQuery
  {
      /** @param list<int> $voiceGroupIds @return array<string, VoiceGroup> nach Name */
      public function voiceGroupsByName(array $voiceGroupIds): array;
      /** @param list<int> $voiceGroupIds @return Collection<int, VoiceGroupList> mit project, nach Titel */
      public function listsForVoiceGroups(array $voiceGroupIds): Collection;
      /** @param list<int> $voiceGroupIds @return Collection<int, Project> */
      public function allowedProjects(array $voiceGroupIds, ?int $currentProjectId = null): Collection;
  }
  ```
  `addColumn()` setzt `sort_order` auf das bisherige Maximum der Liste plus 1, wie Zeile 316.
  `renumberColumns()` und `storeEntry()` übernehmen die Rümpfe aus `moveColumn()` (Zeilen
  371-379: nur geänderte `sort_order` schreiben) und `storeEntry()` (Zeilen 534-559); die
  Positionssuche und das Tauschen bleiben im Controller. `renumberColumns()` läuft in einer
  Transaktion.
- Konstruktor: `VoiceGroupListPersistence $persistence` und `VoiceGroupListQuery $listQuery`
  als Pflichtparameter hinter `NameFormatterService $names`.

- [x] **Schritt 1: Controller auf den Baustein umstellen** (`voiceGroupListController()` in
  `BuildsControllers`, Eintrag in `MIGRATED`, zwei Baustellen), Läufe grün, eigener Commit
  `test(controllers): VoiceGroupListController in Tests über Baustein`.
- [x] **Schritt 2: Tests schreiben.**
  - `VoiceGroupListPersistenceFeatureTest::testRenumberingSeparatesEqualSortOrders`
    (Review-Schwerpunkt 3): drei Spalten mit `sort_order` 0, 0, 1; `renumberColumns()` in der
    Reihenfolge C, A, B → `sort_order` C=0, A=1, B=2.
  - `::testASecondTickKeepsTheFirstCheck` (Review-Schwerpunkt 4): Häkchen-Spalte, erster
    `storeEntry(..., true, $actorA)`, zweiter `storeEntry(..., true, $actorB)` → `checked_at`
    und `updated_by_user_id` unverändert die des ersten.
  - `::testAnEmptyValueRemovesTheEntry`: Notiz-Spalte, Eintrag "x", dann `storeEntry(..., '')`
    → `null`, keine Zeile mehr.
  - `::testANewColumnGoesToTheEnd`: zwei Spalten mit `sort_order` 0 und 4; `addColumn()` → 5.
  - `VoiceGroupListQueryFeatureTest::testListsAreSortedByTitleAndScopedToTheGroups`: zwei
    Listen der Gruppe A ("Beta", "Alpha"), eine der Gruppe B; `listsForVoiceGroups([A])` →
    Titel `['Alpha', 'Beta']`.
- [x] **Schritt 3: Rotlauf**, dann **Schritt 4: Klassen schreiben**, dann **Schritt 5:
  Controller umstellen** (Zeilen 88, 89, 151, 238, 253, 269, 285, 316, 345, 377, 401, 536-556,
  650) und Container sowie Baustein nachziehen.
- [x] **Schritt 6: Wächter nachziehen.** In `ControllerDataAccessTest` die Einträge des
  `VoiceGroupListController` streichen (erwartet: beide 0).
- [x] **Schritt 7: Läufe.**
  `ddev php vendor/bin/phpunit --filter "VoiceGroupList|ControllerDataAccess|ControllerLayering|DependenciesContainerWiring|BuildsControllers"`
  → grün; `ddev composer phpcs`.
- [x] **Schritt 8: Schärfeprobe.** In `renumberColumns()` nur bei `sort_order === 0`
  schreiben → `testRenumberingSeparatesEqualSortOrders` rot. Zurücknehmen.
- [x] **Schritt 9: Commit.** Betreff:
  `refactor(voice-group-lists): Schreiben über Persistenz, Lesen über Query`

---

### Task 3: SongLibraryController

**Dateien:**
- Anlegen: `src/Persistence/SongPersistence.php`, `src/Queries/SongQuery.php`
- Anlegen: `tests/Feature/SongPersistenceFeatureTest.php`, `tests/Feature/SongQueryFeatureTest.php`
- Ändern: `src/Controllers/SongLibraryController.php`, `src/Dependencies.php` (Zeile 588),
  `tests/Feature/BuildsControllers.php`, die fünf Testdateien mit `new SongLibraryController(`,
  `ControllersAreBuiltThroughBuildersTest.php`, `ControllerDataAccessTest.php`

**Schnittstellen:**
- Liefert:
  ```php
  namespace App\Persistence;

  class SongPersistence
  {
      /** @param array<string, mixed> $attributes @param list<int> $categoryIds */
      public function createSong(array $attributes, array $categoryIds): Song;   // Transaktion
      /** @param array<string, mixed> $attributes */
      public function updateSong(Song $song, array $attributes): void;
      public function deleteSong(Song $song): void;
      /** @param list<int> $categoryIds */
      public function syncCategories(Song $song, array $categoryIds): void;
      /** @param array<string, mixed> $attributes */
      public function createLinkResource(int $songId, array $attributes): SongResource;
      /** @param array<string, mixed> $attributes */
      public function updateLinkResource(SongResource $resource, array $attributes): void;
      public function deleteLinkResource(SongResource $resource): void;
  }

  namespace App\Queries;

  class SongQuery
  {
      /** Übersicht: Kategorien, Anhänge nach Name, Projektzuordnungen; nach Titel. */
      public function forIndex(string $search, int $categoryId): Collection;
      /** Kategorien nach sort_order, dann Name. */
      public function categoriesInOrder(): Collection;
      /** Projekte nach Name mit zugeordneten Liedern (nach Titel) und deren Anhängen (nach Name). */
      public function projectsWithAssignedSongs(): Collection;
      public function projectsChronological(): Collection;
  }
  ```
  `forIndex()` übernimmt den Rumpf aus `index()` (Zeilen 62-84) samt Suchfeldern `title`,
  `composer`, `arranger`, `publisher`; ein leerer Suchbegriff bzw. Kategorie 0 filtert nicht.
  `show()` behält `Song::with([...])->find($songId)` (nach der Regel erlaubt).
  `deleteAttachment()` (Zeilen 443-449) nutzt den vorhandenen
  `EntityAttachmentService::deleteForEntity(string, int, int): bool` - die Meldung
  "Anhang nicht gefunden." kommt, wenn er `false` liefert.
- Konstruktor: `SongPersistence $songs` und `SongQuery $songQuery` als Pflichtparameter
  hinter `Twig $view` (vor dem optionalen Logger).

- [x] **Schritt 1: Controller auf den Baustein umstellen** (sieben Baustellen in fünf
  Dateien, Kollisionen nach a2 prüfen), eigener Commit.
- [x] **Schritt 2: Tests schreiben.**
  - `SongPersistenceFeatureTest::testANewSongIsNotLeftWithoutItsCategories`: Kategorie-Kennung
    `999999` (gibt es nicht) → `createSong()` wirft, kein Lied mit dem Titel in der Datenbank.
  - `SongQueryFeatureTest::testSearchMatchesComposerArrangerAndPublisher`: drei Lieder, je eins
    mit dem Suchbegriff in `composer`, `arranger`, `publisher`, eins ohne → drei Treffer.
  - `::testTheCategoryFilterOnlyKeepsSongsOfThatCategory`.
  - `::testCategoriesFollowSortOrderThenName`: Kategorien (2, "B"), (1, "Z"), (1, "A") →
    Namen `['A', 'Z', 'B']`.
- [x] **Schritt 3: Rotlauf**, **Schritt 4: Klassen schreiben**, **Schritt 5: Controller
  umstellen** (Zeilen 62-84, 86, 89, 135, 136, 155, 177-190, 233, 255, 276, 340, 369, 390,
  443-449), Container und Baustein nachziehen.
- [x] **Schritt 6: Wächter nachziehen** (Einträge des `SongLibraryController` streichen).
- [x] **Schritt 7: Läufe.**
  `ddev php vendor/bin/phpunit --filter "Song|ControllerDataAccess|ControllerLayering|DependenciesContainerWiring|BuildsControllers|EntityCleanup"`
  → grün; `ddev composer phpcs`.
- [x] **Schritt 8: Schärfeprobe.** In `forIndex()` die `orWhere`-Zeile für `publisher`
  entfernen → Suchtest rot. Zurücknehmen.
- [x] **Schritt 9: Commit.** Betreff:
  `refactor(songs): Schreiben über Persistenz, Lesen über Query`

---

### Task 4: SponsorshipController

**Dateien:**
- Anlegen: `src/Persistence/SponsorshipPersistence.php`
- Ändern: `src/Queries/SponsoringQuery.php`
- Anlegen: `tests/Feature/SponsorshipPersistenceFeatureTest.php`
- Ändern: `src/Controllers/SponsorshipController.php`, `src/Dependencies.php` (Zeile 614),
  `tests/Feature/BuildsControllers.php`, die zwölf Testdateien mit `new SponsorshipController(`,
  `ControllersAreBuiltThroughBuildersTest.php`, `ControllerDataAccessTest.php`

**Schnittstellen:**
- Liefert:
  ```php
  namespace App\Persistence;

  class SponsorshipPersistence
  {
      /** @param array<string, mixed> $attributes */
      public function create(array $attributes): Sponsorship;
      /** @param array<string, mixed> $attributes */
      public function update(Sponsorship $sponsorship, array $attributes): void;
      public function delete(Sponsorship $sponsorship): void;
      /** Haken einer Leistungszeile; $field ist der Wortstamm ("delivered" => delivered_at, delivered_by_user_id). */
      public function tickLine(SponsorshipBenefit|SponsorshipDeliverable $line, string $field, bool $done, ?int $actorId): void;
      /**
       * Gleicht die Zeilen einer Relation mit der neuen Liste ab (Rumpf von syncLines()).
       * @param HasMany<Model, Sponsorship> $relation @param list<string> $items
       */
      public function syncLines(HasMany $relation, array $items): void;   // Transaktion
      public function renameAttachment(Attachment $attachment, string $name): void;
  }
  ```
  In `SponsoringQuery` kommen hinzu:
  ```php
  public function hasNoCapableAssignee(int $sponsorshipId): bool; // Zeile 470: whereKey()->withoutCapableAssignee()->exists()
  public function isSponsorBlocked(int $sponsorId): bool;         // Rumpf von isBlocked(), Zeile 909
  public function attachmentNameTaken(int $sponsorshipId, string $name, ?int $exceptId = null): bool;  // Zeile 603
  public function findAttachment(int $sponsorshipId, int $attachmentId): ?Attachment;                  // Zeile 664
  ```
  Die Fachregeln - `logoNeeds()`, `validateAssignedUser()`, Statusprüfungen - bleiben im
  Controller; die Persistenz bekommt fertige Attribute. Die fünf `with(...)->find($id)`-Stellen
  (448, 498, 587, 730, 751) bleiben nach der Regel im Controller. Die Prüfung "Zustand ändert
  sich überhaupt" aus `tickLine()` (Zeilen 776-779) zieht mit in die Persistenz.
- Konstruktor: `SponsorshipPersistence $persistence` als Pflichtparameter hinter
  `EntityAttachmentService $attachments`.

- [x] **Schritt 1: Controller auf den Baustein umstellen** (14 Baustellen in zwölf
  Dateien), eigener Commit.
- [x] **Schritt 2: Tests schreiben.**
  - `SponsorshipPersistenceFeatureTest::testALineKeepsItsTickWhenItsTextStays`
    (Review-Schwerpunkt 5): Zeilen "Logo", "Banner", "Logo" erledigt; `syncLines()` mit
    `['Banner', 'Logo', 'Flyer']` → "Logo" weiter erledigt mit `sort_order` 1, "Banner"
    `sort_order` 0, "Flyer" neu und offen, keine weiteren Zeilen.
  - `::testAnEmptyListRemovesAllLines`.
  - `::testTickingTwiceKeepsTheFirstDate`: `tickLine(..., true, $a)` und erneut
    `tickLine(..., true, $b)` → Datum und Person des ersten; der Controller ruft heute nur bei
    Zustandswechsel, die Persistenz prüft das selbst.
- [x] **Schritt 3: Rotlauf**, **Schritt 4: Klasse und Query-Methoden schreiben**,
  **Schritt 5: Controller umstellen** (Zeilen 182, 314, 470, 475, 527, 558, 603, 664, 683,
  780-784, 805-827, 909), Container und Baustein nachziehen.
  Abweichung: Statt `attachmentNameTaken()` liefert `SponsoringQuery::attachmentNames()` die Namensliste, weil
  der Controller sie für `SponsorshipFileName::build()` braucht; der Rotlauf von Schritt 3 entfiel für
  `SponsorshipPersistenceFeatureTest` (Test und Klasse entstanden zusammen), die Schärfeprobe belegt ihn.
- [x] **Schritt 6: Wächter nachziehen.** Erwartet: Schreiben 0, Lesen 0 für den
  `SponsorshipController`.
- [x] **Schritt 7: Läufe.**
  `ddev php vendor/bin/phpunit --filter "Sponsor|ControllerDataAccess|ControllerLayering|DependenciesContainerWiring|BuildsControllers"`
  → grün; `ddev composer phpcs`.
- [x] **Schritt 8: Schärfeprobe.** In `syncLines()` den Vergleich auf den Text weglassen
  (jede Zeile neu anlegen) → `testALineKeepsItsTickWhenItsTextStays` rot. Zurücknehmen.
- [x] **Schritt 9: Commit.** Betreff:
  `refactor(sponsoring): Vereinbarungen über Persistenz schreiben`

---

### Task 5: Abschluss

- [x] **Schritt 1: Volle Suite.** `ddev composer test:parallel` → grün, Zahlen notieren;
  `ddev composer phpcs`, `ddev composer eol:check`.
- [x] **Schritt 2: Bericht an Georg.** Je Task ein Satz, die Zahlen der Läufe, die verbliebenen
  Einträge in beiden Listen des Wächters (Summen), und die Controller, die als Nächstes lohnen:
  die mit den meisten Schreibstellen laut `KNOWN_DIRECT_WRITES`. Kein Squash, kein Merge, kein
  Push ohne seine Ansage.

---

## Bewusst nicht Teil dieses Plans

- **Die übrigen 24 Controller.** Sie werden umgestellt, wenn sie ohnehin angefasst werden;
  der Wächter verhindert, dass ihre Zahlen steigen.
- **Umbenennen von `FinanceBookingService` und `ParticipationWriter`** auf das Schema
  `…WriteService`. Rein kosmetisch, rund 20 Dateien Aufwand.
- **Schreibzugriffe in Middleware und Commands.** Die Regel gilt zunächst für Controller.
- **Getrennte Lesemodelle oder CQRS mit Ereignissen.** Für eine Datenbank und Eloquent ohne
  Nutzen; die Trennung bleibt eine Regel über Klassen, nicht über Datenhaltung.
