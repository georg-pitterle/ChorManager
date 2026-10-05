# Office-Dokumente in der Dateiablage bearbeiten (Collabora Online)

Stand: 2026-10-04 · Status: Entwurf, abgestimmt im Brainstorming

## Ziel

Office-Dokumente in der Dateiablage lassen sich im Browser öffnen und bearbeiten, ohne
sie herunter- und wieder hochzuladen. Der Editor ist Collabora Online, eingebettet in
ChorManager. Gespeichert wird als Version derselben Datei. Mehrere Personen können
gleichzeitig im selben Dokument arbeiten (bringt Collabora mit).

Erfolg heißt: Datei in der Ablage öffnen → bearbeiten → schließen; der neue Stand liegt
als **eine** neue Version vor.

## Entscheidungen

| Frage | Entscheidung |
|---|---|
| Protokoll | WOPI. Damit bleibt eine spätere Anbindung von Euro-Office (ONLYOFFICE-Fork, WOPI-fähig) ohne zweite Schnittstelle möglich. |
| Wer darf was | Stufe „Bearbeiten“ (`LEVEL_EDIT`) oder höher bearbeitet, Stufe „Lesen“ sieht das Dokument im Viewer an. Herunterladen und Drucken im Viewer bleiben erlaubt — der normale Download-Knopf ist es ohnehin. |
| Versionen | Eine Version pro Bearbeitungssitzung, nicht pro Speichervorgang. |
| Authentifizierung der WOPI-Aufrufe | Zufälliges Token, nur der Hash in der Datenbank; jeder Aufruf prüft die Rechte neu. |
| Entwicklung | Collabora als optionaler DDEV-Container; Tests kommen ohne Collabora aus. |
| Neue leere Dokumente | Ab Stufe „Hochladen“ (wie ein Upload, kein eigenes Freigaberecht): Textdokument `.docx`, Tabelle `.xlsx`, Präsentation `.pptx`; nur, was die Discovery als bearbeitbar meldet. Vorlagen unter `assets/office-templates/`, erzeugt von Collabora (convert-to). Ein vorhandener Name wird nie überschrieben (409). |
| Editor-Darstellung | Ganze Seite wie in Nextcloud: eigene Seite ohne App-Layout, zurück über den Schließen-Knopf von Collabora. |

## Nicht im Umfang

- Bearbeiten oder Ansehen über öffentliche Links (`/s/...`)
- Prüfung der WOPI-Proof-Keys (mögliche spätere Härtung, zusätzlich zum Token)
- Euro-Office / ONLYOFFICE
- Dateisperren (`Lock`/`Unlock`) — Collabora braucht sie nicht; für Euro-Office später nötig
- Playwright-Szenario (setzt den Collabora-Container voraus; kann nachgereicht werden)
- Hilfethema (nur auf ausdrückliche Anforderung)

## Voraussetzungen im Betrieb

- Der Browser erreicht Collabora unter `OFFICE_SERVER_URL` (HTTPS).
- Collabora erreicht ChorManager unter `OFFICE_WOPI_BASE_URL`.
- ChorManager erreicht Collabora für die Discovery unter `OFFICE_SERVER_INTERNAL_URL`
  (Standard: `OFFICE_SERVER_URL`).
- In der Collabora-Konfiguration (`coolwsd.xml` bzw. `aliasgroup1` im Container) ist nur
  der ChorManager-Host als WOPI-Host eingetragen.

Ob der bereitstehende Collabora-Server und die Produktiv-Instanz sich gegenseitig
erreichen, ist vor dem Ausrollen zu klären.

## Architektur

### Konfiguration (`src/Settings.php`)

| Variable | Bedeutung |
|---|---|
| `FEATURE_OFFICE` | Schaltet die Anbindung ein. Wirkt nur zusammen mit `FEATURE_FILES`. |
| `OFFICE_SERVER_URL` | Adresse, unter der der **Browser** Collabora erreicht. |
| `OFFICE_SERVER_INTERNAL_URL` | Optional. Adresse, unter der **ChorManager** die Discovery holt. Standard: `OFFICE_SERVER_URL`. |
| `OFFICE_WOPI_BASE_URL` | Adresse, unter der **Collabora** ChorManager erreicht (DDEV: `http://web`). Standard: App-URL. |

Die Werte kommen nach `.env.example`.

### Bausteine (`src/Services/Office/`)

- **`OfficeDiscovery`** — lädt `{OFFICE_SERVER_INTERNAL_URL}/hosting/discovery`, hält das
  Ergebnis im Zwischenspeicher und liefert zu einer Dateiendung die Editor-URL
  (`urlsrc`) sowie, ob die Endung `edit` oder nur `view` unterstützt. Der HTTP-Client ist
  austauschbar, damit Tests ohne Collabora laufen. Fehlschlag → Log
  `office.discovery_failed`, Ergebnis „nichts verfügbar“.
- **`OfficeTokenService`** — stellt Tokens aus, löst sie auf, räumt abgelaufene ab.
- **`WopiFileService`** — CheckFileInfo, Inhalt lesen, Speichern. Schreibt über den
  vorhandenen `FileService` (Kontingent, MIME-Sperrliste, Versionierung,
  `pruneVersions`); dafür bekommt `FileService` eine Methode für das Speichern aus einer
  Office-Sitzung.

### Datenmodell (Phinx-Migration)

Neue Tabelle `office_access_tokens`:

| Spalte | Typ | Hinweis |
|---|---|---|
| `id` | int | |
| `token_hash` | char(64) | SHA-256 des Tokens, eindeutig |
| `file_id` | int | FK `files.id`, `ON DELETE CASCADE` |
| `user_id` | int | FK `users.id`, `ON DELETE CASCADE` |
| `expires_at` | datetime | Index |
| `created_at` | datetime | |

Neue Spalten in `file_versions`:

| Spalte | Typ | Hinweis |
|---|---|---|
| `office_session_open` | bool, Standard `false` | Version gehört zu einer laufenden Collabora-Sitzung |
| `office_saved_at` | datetime, nullable | Zeitpunkt des letzten Schreibens durch Collabora |

### Routen

Angemeldet, in der `/files`-Gruppe:

- `GET /files/{id}/edit` — Editor-Seite; stellt das Token aus.

Öffentlich (das Token ist die Berechtigung), nur bei `FEATURE_FILES` und `FEATURE_OFFICE`,
in `CsrfMiddleware::EXEMPT_PREFIXES` mit `/wopi`:

- `GET /wopi/files/{id}` — CheckFileInfo
- `GET /wopi/files/{id}/contents` — GetFile
- `POST /wopi/files/{id}/contents` — PutFile

### Ablauf

1. In der Ablage (Liste und Detailseite) erscheint bei Dateien, deren Endung die
   Discovery kennt, „Bearbeiten“ (Stufe ≥ Bearbeiten und Endung kann `edit`) oder
   „Ansehen“ (sonst, ab Stufe Lesen).
2. `GET /files/{id}/edit` prüft Stufe ≥ Lesen und stellt ein Token aus. Die Seite enthält
   ein Formular mit `access_token` und `access_token_ttl`, das eine eigene JS-Datei
   (kein Inline-JS) per POST in ein iframe an
   `{urlsrc}WOPISrc={OFFICE_WOPI_BASE_URL}/wopi/files/{id}` schickt.
3. Collabora ruft CheckFileInfo auf. ChorManager löst das Token auf, prüft die Stufe neu und
   antwortet mit:
   - `BaseFileName`, `Size`, `Version` (ID der aktuellen Version), `LastModifiedTime`
   - `OwnerId`, `UserId`, `UserFriendlyName`
   - `UserCanWrite` (Stufe ≥ Bearbeiten und Endung kann `edit`)
   - `UserCanNotWriteRelative: true` (kein „Speichern unter“)
   - `PostMessageOrigin` (Ursprung der App)
4. GetFile liefert den Inhalt der aktuellen Version aus `FileStorage`.
5. PutFile speichert (siehe unten).
6. „Schließen“ im Editor meldet Collabora per PostMessage; das JS kehrt zur Detailseite der
   Datei zurück.

### Nutzer ohne Sitzung

`FileActor` bezieht `isFileAdmin` bisher aus der Sitzung. Für WOPI-Aufrufe wird der Actor
aus `user_id` des Tokens gebaut und `isFileAdmin` aus den aktuellen Rollen des Nutzers
berechnet. Ein deaktivierter (`users.is_active = 0`) oder gelöschter Nutzer erhält keinen
Zugriff.

## Speichern und Versionen

### Randbedingung

Jeder Speicherpfad wird genau einmal geschrieben und nie überschrieben; darauf baut die
inkrementelle Dateisicherung (`FileBackupService`). „Version ersetzen“ heißt daher: neuen
Pfad schreiben, die Versionszeile darauf umhängen, den alten Pfad löschen, wenn keine
Version mehr auf ihn zeigt (Muster wie bei `restoreVersion`/`deleteUnreferencedStorage`).

### PutFile

1. Token gültig, sonst 401. Stufe ≥ Bearbeiten und Endung kann `edit`, sonst 403. Datei
   gelöscht oder im Papierkorb → 404.
2. **Konfliktprüfung**: Ist der Header `X-COOL-WOPI-Timestamp` gesetzt und weicht er von
   `LastModifiedTime` ab → `409` mit `{"COOLStatusCode":1010}`. Collabora fragt dann, ob
   überschrieben oder neu geladen werden soll; beim Überschreiben kommt PutFile ohne
   Zeitstempel erneut.
   `LastModifiedTime` = `office_saved_at` der aktuellen Version, sonst ihr `created_at`,
   im Format ISO 8601 UTC.
3. **Prüfungen wie beim Hochladen**: Größe (`maxUploadBytes` → 413), MIME-Sperrliste (→ 422),
   Kontingent (→ 413, wie `FileQuotaService` es schon meldet). Beim Ersetzen zählt fürs
   Kontingent nur der Größenzuwachs. Ein leerer Rumpf (0 Bytes) wird mit 400 abgewiesen —
   er würde das Dokument leeren.
4. **Version wählen**, in einer Transaktion mit `lockForUpdate` auf der Dateizeile:
   - Aktuelle Version hat `office_session_open = true` **und** `office_saved_at` ist jünger
     als 60 Minuten → diese Version ersetzen (neuer Pfad, `size`, `mime_type`, `sha256`,
     `uploaded_by`, `office_saved_at` aktualisieren; `files.size`/`mime_type`/`updated_by`
     nachziehen).
   - Sonst → neue Version über `addVersion`, mit `office_session_open = true` und
     `office_saved_at = jetzt`; danach `pruneVersions`.
5. `X-COOL-WOPI-IsExitSave: true` → nach dem Speichern `office_session_open = false`.
6. Antwort `200` mit `{"LastModifiedTime": "…"}`.

Begründung der 60 Minuten: Ändert sich beim Schließen nichts, sendet Collabora kein
Exit-Save, und das Flag bliebe offen. Ohne Zeitgrenze überschriebe die nächste Sitzung die
Version der vorigen. In einer aktiven Sitzung mit Änderungen speichert Collabora
spätestens alle paar Minuten.

`uploaded_by` ist die Person, deren Token das Speichern auslöst — bei gemeinsamer
Bearbeitung die zuletzt speichernde.

Jede Version, die auf anderem Weg entsteht (Hochladen, Ersetzen, Wiederherstellen), hat
`office_session_open = false` — eine Office-Sitzung danach beginnt also mit einer neuen
Version, und eine noch offene Sitzung läuft in den Konflikt aus Schritt 2.

## Fehlerfälle

| Fall | Verhalten |
|---|---|
| Collabora nicht erreichbar / Discovery fehlerhaft | Keine Bearbeiten-/Ansehen-Knöpfe; `/files/{id}/edit` leitet mit Fehlermeldung zur Detailseite zurück; Log `office.discovery_failed`. Der Fehlschlag wird eine Minute zwischengespeichert. Die Ablage funktioniert normal. |
| Endung unbekannt | Kein Knopf; `/files/{id}/edit` leitet mit Fehlermeldung zur Detailseite zurück |
| Endung nur `view` | Auch mit Stufe Bearbeiten nur Lesemodus |
| Token unbekannt, abgelaufen oder für andere Datei-ID | 401 |
| Nutzer deaktiviert | 401 beim nächsten WOPI-Aufruf |
| Rechte ganz entzogen | 404 beim nächsten WOPI-Aufruf (wie in der Ablage: wer die Datei nicht sieht, erfährt nichts über sie) |
| Nur noch Lesen, aber Speichern | 403 |
| Datei im Papierkorb / gelöscht | 404 |

## Sicherheit

- Token: 32 Byte aus `random_bytes`, URL-sicher kodiert; gespeichert nur SHA-256; Vergleich
  über den Hash-Lookup. TTL 10 Stunden; `access_token_ttl` ist nach WOPI der absolute
  Ablaufzeitpunkt in Millisekunden seit 1970.
- Ein Token gilt für genau eine Datei und einen Nutzer.
- Abgelaufene Tokens räumt jedes neue Ausstellen ab; ein eigener Cron-Lauf lohnt dafür nicht.
- Das Token steht als Query-Parameter `access_token` in den WOPI-URLs (von Collabora so
  vorgegeben). Die App loggt nur den Pfad einer Anfrage, nie die Query, und keine
  Office-Logzeile enthält das Token (per Test bewacht). Das Zugriffslog des Webservers
  enthält Query-Strings — im Betrieb ist es entsprechend zu behandeln.
- Die CSRF-Injektion (`HtmlFormCsrfInjectorMiddleware`) lässt Formulare mit absoluter
  Zieladresse aus. Sonst ginge das CSRF-Token der Sitzung mit dem Editor-Formular an den
  Office-Server.
- CSP: Nur auf `/files/{id}/edit` erlaubt `SecurityHeadersMiddleware` den Ursprung von
  `OFFICE_SERVER_URL` in `frame-src` und `form-action`. Alle anderen Routen bleiben
  unverändert.
- Logs enthalten Kennungen, keine Dateinamen. Events: `office.token_issued`,
  `office.file_saved`, `office.save_conflict`, `office.save_rejected`,
  `office.discovery_failed`.

## DDEV (optional)

- `.ddev/docker-compose.collabora.yaml` mit `collabora/code` in fester Version.
- Umgebung: `aliasgroup1=http://web`,
  `extra_params=--o:ssl.enable=false --o:ssl.termination=true`.
- Browserzugang über den DDEV-Router per HTTPS auf eigenem Port (z. B.
  `https://chormanager.ddev.site:9980`).
- Der Dienst hängt am Compose-Profil `collabora` und startet nur mit
  `ddev start --profiles=collabora`.
- Ein Kommentar im Kopf der Datei beschreibt — wie bei Webmail — die nötigen `.env`-Werte.

## Tests

PHP-Feature-Tests, geschrieben vor der Umsetzung, ohne echten Collabora:

- **Discovery**: Parsen einer festen Discovery-XML (Fixture); `edit` vs. `view`;
  Server nicht erreichbar.
- **Editor-Seite**: Token wird ausgestellt; Formular zielt auf `urlsrc` mit korrektem
  `WOPISrc`; CSP-Header nur dort gelockert; ohne Stufe Lesen nicht erreichbar; Feature aus
  → 404.
- **CheckFileInfo**: `UserCanWrite` je Stufe und für Datei-Admin; entzogene Rechte;
  deaktivierter Nutzer; Token für fremde Datei; abgelaufenes Token.
- **GetFile**: liefert die aktuelle Version.
- **PutFile** (regelbewachende Tests mit Schärfeprobe):
  - erste Speicherung erzeugt eine Version, weitere ersetzen sie
  - Exit-Save schließt die Sitzung, nächste Speicherung erzeugt neue Version
  - nach 60 Minuten ohne Speichern entsteht eine neue Version
  - abweichender Zeitstempel → 409 / 1010; ohne Zeitstempel wird überschrieben
  - Hochladen über die Ablage während der Sitzung → Konflikt
  - Größenlimit → 413, Kontingent → 413 (Zuwachs-Berechnung beim Ersetzen)
  - leerer Rumpf → 400
  - Stufe Lesen → 403
  - alter Speicherpfad wird nur gelöscht, wenn keine Version mehr auf ihn zeigt
  - Token erscheint in keiner Logzeile
- **Token-Bereinigung**: Ausstellen löscht nur abgelaufene Tokens.
- **CSRF-Injektion**: kein Token in Formularen mit absoluter Zieladresse.
- **JS** (`node --test`): Nachrichten von Collabora werden nur vom Office-Ursprung angenommen.

## Seed-Daten

Enthalten die Ablage-Seeds noch keine Office-Datei, kommen eine `.odt` und eine `.docx`
dazu, damit lokal etwas zu öffnen ist. `office_access_tokens` braucht keine Seeds (kurzlebig).
