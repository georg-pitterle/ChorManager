# Backup-Paket zum Mitnehmen – Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ein Backup lässt sich als eine ZIP-Datei herunterladen, in den Backup-Ordner einer
anderen Installation legen und dort per Konsolenbefehl übernehmen und wiederherstellen; intern
bleibt der deduplizierende Datei-Pool.

**Architecture:** Neuer `BackupPackageService` baut Pakete (Download) und übernimmt Pakete
(Konsole). Den Pool-Teil (welche Dateien gehören dazu, Dateien hinein in den Pool samt
Prüfung und Aufräumen) liefert `FileBackupService` über zwei neue Methoden, die `archive()`
ersetzen. `BackupService::list()` bleibt ohne Seiteneffekt und bekommt nur das abgeleitete
Feld `mail_key_mismatch`.

**Tech Stack:** PHP 8, `ext-zip` (`ZipArchive`), Symfony Console, Slim, Twig, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-10-24-portable-backup-package-design.md`

## Global Constraints

- Übernahme **nur** über `bin/import_backup.php` (`backup:import`), nie aus `list()`,
  `create()` oder einem Web-Request.
- Paketname im Ordner: `<backupId>.zip`, `<backupId>` passt auf `BackupService::ID_PATTERN`;
  andere ZIPs werden ignoriert. Gescheitert: Umbenennung zu `<backupId>.zip.failed`.
- Paketinhalt exakt: `backup.json`, optional `manifest.json`, `database.sql.gz` bzw.
  `database.sql` (nach `gzip` in `backup.json`), `files/<storage_path>`. Jeder andere Eintrag
  lässt die Übernahme scheitern.
- Alle ZIP-Einträge mit `ZipArchive::CM_STORE` (Dump ist schon gzip, Dateien sind meist
  PDF/MP3) – Entscheidung des Plans.
- Nie ein vorhandenes Backup überschreiben; `<backupId>.json` wird als **letzte** Datei
  einer Übernahme geschrieben.
- Übernahme ignoriert `maxManual`/`maxAuto`; übernommene Backups behalten `type` und alle
  Metadaten unverändert (kein `imported_at`).
- Log-Events: `backup.import.completed`, `backup.import.failed` (mit `exception`),
  `backup.package.failed` (Download).
- Mail-Schlüssel-Abweichung warnt, blockiert nie.
- Bezeichner englisch, UI-Texte/Kommentare deutsch mit echten Umlauten; LF-Zeilenenden.
- Tests gefiltert laufen lassen (`ddev composer test -- --filter <Name>`), volle Suite
  einmal am Schluss; `ddev composer phpcs`, für Twig `ddev composer twigcs`.

## Review Focus

- ZIP-Eintrag `files/../../x` oder absoluter Pfad → Übernahme scheitert, nichts außerhalb
  des Backup-Ordners geschrieben (Test in Task 3).
- Pool enthält Pfad schon mit **anderem** Inhalt → scheitert, Pool-Datei unverändert
  (Test in Task 2).
- Übernahme bricht nach dem Schreiben der Pool-Dateien ab → keine neuen Pool-Dateien, kein
  Dump, kein Manifest übrig; vorher vorhandene Pool-Dateien bleiben (Tests in Task 2 und 3).
- Zweiter paralleler `backup:import` → bricht mit Meldung ab statt doppelt zu übernehmen
  (Test in Task 4).
- Dev-Reset bei wartendem Paket → Paket bleibt liegen (Test in Task 3, über `list()`).

---

### Task 1: Paket bauen und herunterladen

Ersetzt die getrennten Downloads durch ein ZIP.

**Files:**
- Create: `src/Services/BackupPackageService.php`
- Modify: `src/Services/Files/FileBackupInterface.php` (`archive()` → `packageContents()`)
- Modify: `src/Services/Files/FileBackupService.php` (`archive()` entfernen, `packageContents()`)
- Modify: `src/Services/BackupService.php` (`getFilesArchive()` entfernen, `ID_PATTERN` public)
- Modify: `src/Controllers/BackupController.php` (`download()` liefert Paket, `downloadFiles()` weg)
- Modify: `src/Routes.php:885-888` (Route `download-files` weg)
- Modify: `src/Dependencies.php` (Factory `BackupPackageService`)
- Modify: `templates/backups/index.twig:95-99` (Knopf „Dateien“ weg)
- Test: `tests/Feature/BackupPackageFeatureTest.php` (neu), `tests/Feature/FileBackupFeatureTest.php`
  (`testFilesArchiveContainsManifestAndFiles` entfernen), `tests/Feature/BackupControllerHttpTest.php`

**Interfaces:**
- Produces:
  - `BackupService::ID_PATTERN` (public const)
  - `FileBackupInterface::packageContents(string $backupDir, string $backupId): ?array` →
    `array{manifest: string, files: array<string, string>}|null` – Manifest-JSON roh und
    `storage_path => absoluter Pool-Pfad`; `null` ohne Manifest. Wirft, wenn eine
    Manifest-Datei im Pool fehlt.
  - `BackupPackageService::__construct(BackupService $backups, string $backupDir, ?FileBackupInterface $fileBackup, LoggerInterface $logger)`
  - `BackupPackageService::build(string $id): array{path: string, filename: string, size: int}` –
    Temp-ZIP unter `sys_get_temp_dir()`, `filename` = `<id>.zip`; Aufrufer löscht die Datei.
    Wirft `\InvalidArgumentException` (ungültige Kennung) bzw. `\RuntimeException` (unbekannt).
  - `BackupController::__construct(Twig $view, BackupService $backupService, BackupPackageService $packages, LoggerInterface $logger)`

- [x] **Step 1: Failing tests schreiben** in `tests/Feature/BackupPackageFeatureTest.php`
  (Aufbau wie `FileBackupFeatureTest`: `FileFixtures`, eigenes Temp-Backup-Verzeichnis,
  `FakeDumpRunner`, Helfer `packages(BackupService): BackupPackageService`):
  - `testPackageContainsMetadataManifestDumpAndExactlyTheManifestFiles`: Datei hochladen,
    Backup, `build()`; ZIP enthält `backup.json` (gleich `<id>.json`), `manifest.json`
    (gleich `<id>.files.json`), `database.sql.gz` (gleicher sha256 wie Metadaten),
    `files/<pfad>` mit Inhalt `'Inhalt A'`, sonst nichts (`$zip->numFiles === 4`).
  - `testPackageOfDatabaseOnlyBackupHasNoManifestAndNoFiles`: Backup mit
    `backups(withFiles: false)` → genau `backup.json` und `database.sql.gz`.
  - `testPackageEntriesAreStoredUncompressed`: `statName(...)['comp_method'] === ZipArchive::CM_STORE`
    für alle Einträge.
  - `testBuildRejectsUnknownBackup`: `expectException(\RuntimeException::class)` für eine
    gültige, nicht vorhandene Kennung.
  - In `BackupControllerHttpTest`: `testDownloadStreamsExistingBackupFile` umstellen auf
    `Content-Type` `application/zip` und `Content-Disposition` mit `<id>.zip`; neu
    `testDownloadFilesRouteIsGone` (`Routes.php` enthält `download-files` nicht).
- [x] **Step 2: Tests laufen lassen, Fehlschlag prüfen**
  Run: `ddev composer test -- --filter "BackupPackageFeatureTest|BackupControllerHttpTest"`
  Expected: FAIL (`BackupPackageService` unbekannt).
- [x] **Step 3: Implementieren.** `packageContents()` in `FileBackupService` aus `readManifest()`
  und `pool()->localPath()`; `build()` öffnet `ZipArchive` mit `CREATE`, fügt Einträge per
  `addFile()` hinzu und setzt je Eintrag `setCompressionName(..., ZipArchive::CM_STORE)`;
  Dump-Pfad aus `BackupService::getFile()`, Eintragsname `database.sql.gz` bzw. `database.sql`
  nach Endung. Bei Fehler Temp-Datei löschen. Controller: vor `build()` `set_time_limit(0)`;
  Stream öffnen, Temp-Datei sofort `unlink` (wie bisher `downloadFiles()`), Fehler mit
  `backup.package.failed` loggen und 404. `archive()`, `getFilesArchive()`, `downloadFiles()`
  und Route entfernen.
- [x] **Step 4: Tests laufen lassen**
  Run: `ddev composer test -- --filter "BackupPackageFeatureTest|BackupControllerHttpTest|FileBackupFeatureTest|BackupServiceTest"`
  Expected: PASS
- [x] **Step 5: Größe messen.** Im ddev-Container ein Backup der aktuellen Dev-DB
  (~190 MB Dump) erstellen und `time` für `build()` messen (kleines Skript im Scratchpad,
  nicht im Repo). Ergebnis im Commit-Text nennen. Liegt es über 60 s: stoppen und dem
  Entwickler berichten, bevor es weitergeht.
  Gemessen: 190 MB Dump → `build()` 0,37 s.
- [x] **Step 6: Commit** (über `/git-commit`)

### Task 2: Dateien eines Pakets in den Pool übernehmen

**Files:**
- Modify: `src/Services/Files/FileBackupInterface.php`, `src/Services/Files/FileBackupService.php`
- Test: `tests/Feature/FileBackupFeatureTest.php`

**Interfaces:**
- Produces:
  `FileBackupInterface::importFiles(string $backupDir, string $backupId, string $manifestJson, \Closure $open): void`
  – `$open(string $storagePath): resource|false` liefert den Inhalt einer Datei aus dem Paket.
  Prüft: Manifest gültig (`readManifest`-Regeln), `backup_id` im Manifest gleich `$backupId`,
  `<backupId>.files.json` existiert noch nicht. Je Eintrag: liegt im Pool → sha256 muss passen,
  sonst `\RuntimeException`; fehlt → schreiben, danach sha256 prüfen. Schreibt
  `<backupId>.files.json` zuletzt. Bei jeder Ausnahme: nur die in diesem Aufruf neu
  geschriebenen Pool-Dateien und ein ggf. geschriebenes Manifest entfernen, dann weiterwerfen.

- [x] **Step 1: Failing tests** in `FileBackupFeatureTest` (Paketinhalt simuliert über ein
  Array `storage_path => Inhalt` und `$open = fn ($p) => fopen('data://text/plain,' . ..., 'rb')`
  bzw. Temp-Dateien):
  - `testImportFilesWritesMissingFilesAndManifest`
  - `testImportFilesKeepsIdenticalPoolFileWithoutRewriting` (mtime bzw. Inode unverändert)
  - `testImportFilesRejectsPoolConflictAndLeavesPoolFileUntouched` (Pool-Inhalt `'alt'`,
    Paket `'neu'` mit Hash von `'neu'` → Exception, Pool-Datei weiter `'alt'`)
  - `testImportFilesRejectsHashMismatchAndRemovesOnlyNewlyWrittenFiles` (zwei Dateien,
    erste neu und korrekt, zweite mit falschem Hash → beide neuen weg, eine vorher
    vorhandene dritte bleibt, kein `.files.json`)
  - `testImportFilesRejectsManifestOfAnotherBackupId`
  - `testImportFilesRejectsInvalidPathInManifest` (`../x`)
- [x] **Step 2: Fehlschlag prüfen** – `ddev composer test -- --filter FileBackupFeatureTest` → FAIL
- [x] **Step 3: Implementieren** in `FileBackupService` (Schreiben über `pool()->writeAt()`,
  Hash über `hash_file` auf `pool()->localPath()`).
- [x] **Step 4: Tests laufen lassen** – gleicher Befehl → PASS
- [x] **Step 5: Commit**

### Task 3: Paket übernehmen (`BackupPackageService::importAll`)

**Files:**
- Modify: `src/Services/BackupPackageService.php`
- Create: `src/Services/BackupImportLockedException.php`
- Test: `tests/Feature/BackupPackageFeatureTest.php`

**Interfaces:**
- Consumes: `BackupService::ID_PATTERN`, `FileBackupInterface::importFiles()` (Task 2),
  `BackupPackageService::build()` (Task 1, für Rundreise-Tests).
- Produces:
  - `BackupPackageService::importAll(): array<string, string|null>` – Kennung ⇒ `null` bei
    Erfolg, sonst deutsche Fehlerursache. Reihenfolge nach Dateiname. Wirft
    `BackupImportLockedException`, wenn `<backupDir>/.import.lock` per
    `flock(LOCK_EX | LOCK_NB)` belegt ist.
  - `BackupPackageService::pendingPackages(): array{waiting: list<string>, failed: list<string>}` –
    nur Dateinamen per `glob`, öffnet kein ZIP.
  - `final class BackupImportLockedException extends \RuntimeException`

Ablauf je Paket wie Spec „Übernahme eines Pakets“ Schritte 1–8. Bei Fehler: Dump entfernen,
nach erfolgreichem `importFiles()` zusätzlich `fileBackup->forget($backupDir, $id)`, Paket zu
`.zip.failed`, `backup.import.failed` loggen. Fehlerursachen (exakter Text, im Test geprüft):
`'Kennung existiert bereits.'`, `'Paket ist unvollständig oder beschädigt.'`,
`'Prüfsumme der Datenbank stimmt nicht.'`, `'Dateien des Pakets sind ungültig.'`.

- [x] **Step 1: Failing tests** (zwei Backup-Verzeichnisse A und B, jeweils eigener
  `BackupService` und eigener Pool; ZIPs aus A per `build()` nach B kopieren, für manipulierte
  Fälle mit `ZipArchive` von Hand bauen):
  - `testRoundTripPackageFromOtherInstallationRestoresDatabaseAndFiles`: vor `importAll()`
    listet B das Backup nicht, `pendingPackages()['waiting']` nennt `<id>.zip`; danach
    `importAll() === [$id => null]`, `list()` enthält es mit gleichen Metadaten, `.zip` ist weg,
    `restore($id)` ruft den Dump-Runner mit `<id>.sql.gz` und schreibt die Datei zurück
    (Ablage vorher leeren).
  - `testImportDeduplicatesFilesAlreadyInPool`
  - `testListNeverImportsAndDevResetLeavesWaitingPackage`: Paket in B, `list()` aufrufen und
    jedes gelistete Backup löschen (wie `src/Services/DevSeedService.php:364`) → `.zip` liegt noch.
  - `testImportSucceedsBeyondManualLimit`: B mit `maxManual = 1` und einem vorhandenen
    manuellen Backup → Übernahme gelingt.
  - Fehlschläge, jeweils: Ergebnis-Text wie oben, `.zip.failed` existiert, `.zip` weg, kein
    `<id>.json`/`<id>.sql.gz`/`<id>.files.json`, Pool wie vorher:
    `testImportRejectsExistingId` (zusätzlich: vorhandene Dateien byte-gleich),
    `testImportRejectsMetadataIdNotMatchingFileName`,
    `testImportRejectsPathTraversalEntry` (Eintrag `files/../../evil.txt` und `../evil.txt`;
    keine Datei `evil.txt` irgendwo unter dem Temp-Verzeichnis der Tests),
    `testImportRejectsUnexpectedEntry` (`extra.txt`),
    `testImportRejectsDumpChecksumMismatch`,
    `testImportRejectsFileHashMismatch`.
  - `testForeignZipNamesAreIgnored` (`irgendwas.zip` bleibt unberührt, nicht in `pendingPackages()`).
  - `testImportThrowsWhenLockIsHeld` (Lock-Datei im Test selbst per `flock` halten).
- [x] **Step 2: Fehlschlag prüfen** – `ddev composer test -- --filter BackupPackageFeatureTest` → FAIL
- [x] **Step 3: Implementieren.** Dump per `$zip->getStream()` + `stream_copy_to_stream` nach
  `<id>.sql.gz` bzw. `.sql`; `<id>.json` mit dem rohen `backup.json`-Inhalt schreiben.
- [x] **Step 4: Tests laufen lassen** – gleicher Befehl → PASS
- [x] **Step 5: Commit**

### Task 4: Konsolenbefehl `backup:import`

**Files:**
- Create: `src/Commands/ImportBackupCommand.php`, `bin/import_backup.php`
- Test: `tests/Feature/ImportBackupCommandFeatureTest.php` (Vorbild `CreateBackupCommandFeatureTest`)

**Interfaces:**
- Consumes: `BackupPackageService::importAll()`, `BackupImportLockedException` (Task 3)
- Produces: Befehl `backup:import`, Exit-Codes `Command::SUCCESS` (alle übernommen oder keines
  da), `Command::FAILURE` (mindestens eines gescheitert oder Sperre belegt).

Ausgabe je Paket: `Übernommen: <id>` bzw. `Fehlgeschlagen: <id> – <Ursache>`; ohne Pakete
`Keine Pakete zum Übernehmen gefunden.`; Sperre: `Eine andere Übernahme läuft gerade.`
`bin/import_backup.php` wie `bin/create_backup.php`, Default-Command `backup:import`.

- [x] **Step 1: Failing tests** mit `CommandTester`: `testImportsWaitingPackageAndReportsSuccess`,
  `testReportsFailedPackageWithReasonAndFailureExitCode`, `testReportsNothingToImport`,
  `testFailsWhenLockIsHeld`.
- [x] **Step 2: Fehlschlag prüfen** – `ddev composer test -- --filter ImportBackupCommandFeatureTest` → FAIL
- [x] **Step 3: Implementieren**
- [x] **Step 4: Tests laufen lassen** → PASS; zusätzlich `ddev php bin/import_backup.php` im
  leeren Dev-Ordner → `Keine Pakete zum Übernehmen gefunden.`
- [x] **Step 5: Commit**

### Task 5: Backup-Seite – Hinweise, Mail-Schlüssel, Spalte „Dateien“

**Files:**
- Modify: `src/Services/BackupService.php` (`list()` ergänzt `mail_key_mismatch`)
- Modify: `src/Controllers/BackupController.php` (`index()` übergibt `pending_packages`)
- Modify: `templates/backups/index.twig`, `public/js/backups.js`
- Test: `tests/Unit/Services/BackupServiceTest.php`, `tests/Feature/BackupControllerHttpTest.php`

**Interfaces:**
- Consumes: `BackupPackageService::pendingPackages()` (Task 3)
- Produces: Template-Variable `pending_packages` (Form wie `pendingPackages()`); Listenfeld
  `mail_key_mismatch: bool` – `true` nur, wenn aktueller Schlüssel und `mail_key_id` beide
  nicht `null` und verschieden sind.

Texte (exakt):
- Seitenkopf: `Backups der Datenbank und der Dateiverwaltung erstellen, herunterladen und wiederherstellen.`
- Zusatz im Warnhinweis: `Zum Umzug in eine andere Installation: Backup herunterladen, die ZIP-Datei in deren Backup-Ordner legen und dort „php bin/import_backup.php“ ausführen.`
- Wartende Pakete (`alert-info`): `Im Backup-Ordner liegen Pakete, die noch nicht übernommen sind:` + Liste + `Übernehmen mit „php bin/import_backup.php“.`
- Gescheiterte Pakete (`alert-warning`): `Diese Pakete konnten nicht übernommen werden:` + Liste + `Den Grund nennt das Protokoll; die Dateien bitte von Hand aus dem Backup-Ordner entfernen.`
- Spalte „Dateien“: `{{ files_count }} Dateien ({{ MB }} MB)` plus kleiner Muted-Text
  `im gemeinsamen Pool`, `title`: `Jede Datei liegt nur einmal im Backup-Ordner, egal wie viele Backups sie enthalten.`
- Badge bei `mail_key_mismatch`: `<span class="badge text-bg-warning">anderer Mail-Schlüssel</span>` in der Typ-Spalte.
- Restore-Knopf erhält `data-mail-key-mismatch="1"` bzw. `"0"`; Modal enthält
  `<div class="alert alert-warning d-none" id="restoreMailKeyWarning">Dieses Backup stammt von einem anderen Mail-Schlüssel. Gespeicherte Mail-Zugangsdaten lassen sich danach nicht mehr entschlüsseln und müssen neu eingegeben werden.</div>`;
  `backups.js` schaltet `d-none` nach dem Attribut.

- [x] **Step 1: Failing tests**
  - `BackupServiceTest`: `testListFlagsMailKeyMismatch`, `testListDoesNotFlagMatchingMailKey`,
    `testListDoesNotFlagWhenEitherKeyIsUnknown` (aktuell `null` / Metadaten `null`).
  - `BackupControllerHttpTest` mit echtem `Twig::create(<templates>)` (wie
    `EventAudienceControllerFeatureTest`) für `index()`: `testIndexShowsWaitingAndFailedPackages`
    (Dateinamen und beide Texte im HTML), `testIndexMarksMailKeyMismatchOnRow`
    (Badge-Text und `data-mail-key-mismatch="1"`), `testIndexHasNoFilesDownloadButton`
    (`download-files` nicht im HTML).
- [x] **Step 2: Fehlschlag prüfen** – `ddev composer test -- --filter "BackupServiceTest|BackupControllerHttpTest"` → FAIL
- [x] **Step 3: Implementieren**
- [x] **Step 4: Tests laufen lassen** → PASS; `ddev composer twigcs` → keine Fehler
- [x] **Step 5: Sichtprüfung headless** im ddev-Container: `/backups` mit einem wartenden und
  einem `.zip.failed`-Paket rendern, Screenshot Desktop und Mobil ansehen.
- [x] **Step 6: Commit**

### Task 6: Abschluss

- [x] **Step 1:** `BackupUsageProvider` prüfen: `.zip`/`.zip.failed` werden dem Typ zugerechnet
  (Muster greift); Test `testCountsPackagesUnderTheirBackupType` in
  `tests/Unit/Services/Storage/BackupUsageProviderTest.php` ergänzen, falls nicht schon
  abgedeckt.
- [x] **Step 2:** `DevSeedBackupCoverageFeatureTest` laufen lassen; Seed braucht nichts Neues
  (Backups sind Dateien).
- [x] **Step 3:** Volle Suite `ddev composer test`, `ddev composer phpcs`, `ddev composer twigcs` → grün
- [ ] **Step 4:** Commit über `/git-commit`; nicht pushen.
