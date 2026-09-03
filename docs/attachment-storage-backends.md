# Anhänge wahlweise in der Datenbank oder in Nextcloud (WebDAV)

> Vorhaben, noch nicht umgesetzt. Stand: 2026-09-03.

## Ausgangspunkt

Heute liegt jeder Anhang als `longblob` in `attachments.file_content`
([db/migrations/20260314130000_initial.php](../db/migrations/20260314130000_initial.php), Zeile 386).
Das hat drei Folgen: die Datenbank wächst mit jedem Vertrags-PDF und jeder 30-MB-Audiodatei, jedes
Backup (`BackupService`) schleppt diese Bytes mit, und die Auslieferung zieht bei jeder
Bereichsanfrage die **ganze** Datei durch den Speicher — auch wenn der Player nur ein paar Kilobyte
ab Sekunde 90 will ([src/Services/AttachmentResponseFactory.php](../src/Services/AttachmentResponseFactory.php),
Zeile 41).

Ziel: Der Ablageort wird konfigurierbar (`database` oder `webdav`/Nextcloud), der Bestand lässt
sich in **beide** Richtungen umziehen, und der Umzug darf stückweise laufen, ohne dass in der
Zwischenzeit ein Anhang unerreichbar wird.

Entschieden:

- Konfiguration über `.env` + `src/Settings.php` — wie `db`, `backup`, `MAIL_CREDENTIAL_KEY`.
  Das App-Passwort landet damit nicht in der Datenbank.
- Treiber **pro Zeile** (`attachments.storage_driver`), nicht global. Mischbestand ist erlaubt.
- Tests gegen ein Storage-Double; zusätzlich ein schlanker WebDAV-Sidecar für den Handlauf.
- Bereichsanfragen werden an WebDAV durchgereicht, statt die Datei ganz zu laden.

## Bestandsaufnahme

- Auslieferung läuft bereits über **eine** Stelle:
  [src/Controllers/AttachmentController.php](../src/Controllers/AttachmentController.php) mit
  `GET /attachments/{id}/preview|download` ([src/Routes.php](../src/Routes.php), Zeile 191).
  `authorize()` liest nur Metadaten, `loadContent()` holt danach den BLOB — diese Trennung bleibt.
- Rechte: [src/Services/AttachmentAccessRegistry.php](../src/Services/AttachmentAccessRegistry.php)
  — **unverändert**. Es wird weiterhin durch die Anwendung ausgeliefert, nie per Umleitung auf
  Nextcloud; sonst läge die Rechtefrage bei Nextcloud statt bei uns. CSP und
  `SecurityHeadersMiddleware` bleiben gleich.
- Schreiben ist **nicht** vereinheitlicht: drei Controller schreiben am Service vorbei direkt
  `Attachment::create()` — [src/Controllers/FinanceController.php](../src/Controllers/FinanceController.php)
  (Zeile 346), [src/Controllers/TaskController.php](../src/Controllers/TaskController.php)
  (Zeile 698), [src/Controllers/SongLibraryController.php](../src/Controllers/SongLibraryController.php)
  (Zeile 425). Finance vergibt dabei zusätzlich **keinen** Zufallspräfix im `filename` und kürzt
  nicht auf 255 Zeichen.
- Löschen ist teils Model-Delete (`$attachment->delete()`), teils Bulk-Delete über den Query
  Builder ([src/Services/EntityAttachmentService.php](../src/Services/EntityAttachmentService.php),
  Zeilen 193 und 208) — letzterer feuert **keine** Model-Events. Model-Events taugen deshalb nicht
  zum Aufräumen der Gegenstelle; das muss explizit im Service passieren.
- Kein HTTP-Client im Projekt (kein Guzzle, kein Flysystem, kein sabre/dav). cURL 8.14.1 ist im
  Container vorhanden — der WebDAV-Client wird handgerollt, wie schon die IMAP-Prüfung in
  [src/Controllers/ProfileController.php](../src/Controllers/ProfileController.php) (Zeile 540).
  **Keine neue Composer-Abhängigkeit.**

## Umsetzung

### 1. Schema (Phinx)

Neue Migration `db/migrations/2026XXXXXXXXXX_add_attachment_storage_driver.php`:

`up()`

- `storage_driver` `varchar(20) NOT NULL DEFAULT 'database'`
- `storage_path` `varchar(1024) NULL` (Pfad relativ zur konfigurierten WebDAV-Sammlung)
- `file_content` → `longblob NULL` (Aufweitung, ungefährlich)
- Index auf `storage_driver` (der Umzugsbefehl filtert danach)
- Kette mit `->update()` abschließen (Vorgabe aus `instructions/database.md`, statisch geprüft von
  `tests/Unit/Migrations/MigrationChainCompletionTest`)

`down()` enthält `MODIFY … NOT NULL` und `DROP COLUMN`, also **Wächter davor**, Muster
[db/migrations/20260820120000_require_finance_account_on_finances.php](../db/migrations/20260820120000_require_finance_account_on_finances.php):

```php
$pending = (int) ($this->fetchRow(
    "SELECT COUNT(*) AS pending FROM attachments
     WHERE storage_driver <> 'database' OR file_content IS NULL"
)['pending'] ?? 0);
if ($pending > 0) {
    throw new RuntimeException(sprintf(
        'Es liegen %d Anhang/Anhänge außerhalb der Datenbank. '
        . 'Vor dem Rückbau "ddev php bin/migrate_attachments.php --to=database" ausführen.',
        $pending
    ));
}
```

### 2. Storage-Naht (`src/Services/Storage/`)

```php
interface AttachmentStorage
{
    public function name(): string;                                  // 'database' | 'webdav'
    public function put(Attachment $attachment, string $contents): ?string;  // liefert storage_path
    public function read(Attachment $attachment): string;
    public function readRange(Attachment $attachment, int $start, int $length): string;
    public function size(Attachment $attachment): int;
    public function delete(Attachment $attachment): void;
}
```

- `DatabaseAttachmentStorage` — heutiges Verhalten. `size()` über `SELECT LENGTH(file_content)`
  (kein BLOB-Transfer), `readRange()` über `SUBSTRING()` in SQL statt `substr()` in PHP; damit
  gewinnt auch der Datenbank-Treiber gegenüber heute.
- `WebdavAttachmentStorage` — spricht über ein `WebdavClient`-Interface.
  Pfad: `{base_path}/{entity_type}/{filename}`. `filename` trägt bereits 32 Hex-Zeichen Zufall
  ([src/Services/EntityAttachmentService.php](../src/Services/EntityAttachmentService.php),
  Zeile 127) und ist damit kollisionsfrei — **Voraussetzung**, dass Finance auf den Service
  umgestellt wird (Schritt 4).
  Reihenfolge beim Hochladen: erst `PUT`, dann die Datenbankzeile. Bricht der `PUT` ab, entsteht
  keine Zeile ohne Datei; bricht es danach ab, bleibt eine verwaiste Datei liegen — die stört
  nicht und wird beim nächsten Lauf mit demselben Pfad überschrieben.
  `MKCOL` für `{base_path}/{entity_type}` bei Bedarf, `405 Method Not Allowed` gilt als Erfolg.
- `AttachmentStorageRegistry` — `for(Attachment $a)` wählt nach `$a->storage_driver`, `default()`
  liefert den Treiber für neue Uploads aus der Konfiguration. Unbekannter Treiber wirft, statt
  still auf `database` zurückzufallen.
- `WebdavClient` (Interface) und `CurlWebdavClient`. Basic-Auth mit App-Passwort,
  `CURLOPT_FOLLOWLOCATION=false`, `CURLOPT_PROTOCOLS_STR='http,https'`, Zeitlimit aus der
  Konfiguration. `https` ist Pflicht, außer `AppEnvironment::isDebugEnabled()` (der Sidecar läuft
  über http). Das App-Passwort kommt in **keine** Protokollzeile.

### 3. Konfiguration

`.env.example` bekommt einen Abschnitt im Stil der bestehenden Blöcke:

```
ATTACHMENT_DRIVER=database
ATTACHMENT_WEBDAV_BASE_URL=
ATTACHMENT_WEBDAV_USERNAME=
ATTACHMENT_WEBDAV_PASSWORD=
ATTACHMENT_WEBDAV_BASE_PATH=ChorManager/attachments
ATTACHMENT_WEBDAV_TIMEOUT=15
```

Der Kommentar nennt die Nextcloud-Form der Basis-URL
(`https://cloud.example.org/remote.php/dav/files/<benutzer>`) und weist darauf hin, dass ein
**App-Passwort** zu verwenden ist, nicht das Anmeldepasswort. Bei `ATTACHMENT_DRIVER=webdav` ohne
URL oder Zugangsdaten bricht der Start mit klarer Meldung ab — Muster
`AppUrlResolver::assertConfiguredForProduction()`.

[src/Settings.php](../src/Settings.php) bekommt einen `'attachments'`-Block über `EnvHelper::read()`
und `readBool()`, [src/Dependencies.php](../src/Dependencies.php) die Bindungen. Vorbild für
„Interface auf Implementierung, Konfiguration aus `settings`" ist der Backup-Block in
`Dependencies.php` (Zeilen 360 bis 394).

### 4. Schreib- und Löschpfade zusammenführen

Nötig, nicht optional: ohne das entstehen bei `webdav` verwaiste Dateien und kollidierende Pfade.

- Die drei Inline-Duplikate in `FinanceController`, `TaskController` und `SongLibraryController`
  rufen `EntityAttachmentService::storeUploads()`. Finance gewinnt dadurch Zufallspräfix und
  Namenskürzung; die Journalsperre und die modulspezifischen Fehlermeldungen bleiben, wo sie sind.
- `EntityAttachmentService::storeUploads()` schreibt über `AttachmentStorageRegistry::default()`
  und setzt `storage_driver` und `storage_path`; `file_content` bleibt bei `webdav` leer.
- `deleteForEntity()` und `deleteAllForEntities()` lesen erst die Metadaten (`METADATA_COLUMNS`
  zuzüglich `storage_driver` und `storage_path`), löschen die Gegenstelle, dann die Zeilen. Der
  bisherige Bulk-Delete wird dadurch zu „laden, entfernen, löschen" in Blöcken.
- Controller-Löschungen (`FinanceController::deleteAttachment`, `TaskController`,
  `SongLibraryController`, `SponsorController`, `SponsorshipController`) gehen über den Service.

### 5. Auslieferung mit Bereichsanfrage

`AttachmentResponseFactory` bekommt den `AttachmentStorageRegistry` statt
`$attachment->file_content`:

1. Größe über `size()` (aus `file_size`, sonst vom Treiber).
2. `parseRangeHeader()` gegen diese Größe — die vorhandene Methode bleibt unverändert.
3. Nur den Ausschnitt über `readRange()` holen; ohne `Range` `read()`.

`AttachmentController::loadContent()` entfällt: die Metadaten aus `authorize()` reichen, den Inhalt
besorgt die Factory. Damit fällt auch die zweite Abfrage weg. Die 404-Antwort für „zwischenzeitlich
gelöscht" wandert in den Fehlerfall des Treibers.

### 6. Umzug in beide Richtungen

`src/Commands/MigrateAttachmentStorageCommand.php` (`attachments:migrate`) und
`bin/migrate_attachments.php` — Aufbau exakt wie
[src/Commands/RotateMailCredentialKeyCommand.php](../src/Commands/RotateMailCredentialKeyCommand.php)
und [bin/rotate_mail_key.php](../bin/rotate_mail_key.php): `CliBootstrap`, `chunkById`,
`--dry-run`, strukturierte Protokollzeilen, idempotent.

Optionen: `--to=database|webdav` (Pflicht), `--dry-run`, `--limit=N`, `--batch=100`.

Je Zeile: Quelle lesen, Ziel schreiben, **sha256 und Länge vergleichen**, Zeile umschreiben, Quelle
entfernen. Zeilen, die schon auf dem Ziel liegen, werden gezählt und übersprungen. Ein Abbruch
mitten im Lauf ist folgenlos: der nächste Lauf setzt fort, weil jede Zeile ihren eigenen Treiber
trägt.

Ausgabe am Ende: verschoben, übersprungen, fehlgeschlagen — wie im Rotationsbefehl.

### 7. Seed

- `DevSeedService` schreibt über die Naht. Schritt 4 erledigt das größtenteils; die Seed-Methoden
  verwenden `Attachment::create()` und werden auf Service beziehungsweise Registry umgestellt,
  damit ein Dev mit `ATTACHMENT_DRIVER=webdav` echte Dateien in Nextcloud bekommt.
- `resetSeedData()` ([src/Services/DevSeedService.php](../src/Services/DevSeedService.php),
  Zeile 266) muss vor dem `TRUNCATE` die Gegenstelle leeren, sonst sammeln sich verwaiste Dateien.
- Seed-Bericht in `run()`: `attachments` ergänzt um den verwendeten Treiber.

### 8. Dev-Gegenstelle

`.ddev/docker-compose.webdav.yaml` mit `httpd:2.4-alpine` und einer eigenen `dav.conf` unter
`.ddev/webdav/` — dieselbe Machart wie `.ddev/nginx_full/` und `.ddev/webmail-plugins/`, ohne
fremdes Fertigimage. Nextcloud selbst wäre für einen reinen WebDAV-Test zu schwer.
README-Abschnitt analog zum Webmail-Abschnitt: Sidecar starten, `.env` umstellen,
`attachments:migrate --to=webdav` laufen lassen.

## Betroffene Dateien

| Datei | Änderung |
| --- | --- |
| `db/migrations/2026XXXXXXXXXX_add_attachment_storage_driver.php` | neu, mit Wächter im `down()` |
| `src/Services/Storage/*` | neu: Interface, zwei Treiber, Registry, WebDAV-Client |
| `src/Models/Attachment.php` | `storage_driver` und `storage_path` in `$fillable` |
| `src/Services/EntityAttachmentService.php` | Schreiben und Löschen über die Registry |
| `src/Services/AttachmentResponseFactory.php` | Bereichsanfrage über `readRange()` |
| `src/Controllers/AttachmentController.php` | `loadContent()` entfällt |
| `src/Controllers/{Finance,Task,SongLibrary,Sponsor,Sponsorship}Controller.php` | Duplikate auf den Service |
| `src/Settings.php`, `src/Dependencies.php`, `.env.example` | Konfiguration und Bindungen |
| `src/Commands/MigrateAttachmentStorageCommand.php`, `bin/migrate_attachments.php` | neu |
| `src/Services/DevSeedService.php` | Seed über die Naht, Reset räumt die Gegenstelle |
| `.ddev/docker-compose.webdav.yaml`, `.ddev/webdav/dav.conf`, `README.md` | neu |

## Tests

Testgetrieben, jeder Test zuerst rot. Bestand: `Bootstrap::setupTestDatabase()` und eine
Transaktion je Test, kein Schema von Hand (`NoHandBuiltSchemaTest` erzwingt das). Dateinamen
englisch, Szenariotexte deutsch.

1. `tests/Unit/Services/Storage/DatabaseAttachmentStorageTest.php` — put, read, readRange, size,
   delete.
2. `tests/Unit/Services/Storage/WebdavAttachmentStorageTest.php` — gegen ein
   `WebdavClient`-Double: Methode, URL, Basic-Auth, `Range`-Kopfzeile, `MKCOL`-Idempotenz
   (405 gilt als Erfolg), Fehlerabbildung. Kein Netz.
3. `tests/Feature/AttachmentStorageDriverFeatureTest.php` — Upload bei Treiber `webdav` setzt
   `storage_driver` und `storage_path` und lässt `file_content` leer; Auslieferung kommt vom
   Double; `Range` erzeugt 206 mit genau dem Ausschnitt; unbekannter Treiber wirft.
4. `tests/Feature/AttachmentStorageMigrationCommandTest.php` — beide Richtungen, Mischbestand,
   `--dry-run` schreibt nichts, zweiter Lauf verschiebt nichts mehr, verfälschte Prüfsumme lässt
   die Quelle stehen.
5. `tests/Feature/AttachmentDeleteRemovesRemoteFeatureTest.php` — Entität löschen entfernt auch die
   Gegenstelle, auch auf dem Bulk-Pfad `deleteAllForEntities`.
6. Wächter-Test für das `down()` der Migration.
7. Anzupassen: `AttachmentResponseFactoryTest`, `DependenciesContainerWiringTest`,
   `AttachmentAccessFeatureTest` (prüft, dass `file_content` nicht in der Rechte-Abfrage steht),
   `DownloadFeatureTest`, `AttachmentLongFilenameFeatureTest`, `FinanceFeatureTest`.

## Verifikation

```bash
ddev exec ./vendor/bin/phinx migrate
ddev php vendor/bin/phpunit --filter 'Attachment|Storage|Webdav|Migration'
ddev composer phpcs
```

Danach der Handlauf gegen echtes WebDAV:

```bash
ddev restart                                   # mit docker-compose.webdav.yaml
# .env: ATTACHMENT_DRIVER=webdav plus Zugangsdaten des Sidecars
ddev php bin/migrate_attachments.php --to=webdav --dry-run
ddev php bin/migrate_attachments.php --to=webdav
ddev php bin/migrate_attachments.php --to=database          # Rückweg
ddev exec APP_ENV=development ALLOW_DEV_SEED=1 php bin/dev_seed.php --mode=reset-and-seed
```

Im Browser: Vorschau und Download an einer Kassabuch-Buchung, einem Lied-PDF und einer MP3-Datei
auf `/songs/downloads` — dort belegt der Player, dass Bereichsanfragen tragen.

Zum Schluss einmal `ddev composer test` (voller Lauf einschließlich Zeilenende-Prüfung).

**Schärfeprobe**, damit die grüne Suite etwas belegt: je einmal gezielt sabotieren und rot sehen —
Wächter im `down()` entfernen, Prüfsummenvergleich im Umzugsbefehl entfernen, `delete()` der
Gegenstelle im Service auskommentieren, `readRange()` durch „ganze Datei" ersetzen.

## Bewusst nicht enthalten

- Keine Umleitung des Browsers auf Nextcloud — die Rechtefrage bliebe sonst nicht bei uns.
- Keine Verschlüsselung der Dateien in Nextcloud; dafür ist Nextcloud selbst zuständig.
- Kein zweiter Treiber (S3 oder Ähnliches); die Naht lässt ihn zu, gebaut wird er nicht.
- Keine Umstellung des Backups. `BackupService` sichert weiterhin die Datenbank. Nach dem Umzug
  gehören die Nextcloud-Dateien zur Sicherung dazu — das kommt als Hinweis in den
  README-Abschnitt.
