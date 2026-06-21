# Backup-Verwaltung — Design

- Status: Approved (Design)
- Datum: 2026-06-21
- Ansatz: A (`mysqldump` + Dateisystem als Quelle der Wahrheit)

## Ziel

Eigener Bereich „Backup-Verwaltung" mit zugehörigem Recht, in dem Backups der
Anwendungsdatenbank erstellt, heruntergeladen, gelöscht und wiederhergestellt
werden können. Zwei Erstellungsmodi: automatisch (Cron/CLI) und manuell
(Button). Eine Wiederherstellung beendet alle aktiven Sessions.

## Kontext / Randbedingungen

- Stack: Slim 4, PHP-DI, Eloquent (`illuminate/database`), Phinx, Twig, PHP 8.5, MySQL, DDEV.
- Alle Nutzdaten — auch hochgeladene Dateien (`Attachment.file_content` etc.) —
  liegen als BLOB in MySQL. Ein DB-Dump erfasst damit den vollständigen
  App-Zustand. Backups enthalten **nur** den DB-Dump (keine `.env`, keine
  separaten Dateien).
- Rechte: Tabelle `roles` mit booleschen `can_*`-Spalten; Flags werden beim
  Login in die Session gespiegelt; `RoleMiddleware` erzwingt sie je Route.
- Sessions liegen als PHP-Dateien (Standard-Save-Handler), **nicht** in der DB.
  Ein DB-Restore beendet sie daher nicht von allein — es braucht einen
  expliziten Invalidierungsmechanismus.
- CLI: Symfony-Console-Kommandos in `src/Commands/`, Entrypoints in `bin/`
  (Vorbild: `bin/process_mail_queue.php`).
- Konfiguration: `EnvHelper` liest Env, gebündelt im `settings`-Array in
  `src/Settings.php`.

## Getroffene Entscheidungen

- Backup-Inhalt: **nur DB-Dump** (vollständig, da Attachments in DB).
- Restore-Quelle: **nur verwaltete Backups** (kein Upload externer Dateien →
  kein Einschleusen beliebigen SQL).
- Download: **ja** (Offsite-Kopie).
- Kontingent voll: **automatische** Backups **rotieren** (ältestes Auto-Backup
  löschen, Cron läuft verlässlich weiter); **manuelle** Backups **blockieren**
  (Fehlermeldung, bewusstes Löschen nötig). Getrennte Kontingente je Typ.
- Auto-Zeitplan: **externer Cron** ruft ein CLI-Kommando, das genau ein
  Auto-Backup erstellt und rotiert. Kein Scheduler in der App.
- Metadaten: **Dateisystem ist Quelle der Wahrheit** (Sidecar-`.json`), keine
  DB-Tabelle — sonst zeigt die Backup-Liste nach einem Restore den Stand aus
  dem wiederhergestellten Backup (Paradoxon).

## Architektur / Komponenten

### 1. Berechtigung

- Neue boolesche Spalte `can_manage_backups` auf `roles` (Phinx-Migration,
  Default `false`).
- `Role::$fillable` um `can_manage_backups` erweitern.
- Session-Flag `can_manage_backups` beim Login setzen (analog übriger `can_*`
  in `SessionAuthService`).
- `RoleMiddleware`: neuer Konstruktor-Param `requiresBackupManagement`. Das
  Recht ist **eigenständig** und wird **nicht** über `can_manage_users`
  mitgewährt (Backup/Restore ist destruktiv und sensibel).
- Rollen-Formular (`RoleController` + zugehöriges Template) erhält eine
  Checkbox für das neue Recht.

### 2. Konfiguration (.env)

```
BACKUP_DIR=/var/backups/chormanager   # außerhalb Webroot, in .gitignore
BACKUP_MAX_MANUAL=5                    # 0 = unbegrenzt
BACKUP_MAX_AUTO=7                      # 0 = unbegrenzt
BACKUP_GZIP=true
```

- Werte via `EnvHelper` lesen, in `settings['backup']` bündeln (siehe
  `src/Settings.php`).
- In `.env.example` dokumentieren.
- `BACKUP_DIR` wird bei Bedarf angelegt; muss außerhalb des Webroots liegen.

### 3. BackupService (`src/Services/BackupService.php`)

Öffentliche Methoden:

- `list(): array` — `BACKUP_DIR` scannen, Sidecar-`.json` einlesen, nach
  `created_at` absteigend sortiert zurückgeben.
- `create(string $type, ?int $userId): BackupResult` —
  - `manual`: bei vollem manuellem Kontingent `BackupLimitReachedException`.
  - `auto`: bei vollem Auto-Kontingent ältestes Auto-Backup löschen (rotieren),
    dann erstellen.
  - Ablauf: `mysqldump` → optional gzip → Datei
    `backup_<type>_<UTC-Zeitstempel>_<rand>.sql.gz` + Sidecar `.json`.
- `restore(string $id): void` — Datei und sha256 prüfen → `mysql`-Import →
  **danach** `session_valid_after = now()` setzen → loggen.
- `delete(string $id): void`.
- `getFile(string $id): array` — Pfad + Metadaten für Download-Stream.

Sidecar-`.json`-Felder: `id`, `type` (`manual`|`auto`), `created_at` (UTC ISO),
`created_by` (User-ID oder `null` bei Cron), `size`, `sha256`, `app_version`,
`db_name`, `gzip` (bool).

Dump/Import über ein **`DumpRunner`-Interface**:

- Reale Implementierung führt `mysqldump` bzw. `mysql` über `proc_open` mit
  Argument-**Array** aus (kein Shell-String → kein Injection-Risiko). Das
  DB-Passwort wird über die Env-Variable `MYSQL_PWD` des Kindprozesses
  übergeben, nicht als Argument (kein Leak in der Prozessliste).
- DB-Zugangsdaten stammen aus `settings['db']`.
- Das Interface wird in Tests gemockt (kein echtes `mysqldump` nötig).

Logging (`LoggerInterface`, strukturiertes JSON, `event`-Key):
`backup.create.start|completed|failed`, `backup.restore.start|completed|failed`,
`backup.delete`, `backup.rotate`. Exceptions im `exception`-Kontext.

### 4. Session-Invalidierung bei Restore

- `AppSetting`-Key `session_valid_after` (Unix-Zeitstempel).
- Login setzt `$_SESSION['auth_epoch'] = time()`.
- `AuthMiddleware`: ist `auth_epoch` (fehlend = 0) kleiner als
  `session_valid_after`, wird die Session zerstört und auf `/login` umgeleitet.
- `restore()` schreibt `session_valid_after = time()` **nach** dem Import →
  jede bestehende Session inkl. des ausführenden Admins wird ungültig. Der
  Admin landet mit Hinweis auf der Login-Seite.
- Hinweis: Der im Backup enthaltene alte `session_valid_after`-Wert wird durch
  den frischen `now()`-Wert nach dem Import überschrieben.

### 5. CLI / Cron

- `src/Commands/CreateBackupCommand.php` — Symfony-Console-Kommando
  `backup:create` mit Option `--type=auto|manual` (Default `auto`); ruft
  `BackupService::create()`.
- `bin/create_backup.php` — Entrypoint analog `bin/process_mail_queue.php`
  (ContainerBuilder + Settings + Dependencies, Capsule booten, Kommando
  registrieren).
- Der Zeitplan liegt im externen Cron (System/DDEV). Ein Lauf = ein
  Auto-Backup + Rotation.

### 6. Controller + Routes

- `src/Controllers/BackupController.php`:
  - `index` — Liste rendern.
  - `store` — manuelles Backup erstellen (POST).
  - `restore` — Wiederherstellung (POST).
  - `delete` — Backup löschen (POST).
  - `download` — Datei streamen (GET).
- Alle mutierenden Aktionen: **POST + CSRF**. Restore und Delete mit
  Bestätigungsmodal.
- Routen-Gruppe unter `/backups`, geschützt durch
  `RoleMiddleware(requiresBackupManagement: true)` (siehe `src/Routes.php`).
- Nach erfolgreichem Restore: Redirect auf `/login` mit Hinweismeldung (die
  eigene Session ist beendet).

### 7. Templates

- `templates/backups/index.twig`: Tabelle (Typ, Datum, Ersteller, Größe,
  Aktionen: Download / Restore / Löschen), Button „Backup erstellen",
  Bestätigungs-Modals für Restore und Löschen.
- Kein Inline-JS, kein Inline-CSS; dedizierte Klassen in `public/css/style.css`
  bzw. Bootstrap-Utilities; responsiv (mobil + Desktop).
- Nav-Eintrag „Backup-Verwaltung" im Sidebar-Layout, sichtbar nur bei
  `session.can_manage_backups`.
- Twig-Standards: doppelte Anführungszeichen; Boolean-Operatoren via
  `{% set %}`-Zwischenvariablen einzeilig.

### 8. Sicherheit

- `BACKUP_DIR` außerhalb des Webroots, nicht web-erreichbar.
- `id` → Datei nur gegen die gescannte Whitelist auflösen; `id`-Format
  validieren (kein Path-Traversal).
- Download mit sicherem Dateinamen, Content-Type `application/gzip`, gestreamt.
- Restore ist destruktiv → doppelte Bestätigung + Logeintrag mit auslösender
  User-ID.
- Eigenes Recht, least privilege; CSRF auf allen mutierenden Routen.

### 9. Seed-Daten (`src/Services/DevSeedService.php`)

- Admin-Rolle erhält `can_manage_backups = true`.
- `resetSeedData()`: Dev-`BACKUP_DIR` leeren.
- Dev-Lauf erzeugt 1 Beispiel-Backup, damit die Liste nicht leer ist.
- Report-Counter `backups` in `run()` ergänzen.
- Mandatory-Seed-Checkliste (keine neue Tabelle, daher nur relevante Punkte):
  Berechtigung seeden, Reset um BACKUP_DIR-Leerung erweitern, Counter im Report,
  Seed-Methode für Beispiel-Backup, in `run()` in sicherer Reihenfolge einhängen,
  echten Dev-Seed-Lauf ausführen und Report prüfen.

## Datenfluss

1. **Manuell**: Admin → POST `/backups` → `BackupController::store` →
   `BackupService::create('manual', userId)` → Limit-Check (blockiert bei voll)
   → `mysqldump` → gzip → Datei + Sidecar → Flash + Redirect.
2. **Automatisch**: Cron → `bin/create_backup.php` → `CreateBackupCommand` →
   `BackupService::create('auto', null)` → Rotation bei voll → Datei + Sidecar.
3. **Restore**: Admin → Bestätigung → POST `/backups/{id}/restore` →
   `BackupService::restore(id)` → sha256-Check → `mysql`-Import →
   `session_valid_after = now()` → Redirect `/login`.
4. **Download**: GET `/backups/{id}/download` → `getFile` → Stream.
5. **Löschen**: POST `/backups/{id}/delete` → `delete`.

## Fehlerbehandlung

- `BackupLimitReachedException` bei vollem manuellem Kontingent → Flash-Fehler,
  kein Backup.
- `mysqldump`/`mysql` Exitcode ≠ 0 → Exception, geloggt, Flash-Fehler;
  unvollständige Dateien werden entfernt.
- sha256-Mismatch beim Restore → Abbruch vor Import, Fehlermeldung.
- Unbekannte/ungültige `id` → 404.

## Tests (TDD)

- `BackupServiceTest` (mit gemocktem `DumpRunner`, temporäres Backup-Verzeichnis):
  - manuelles Backup blockiert bei vollem manuellem Kontingent;
  - automatisches Backup rotiert das älteste Auto-Backup;
  - Sidecar-Metadaten und sha256 korrekt geschrieben;
  - `list()` parst und sortiert korrekt;
  - `delete()` entfernt Datei und Sidecar;
  - `restore()` prüft sha256 und setzt `session_valid_after`.
- Middleware-Test: `/backups` ohne `can_manage_backups` → 403; mit Recht → ok.
- `AuthMiddleware`-Test: veraltete `auth_epoch` → Session invalidiert,
  Redirect `/login`.

## Out of Scope (YAGNI)

- Upload externer Backup-Dateien zum Wiederherstellen.
- Backup von Dateien außerhalb der DB (.env, Filesystem).
- App-interner Scheduler / Intervall-Logik (externer Cron steuert die Frequenz).
- Verschlüsselung der Backup-Dateien, Offsite-Upload (S3 o.ä.).
