# Speicherplatz-Übersicht

Stand: 2026-10-07 · Status: Entwurf, abgestimmt im Brainstorming

## Ziel

Eine Seite `/storage` zeigt, wie viel Speicherplatz die Installation belegt, aufgeteilt
nach Bereichen: Dateiablage (aktuelle und ältere Versionen, Papierkorb, Teamordner),
Datenbank (darin die Anhänge nach Bereich), Backups und Sonstiges unter `var/`. Eine
Kachel auf dem Dashboard zeigt die Gesamtgröße mit einer Mini-Übersicht der vier
Bereiche.

Die Übersicht ist zugleich die Grundlage für spätere Aufräum-Funktionen: Jede Kategorie
trägt einen festen Schlüssel, an den sich eine Aufräum-Aktion hängen kann.

Erfolg heißt: Wer das Recht „Speicherplatz-Verwaltung“ hat, sieht auf einen Blick, wo der
Platz liegt, etwa dass ältere Dateiversionen 2 GB belegen oder die Finanz-Anhänge 300 MB,
und jede Zahl auf der Seite ist live und ohne Doppelzählung gerechnet.

## Entscheidungen

| Frage | Entscheidung |
|---|---|
| Wer sieht es | Neues Recht `can_manage_storage` („Speicherplatz-Verwaltung“). Die Migration setzt es für alle Rollen mit dem höchsten vorhandenen `hierarchy_level` (im Normalfall 100), damit nie niemand es hat. |
| Wann wird gerechnet | Die Seite `/storage` rechnet bei jedem Aufruf live. Nur die Dashboard-Kachel liest eine bis zu 1 Stunde alte Zusammenfassung. |
| Aufbau | Ein Provider pro Bereich hinter einem gemeinsamen Interface (Ansatz A). |
| Doppelzählung | Anhänge sind Unterknoten der Datenbank, nicht eigener Wurzelknoten. Ein Speicherpfad der Dateiablage zählt genau einmal. |
| Aufräumen | Nicht in diesem Schritt. Nur die festen Schlüssel als Andockpunkt. |

## Nicht im Umfang

- Aufräum- oder Lösch-Aktionen
- Verlauf über die Zeit (Wachstum pro Monat), Cron-Messung
- Warnschwellen oder Benachrichtigungen bei vollem Speicher
- Hilfethema (erst auf ausdrückliche Anforderung, siehe AGENT.md)

## Architektur

```
StorageController::index ──► StorageUsageService::collect()
DashboardController       ──► StorageUsageService::summary()   (Cache 1 h)
                                   │
         ┌─────────────────┬───────┴─────────┬──────────────────────┐
FileStorageUsageProvider  DatabaseUsageProvider  BackupUsageProvider  VarDirectoryUsageProvider
 (nur bei FEATURE_FILES)
```

Namespace `App\Services\Storage`.

### `StorageUsageNode` (Wertobjekt, readonly)

| Feld | Typ | Bedeutung |
|---|---|---|
| `key` | string | Fester Schlüssel, z. B. `files.versions.old`. Stabil, Andockpunkt für Aufräumen. |
| `label` | string | Deutsches Label für die Anzeige |
| `bytes` | int | Belegter Platz. Bei einem Knoten mit Kindern die Summe der Kinder, außer wo unten anders festgelegt. |
| `count` | ?int | Anzahl der Elemente (Dateien, Anhänge, Zeilen, Backups), wo sinnvoll |
| `children` | list<StorageUsageNode> | Unterknoten |
| `error` | ?string | Gesetzt, wenn der Wert nicht ermittelbar war. `bytes` ist dann 0. |

### `StorageUsageProvider` (Interface)

```php
public function key(): string;            // Schlüssel des Wurzelknotens, z. B. "files"
public function label(): string;          // deutsches Label des Wurzelknotens
public function usage(): StorageUsageNode; // ein Wurzelknoten
```

Ein Provider darf werfen. `StorageUsageService` fängt die Ausnahme an einer Stelle,
protokolliert `storage.usage_failed` (mit `exception` und `provider` im Kontext) und setzt
für diesen Bereich einen Wurzelknoten mit `error` ein.

### `StorageUsageService`

- `collect(): StorageUsageReport`: ruft alle Provider auf, Reihenfolge Dateiablage,
  Datenbank, Backups, Sonstiges. Der Report enthält die Wurzelknoten, die Gesamtsumme
  (Summe der Wurzelknoten) und den Zeitpunkt. Ist kein Bereich fehlgeschlagen, schreibt sie
  anschließend die Zusammenfassung in
  den Cache.
- `summary(): StorageUsageSummary`: Gesamt, die vier Wurzelsummen mit Schlüssel und Label,
  Fehlerflag je Bereich, Zeitpunkt. Liest `var/cache/storage-usage-summary.json`. Ist die
  Datei jünger als 3600 Sekunden und lesbar, wird sie verwendet. Sonst wird `collect()`
  aufgerufen (das den Cache neu schreibt). Eine kaputte oder unlesbare Datei gilt als
  fehlend. Schlägt das Schreiben fehl, wird `storage.summary_cache_write_failed`
  protokolliert, das Ergebnis aber trotzdem zurückgegeben.

Die Uhr wird als Abhängigkeit hineingereicht, damit Tests das Alter des Cache steuern
können. Der Cache-Pfad kommt aus den Settings.

## Rechenregeln

### Dateiablage (`files`)

Nur wenn `FEATURE_FILES` aktiv ist. Sonst fehlt der Knoten ganz.

Grundmenge: alle verschiedenen `storage_path` aus `file_versions`, jeweils mit seiner
Größe, genau wie in `FileQuotaService::sumForFolders`. Jeder Pfad landet in der **ersten**
zutreffenden Kategorie:

1. `files.current` „Aktuelle Versionen“: Der Pfad ist `current_version_id` einer Datei,
   die nicht im Papierkorb liegt.
2. `files.versions.old` „Ältere Versionen“: Der Pfad gehört zu einer nicht-aktuellen
   Version einer Datei, die nicht im Papierkorb liegt.
3. `files.trash` „Papierkorb“: alle übrigen Pfade.

„Im Papierkorb“ heißt: `files.deleted_at` ist gesetzt, oder der Ordner der Datei oder
einer seiner Vorfahren hat `deleted_at`. Die Ermittlung der gelöschten Teilbäume nutzt,
was `FileTrashService`/`FileAccessService` dafür schon bereitstellen. Gibt es dort nichts
Passendes, kommt eine Methode dorthin, nicht in den Provider.

Invariante (durch Test gesichert): `files.current + files.versions.old + files.trash`
ergibt `FileQuotaService::totalUsedBytes()`.

Zusätzlich `files.team_folders` „Teamordner“: ein Kind pro Teamordner (Ordner ohne
`parent_id`, auch gelöschte), Schlüssel `files.team_folders.<id>`, `bytes` über
`FileQuotaService::usedBytesInSubtree()`, dazu das Kontingent (`quota_bytes`) als
Zusatzwert. Dieser Knoten ist eine **Aufschlüsselung** derselben Bytes und zählt nicht in
die Summe des Wurzelknotens `files`. Die Anzeige stellt ihn als eigene Tabelle dar.

### Datenbank (`database`)

- Gesamt: `SUM(data_length + index_length)` aus `information_schema.TABLES` für das
  aktuelle Schema (`DATABASE()`).
- `database.attachments` „Anhänge“: Größe der Tabelle `attachments` laut
  `information_schema`. Kinder `database.attachments.<entity_type>` mit
  `SUM(COALESCE(file_size, LENGTH(file_content)))` und `COUNT(*)`, gruppiert nach
  `entity_type` - die gespeicherte Größe, damit nicht jede Messung alle BLOBs von der
  Platte liest; nur Altdaten ohne Größe werden gemessen. Labels:
  `event` Termine, `finance` Finanzen, `song` Lieder, `sponsor` Sponsoren, `sponsorship`
  Sponsoring, `task` Aufgaben, `newsletter` Newsletter. Unbekannte Typen erscheinen mit
  ihrem Schlüssel als Label. Die Kinder sind eine Aufschlüsselung der Nutzdaten. Die
  Differenz zur Tabellengröße (Index, Verwaltungsdaten) steht als Kind
  `database.attachments.overhead` „Verwaltung und Index“, damit die Summe stimmt.
- `database.mail_queue` „Mail-Warteschlange“: Tabellengröße, `count` = Zeilenzahl.
- `database.notifications` „Benachrichtigungen“: Tabellen `user_notifications` und
  `notification_dispatch_log` als Kinder, je Tabellengröße und Zeilenzahl.
- `database.tables.<table>`: die 10 größten übrigen Tabellen, Label = Tabellenname.
- `database.other` „Übrige Tabellen“: der Rest.

Zeilenzahlen kommen aus `COUNT(*)`, nicht aus `information_schema.TABLE_ROWS`, weil das
bei InnoDB nur eine Schätzung ist. Fehlt eine der genannten Tabellen, fehlt ihr Knoten.

### Backups (`backups`)

Verzeichnis `settings.backup.dir`.

- `backups.dumps.manual` „Datenbank-Backups (manuell)“ und `backups.dumps.auto`
  „Datenbank-Backups (automatisch)“: je Anzahl und Summe der Dump-Dateien plus ihrer
  Metadaten (`<id>.json`, `<id>.files.json`). Der Typ kommt aus dem Dateinamen
  (`backup_manual_…` bzw. `backup_auto_…`, siehe `BackupService::ID_PATTERN`), `count` ist
  die Zahl verschiedener Kennungen. `BackupService` selbst wird nicht verwendet, weil sein
  Konstruktor das Verzeichnis anlegt und einen Dump-Runner braucht.
- `backups.file_pool` „Datei-Pool der Backups“: Größe des Verzeichnisses `files/` im
  Backup-Verzeichnis, durch rekursives Durchlaufen. `count` = Anzahl Dateien.
- `backups.other` „Sonstiges“: alles Übrige im Backup-Verzeichnis, falls ungleich 0.

Fehlt das Verzeichnis, ist der Knoten 0 Byte ohne Fehler.

### Sonstiges (`var`)

Je ein Kind `var.<verzeichnis>` pro Unterverzeichnis von `var/`, Label = deutscher Name
wo bekannt (`cache` Zwischenspeicher, `import` Import, `bank_statement_import`
Kontoauszug-Import, `htmlpurifier` HTML-Filter-Cache, `rate-limits` Anmeldebegrenzung),
sonst der Verzeichnisname. Lose Dateien direkt in `var/` als `var.loose`.

Ausgenommen sind die Verzeichnisse, die schon ein eigener Provider misst: das reale
(`realpath`) Dateiablage-Verzeichnis und das reale Backup-Verzeichnis, egal ob sie unter
`var/` liegen oder nicht. Symbolische Links werden nicht verfolgt.

## Oberfläche

### Seite `/storage`

`templates/storage/index.twig`, ohne eigenes JavaScript. Kopf wie die übrigen
Verwaltungsseiten: Titel „Speicherplatz“, Untertitel „Belegter Speicherplatz der
Installation, live berechnet“.

1. Kennzahl-Karten: Gesamt, Dateiablage (wenn vorhanden), Datenbank, Backups, Sonstiges.
   Ein Bereich mit `error` zeigt „nicht ermittelbar“.
2. Pro Bereich eine Karte: oben ein Balken mit dem Anteil am Gesamt, darunter eine Tabelle
   mit Label, Anzahl, Größe und Anteil. Kinder werden eingerückt dargestellt. Die
   Balkenbreite kommt über die CSS-Variable `--usage-share` (erlaubte Ausnahme laut
   template-hygiene), die Regeln stehen in `public/css/style.css`.
3. Dateiablage: zusätzlich die Tabelle der Teamordner mit „belegt / Kontingent“ und einem
   Balken, wenn ein Kontingent gesetzt ist.

Auf dem Handy werden die Tabellen gestapelt wie auf den übrigen Verwaltungsseiten
(`data-label`).

Größen formatiert ein neuer Twig-Filter `format_bytes` (B, KB, MB, GB, TB, Basis 1024,
KB ohne, ab MB eine Nachkommastelle, deutsches Dezimalkomma und Tausenderpunkt - wie
bisher die Sponsoring-Anhänge). `SponsoringAttachmentController`
hat dafür eine private Methode `formatSize`. Sie zieht in eine gemeinsame Klasse
`App\Util\ByteFormatter`, die Filter und Controller beide nutzen.

### Navigation

Eintrag „Speicherplatz“ im Bereich Administration direkt nach „Backups“, URL `/storage`,
Icon `bi-hdd`, Stichwörter `speicher`, `platz`, `belegung`, `aufräumen`, sichtbar mit
`can_manage_storage`.

### Dashboard-Kachel

Sichtbar nur mit `can_manage_storage`. Inhalt: Gesamtgröße groß, darunter ein gestapelter
Balken der vier Bereiche (Breiten über `--usage-share`) mit kurzer Legende samt Größen,
Zeile „Stand: <Datum Uhrzeit>“ und Link „Details“ nach `/storage`. Ein Bereich mit Fehler
erscheint in der Legende als „nicht ermittelbar“. Wirft `summary()` trotz allem, zeigt die
Kachel „Speicherplatz nicht ermittelbar“ und den Link, das Dashboard rendert weiter.

## Rechte und Daten

- Migration: Spalte `roles.can_manage_storage` (TINYINT(1), Standard 0, nach
  `can_manage_files`). Danach `UPDATE roles SET can_manage_storage = 1 WHERE hierarchy_level
  = (SELECT max_level FROM (SELECT MAX(hierarchy_level) AS max_level FROM roles) AS
  highest)` - die abgeleitete Tabelle ist nötig, weil MySQL im UPDATE keine direkte
  Unterabfrage auf dieselbe Tabelle erlaubt. `down()` entfernt die Spalte.
- Das Recht kommt an alle Stellen, an denen `can_manage_backups` steht: Role-Model,
  Ersteinrichtung in `AuthController`, `RoleController` (Anlegen, Bearbeiten,
  `buildPermissionFlags`), Übernahme in die Sitzung beim Login und beim Auffrischen,
  Rollen-Matrix und beide Formulare in `templates/roles/index.twig` (Label
  „Speicherplatz-Verwaltung“, Hilfetext „Wenn aktiv, darf diese Person die Übersicht über
  den belegten Speicherplatz sehen.“), `roles.js` falls es die Datenattribute liest,
  `DevSeedService` für die Admin-Rolle.
- `RoleMiddleware`: neues Gate `requiresStorageManagement` mit Meldung „Zugriff verweigert:
  Sie haben keine Berechtigung zur Speicherplatz-Verwaltung.“
- Route `GET /storage` in eigener Gruppe mit diesem Gate.
- Settings: `storage.summary_cache` = `var/cache/storage-usage-summary.json`.

### Seed-Daten

Ziel: Auf einer frisch geseedeten Installation hat jede Kategorie einen Wert größer 0. Zu
prüfen und, wo es fehlt, zu ergänzen: mindestens eine Datei mit älterer Version, eine
Datei im Papierkorb, eine Datei in einem gelöschten Ordner, Anhänge in jedem
`entity_type`, Einträge in `mail_queue` und `user_notifications`. Backups und `var/`
entstehen im Betrieb und werden nicht geseedet.

## Tests (vor der Umsetzung)

- `FileStorageUsageProvider`: Zuordnung aktuell, alt und Papierkorb, gelöschte Datei,
  Datei in gelöschtem Unterordner, geteilter Speicherpfad (zurückgeholte alte Version)
  zählt einmal und in der höchsten Kategorie. Invariante gegen
  `FileQuotaService::totalUsedBytes()`. Teamordner-Aufschlüsselung mit Kontingent.
- `DatabaseUsageProvider`: Anhänge nach `entity_type` mit Größe und Anzahl, unbekannter
  Typ, Summe der Kinder gleich Wurzel, Mail-Warteschlange mit Zeilenzahl.
- `BackupUsageProvider` (temporäres Verzeichnis): manuell und automatisch getrennt,
  Datei-Pool, fehlendes Verzeichnis ergibt 0 ohne Fehler.
- `VarDirectoryUsageProvider` (temporäres Verzeichnis): Unterverzeichnisse,
  ausgenommene Dateiablage- und Backup-Verzeichnisse, lose Dateien, Symlink wird nicht
  verfolgt.
- `StorageUsageService`: Gesamt = Summe der Wurzeln. Ein werfender Provider ergibt einen
  Knoten mit `error`, die übrigen bleiben korrekt. Frischer Cache wird verwendet, alter
  Cache wird neu gerechnet, kaputter Cache gilt als fehlend, `collect()` schreibt den
  Cache.
- `ByteFormatter`: Grenzen 0 B, 1023 B, 1 KB, 1 MB, Dezimalkomma, Tausenderpunkt, GB, TB.
- Feature: `GET /storage` ohne Recht 403, mit Recht 200 mit den Bereichsüberschriften.
  Navigation sichtbar bzw. unsichtbar. Dashboard-Kachel sichtbar bzw. unsichtbar.
- Migration: Recht landet bei den Rollen mit dem höchsten Level, auch wenn das nicht 100
  ist, und bei keiner anderen.
- Rollen: Anlegen und Bearbeiten speichern das neue Recht.
