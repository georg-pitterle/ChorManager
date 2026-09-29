# Entwurf: Modul „Dateiverwaltung" (Teamordner à la Nextcloud)

## Kontext

Chöre verteilen Noten, Übe-Audios, Protokolle, Verträge heute über Anhänge an Songs/Tasks/Sponsoring
oder außerhalb der App. Es fehlt ein zentraler Ablageort mit Ordnern und fein steuerbarem Zugriff.
Ziel: Modul „Dateien" — Teamordner anlegen, Dateien hochladen/organisieren, pro Ordner an Rollen,
Mitglieder, Stimmgruppen, Projektmitglieder oder alle Mitglieder mit Stufen freigeben.

Entscheidungen (mit Georg geklärt):
- Rechte: Stufen pro Freigabe — **Lesen < Hochladen < Bearbeiten < Verwalten**; Unterordner erben
  additiv, eigene Freigaben erweitern (kein Entzug/Deny).
- Freigabeziele: Rolle, Mitglied, Stimmgruppe, Projektmitglieder, alle aktiven Mitglieder.
- Phase 1: Papierkorb (30 Tage) + Versionen, Speicherkontingent, Drag&Drop-Mehrfach-Upload mit
  Fortschritt, Vorschau (PDF/Bild/Audio), ZIP-Download Ordner, Suche + Favoriten.
- Ablage: **eigene Storage-Naht, Treiber `local` auf Disk** (`var/files`), nicht BLOB. WebDAV-Treiber
  später über dieselbe Naht möglich.
- WebDAV-Zugang (`/webdav`) für Teamordner: **später**, nicht in diesem Plan.
- **Dateien kommen mit ins Backup** (inkrementeller Pool, siehe Architektur).
- Globales Rollenrecht `can_manage_files` („Dateiverwaltung verwalten"): Teamordner anlegen,
  Kontingente, alle Freigaben; implizit Stufe Verwalten überall.

## Datenmodell (Phinx, Skill `/phinx-migration`)

| Tabelle | Spalten (Kern) |
|---|---|
| `file_folders` | id, parent_id NULL (NULL = Teamordner), name, quota_bytes NULL (nur Wurzel), created_by, timestamps, deleted_at NULL, deleted_by NULL; Index (parent_id, name) |
| `files` | id, folder_id, name, current_version_id NULL, size, mime_type, created_by, updated_by, timestamps, deleted_at, deleted_by; Index (folder_id, name), Index name (Suche) |
| `file_versions` | id, file_id, version_number, storage_driver, storage_path, size, mime_type, sha256, uploaded_by, created_at |
| `file_folder_shares` | id, folder_id, target_type ENUM(role,user,voice_group,project_members,all_members), reference_id NULL, level TINYINT(1–4), created_by, created_at; UNIQUE(folder_id,target_type,reference_id) |
| `file_favorites` | id, user_id, file_id NULL, folder_id NULL, created_at; UNIQUE je Ziel |
| `roles` | + `can_manage_files` TINYINT DEFAULT 0 (+ Backfill-Migration für höchste Hierarchiestufe, Muster `20260731090200_backfill_can_manage_roles_permission.php`) |

Eindeutige Namen im Ordner (ohne Papierkorb) im Service erzwingen (NULL-`deleted_at` macht UNIQUE
in MySQL unbrauchbar). FKs mit `ON DELETE CASCADE` für Versionen/Freigaben/Favoriten.

## Architektur

**Konfiguration** — `src/Settings.php`: `modules.files` (`FEATURE_FILES`), Block `files`:
`FILES_STORAGE_PATH` (Default `var/files`), `FILES_MAX_UPLOAD_MB` (Default 100),
`FILES_MAX_VERSIONS` (10), `FILES_TRASH_DAYS` (30), `FILES_TOTAL_QUOTA_MB` (optional). `.env.example` ergänzen.

**Storage-Naht** `src/Services/FileStorage/`:
- `FileStorage` (Interface): `put(string $sourcePath): string` (liefert storage_path), `readStream()`,
  `readRange()`, `size()`, `delete()`, `name()`.
- `LocalFileStorage`: Pfad `ab/cd/<uuid>` unter Basisverzeichnis, nie Nutzername im Pfad → kein
  Traversal; `realpath`-Prüfung gegen Basis. Schnittstelle bewusst analog zu
  `docs/attachment-storage-backends.md`, damit ein späterer WebDAV-Treiber passt.
- `FileStorageRegistry`: wählt nach `storage_driver`, unbekannt → Exception.

**Services** (`src/Services/Files/`):
- `FileAccessService` — effektive Stufe = Max über Freigaben des Ordners + aller Vorfahren
  (`can_manage_files` → 4). `visibleRootFoldersQuery(userId)` + `levelFor(folder, userId)`,
  Auflösung von Rolle/Stimmgruppe/Projekt analog `EventAudienceService::visibleEventsQuery()`
  (`src/Services/EventAudienceService.php:162`, Hilfsmethode `userReferenceIds`). Vorfahrenkette
  einmal pro Request laden (kleine Bäume, rekursive CTE oder Iteration mit Cache).
- `FileFolderService` — anlegen, umbenennen, verschieben (Zyklusprüfung, Stufe Bearbeiten in Quelle
  **und** Ziel, Kontingent des Ziel-Teamordners), in Papierkorb, wiederherstellen.
- `FileUploadService` — Mehrfach-Upload; Namensbereinigung (keine `/ \`, Steuerzeichen, max 255,
  Muster `DownloadFileName`); MIME via `finfo`; Sperrliste ausführbarer Typen; gleicher Name →
  neue Version (Stufe Bearbeiten) statt Duplikat; Versionen über `FILES_MAX_VERSIONS` älteste löschen.
  Reihenfolge: erst Storage-`put`, dann DB-Zeile (Transaktion), bei DB-Fehler Datei entfernen.
- `FileQuotaService` — Belegung je Teamordner = Summe aller Versionen inkl. Papierkorb; Prüfung vor
  Upload/Verschieben/Wiederherstellen; optional Gesamtlimit.
- `FileTrashService` + Command `files:purge-trash` (`bin/purge_file_trash.php`, Muster
  `RotateMailCredentialKeyCommand` / `CliBootstrap`) — endgültig löschen nach `FILES_TRASH_DAYS`,
  inkl. Storage-Dateien.
- `FileZipService` — `ZipArchive` in Temp-Datei, nur Dateien mit Leserecht, Größenlimit, Stream
  als Download, Temp löschen. (ext-zip im Container prüfen.)
- `FileResponseFactory` — Download/Inline mit Range-Unterstützung (Logik aus
  `AttachmentResponseFactory::parseRangeHeader` wiederverwenden/auslagern), `DownloadFileName`,
  Inline nur für sichere Typen per `AttachmentPreview`, `X-Content-Type-Options: nosniff`.
- `src/Queries/FileSearchQuery` — `LIKE` auf Datei-/Ordnernamen, gefiltert auf sichtbare Teamordner.

**Policy**: `src/Policies/FilePolicy` (aus `$_SESSION`, Muster `SponsoringPolicy`) +
`FileAccessService` für ordnerbezogene Checks. Jede Aktion prüft Stufe serverseitig.

**Controller + Routen** (`src/Routes.php`, Gruppe in `if ($settings['modules']['files'])`):
- `FileBrowserController`: `GET /files` (Teamordner, Favoriten, Kontingentbalken),
  `GET /files/folders/{id}` (Breadcrumb, Unterordner, Dateien), `GET /files/search`.
- `FileController`: `POST /files/folders/{id}/upload` (JSON, mehrere Dateien),
  `GET /files/{id}/download|preview`, `GET /files/versions/{id}/download`, `POST /files/{id}/rename|move|delete`,
  `POST /files/{id}/versions/{versionId}/restore`.
- `FileFolderController`: anlegen/umbenennen/verschieben/löschen, `GET /files/folders/{id}/zip`.
- `FileShareController`: Freigaben lesen/setzen (Stufe Verwalten).
- `FileTrashController`: `GET /files/trash`, wiederherstellen, endgültig löschen (Verwalten).
- `FileFavoriteController`: an/abheften (JSON).
- Admin: `POST /files/roots` + Kontingent — Gate `requiresFilesManagement` in
  `src/Middleware/RoleMiddleware.php` `GATES`.

**Rechte-Einbindung**: `Role::PERMISSIONS`, `RoleController::MODULE_GATED_PERMISSIONS`
(`can_manage_files` → `files`), Labels in `templates/roles/index.twig` (4 Stellen),
`SessionAuthService` läuft automatisch über `PERMISSIONS`.

**Navigation**: `src/Navigation/NavigationBuilder.php` Eintrag „Dateien", Icon `bi-folder2-open`,
sichtbar bei `module('files')`.

**UI**:
- `templates/files/{index,folder,search,trash}.twig`, Partials für Share-/Rename-/Move-/Version-Modals;
  Vorschau über `templates/partials/attachment_preview_modal.twig` + `public/js/attachment-preview.js`.
- `public/js/file-manager.js` (vanilla IIFE): Dropzone + Dateiauswahl, XHR-Upload je Datei mit
  Fortschritt, Konflikthinweis „neue Version", Favoriten-Toggle, Ordnerauswahl für Verschieben.
  Limits aus `<meta name="upload-limits">` (`upload-helper.js`).
- CSS in `public/css/style.css` (Dropzone, Kontingentbalken via `--progress-value`); kein Inline-CSS/JS.
- Mobil: Tabelle → Kartenliste, Aktionen im Dropdown.

**Logging**: Monolog-Events `files.uploaded`, `files.version_created`, `files.deleted`, `files.restored`,
`files.purged`, `files.share_changed`, `files.quota_exceeded`, `files.access_denied`.

**Hinweis Betrieb**: `var/files` muss im Produktivcontainer persistent sein (Volume) — README-Abschnitt.

**Backup mit Dateien** (`src/Services/BackupService.php`, heute `.sql(.gz)` + `.json`):
Gespeicherte Dateien sind unveränderlich (jede Version eigener `storage_path`, nie überschrieben) →
**inkrementelle Sicherung über einen Dateipool** statt Vollkopie je Backup:
- Neue Naht `FileBackupInterface` (`src/Services/Files/FileBackup.php`), in `BackupService` optional
  injiziert (Modul aus → No-op; bestehende Tests bleiben).
- `create()`: nach dem Dump Manifest `<id>.files.json` schreiben — alle `file_versions`
  (storage_driver, storage_path, size, sha256). Fehlende Einträge in Pool `backupDir/files/<storage_path>`
  kopieren (vorhandene mit gleicher sha256 überspringen). Metadaten bekommen `files_manifest`,
  `files_count`, `files_bytes`. Scheitert die Kopie → Backup gilt als fehlgeschlagen, Dump + Manifest
  wieder entfernen (wie heute beim Dump-Fehler).
- `restore()`: vor dem DB-Restore Manifest + Pool prüfen (jede Datei vorhanden, sha256 passt), sonst
  Abbruch **vor** dem Einspielen. Danach DB einspielen, fehlende/abweichende Dateien aus dem Pool nach
  `var/files` zurückkopieren. Dateien in `var/files`, die der eingespielte Stand nicht kennt, werden
  **nicht** gelöscht, sondern vom Purge-Command als verwaist erkannt (`files:purge-trash --orphans`).
- `delete()` / Rotation: Manifest entfernen, danach Pool-Dateien löschen, die in keinem verbliebenen
  Manifest mehr stehen (Pool-GC).
- `getFile()`/Download: Datei-Download bleibt SQL; zusätzlicher Download „Dateien" baut ein `.tar.gz`
  aus Manifest + Pool-Dateien in eine Temp-Datei (PharData) und liefert es aus.
- `list()`/Backup-UI (`templates/backups/index.twig`): Spalte Dateien (Anzahl/Größe); Backups ohne
  Manifest (Altbestand) bleiben gültig und werden als „nur Datenbank" gekennzeichnet.
- Speicherplatz: Pool wächst mit Dateibestand, nicht mit Backup-Anzahl — Hinweis im README.

## Umsetzungsreihenfolge (TDD, jeder Schritt Test zuerst rot)

1. Worktree + Spec.
2. Migrationen + Modelle (`FileFolder`, `StoredFile`* , `FileVersion`, `FileFolderShare`, `FileFavorite`),
   Rollenrecht, Modul-Flag. (*`File` kollidiert leicht mit PHP-Begriffen → `StoredFile`.)
3. Storage-Naht + `LocalFileStorage` (Unit-Tests gegen Temp-Verzeichnis).
4. `FileAccessService` (Vererbung, alle 5 Zieltypen, Max-Stufe, `can_manage_files`).
5. Ordner-/Upload-/Versions-/Kontingent-/Papierkorb-Services.
6. Controller, Routen, Gates, Navigation, Rollen-UI.
7. Templates, JS, CSS.
8. ZIP, Suche, Favoriten.
9. Purge-Command (inkl. `--orphans`).
9a. Backup mit Dateien (Pool, Manifest, Restore-Prüfung, Pool-GC, Datei-Download, Backup-UI).
10. Seed (`/dev-seed-completeness`): Teamordner „Noten" (alle: Lesen; Notenwart-Rolle: Bearbeiten),
    „Vorstand" (Rolle: Verwalten), „Stimmproben Sopran" (Stimmgruppe: Lesen), Projektordner
    (Projektmitglieder: Hochladen), Unterordner, Dateien aus `DevSeedAttachmentFixtures` mit 2–3
    Versionen, Papierkorbeintrag, Favoriten, Kontingent. `resetSeedData()` + Storage-Verzeichnis leeren,
    Zähler im Bericht.
11. Hilfe (`/create-help-topic`): `help/files/docs/files.md` + Unterseiten (Freigaben, Papierkorb &
    Versionen), Rechte nur per Label „Dateiverwaltung verwalten".
12. Optional E2E-Szenario (`/e2e-scenario`): Upload → Freigabe an Stimmgruppe → Sichtbarkeit.

## Tests (Auswahl)

- `tests/Unit/Services/FileStorage/LocalFileStorageTest` — put/read/range/delete, Traversal-Abwehr.
- `tests/Feature/FileAccessFeatureTest` — je Zieltyp, Vererbung, Max-Stufe, kein Zugriff ohne Freigabe,
  Papierkorb unsichtbar, Modul aus → 404.
- `FileUploadFeatureTest` — Mehrfach-Upload, MIME-Sperre, Namensbereinigung, gleicher Name → Version,
  Versionslimit, Stufe Lesen darf nicht hochladen. Muster `FinanceReceiptUploadFeatureTest.php:171`
  (`UploadedFile` + `withUploadedFiles`). **In `UploadLimitFeatureTest` Liste ergänzen.**
- `FileQuotaFeatureTest`, `FileMoveFeatureTest` (Zyklus, Rechte in Ziel), `FileTrashFeatureTest`
  (+ Purge-Command), `FileShareFeatureTest`, `FileZipFeatureTest`, `FileSearchFeatureTest`
  (nur Sichtbares), `FileDownloadRangeFeatureTest`, `FileFavoriteFeatureTest`.
- `FileBackupFeatureTest` — Backup legt Manifest + Pool an; zweites Backup kopiert nichts doppelt;
  Restore stellt gelöschte Datei wieder her; fehlende/verfälschte Pool-Datei bricht **vor** DB-Restore ab;
  Löschen eines Backups entfernt nur nicht mehr referenzierte Pool-Dateien; Altbackup ohne Manifest
  lässt sich weiter wiederherstellen; Modul aus → Verhalten wie bisher.
- Anpassen: bestehende `Backup*`-Tests, `DependenciesContainerWiringTest`, Rollen-/Navigation-Tests, `DevSeed*`-Tests.
- Schärfeprobe: Vererbung abschalten, Stufenprüfung im Upload entfernen, Kontingentprüfung entfernen,
  Such-Sichtbarkeitsfilter entfernen, sha256-Prüfung im Backup-Restore entfernen, Pool-GC löscht
  alles → jeweils rot sehen.

## Verifikation

```bash
ddev exec --dir /var/www/html/.worktrees/file-management ./vendor/bin/phinx migrate
ddev exec --dir … php vendor/bin/phpunit --filter "File"
ddev exec --dir … composer test:parallel
ddev exec --dir … composer phpcs && composer twigcs
ddev exec --dir … env APP_ENV=development ALLOW_DEV_SEED=1 php bin/dev_seed.php --mode=reset-and-seed
```
Seed-Bericht: neue Zähler > 0. Browser-Handlauf nur auf Wunsch.

## Bewusst nicht enthalten (Folgeschritte)

- WebDAV-Zugriff auf Teamordner (`WebdavTreeService`), WebDAV-Storage-Treiber.
- Öffentliche Links für Externe.
- Verknüpfung mit Songs/Events, Benachrichtigungen, Aktivitätsprotokoll in der UI.
- Deny-Regeln / Vererbung unterbrechen.
