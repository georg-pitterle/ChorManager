# Anhang-Vorschau und Download vereinheitlichen

Datum: 2026-08-31

## Ziel

Anhänge sollen sich überall gleich verhalten: ein Vorschau-Modal und ein Download-Button,
egal ob die Datei an einer Aufgabe, einer Buchung, einem Sponsor, einer Vereinbarung oder
einem Song hängt.

Heute macht jeder Bereich etwas anderes, und ein Vorschau-Modal gibt es nirgends:

| Ort | Verhalten heute |
|---|---|
| `templates/partials/attachments.twig` (nur Aufgaben) | Dateiname als Link auf Download, dazu Löschen |
| `templates/finances/index.twig` | Dropdown mit `target="_blank"`, Server entscheidet per MIME zwischen `inline` und `attachment` |
| `templates/sponsoring/attachments/index.twig` | Namenslink plus Download-Button, beide auf dieselbe URL |
| `templates/sponsoring/sponsors/detail.twig` | Namenslinks in zwei getrennten Listen (Sponsor, Vereinbarungen) |
| `templates/songs/detail.twig` | Download-Link |
| `templates/songs/downloads.twig` | eingebettete Audio- und MIDI-Player plus Download-Link |

Auch das Backend ist dreifach gebaut: `EntityAttachmentService::buildDownloadResponse()`
erzwingt `attachment`, `FinanceController::viewAttachment()` entscheidet per MIME-Whitelist,
und `DownloadController` hat eine eigene `/stream`-Route, die nur Audio-Typen freigibt.
Ein Fehler in der Auslieferung müsste an drei Stellen behoben werden.

## Umfang

Enthalten sind alle sechs oben genannten Stellen.

Die Downloads-Seite behält ihre direkt eingebetteten Audio- und MIDI-Player — sie sind dort
der eigentliche Zweck der Seite, ein Modal wäre ein Rückschritt. Sie bekommt das Modal
zusätzlich für PDF- und Bild-Anhänge.

Nicht enthalten: Änderungen am Upload-Weg, an den erlaubten MIME-Typen des `UploadValidator`
und an der Löschlogik. Ebenfalls nicht enthalten sind Anhänge außerhalb der Tabelle
`attachments` (Hilfe-Bilder, Newsletter-Assets, Backups).

## Vorschaubare Typen

Eine einzige Quelle entscheidet: `App\Util\AttachmentPreview`. Genutzt vom Controller *und* —
als registrierte Twig-Funktion `attachment_previewable()` — von den Templates. Zwei getrennte
Listen im Code wären genau die Doppelung, die dieser Umbau beseitigt.

Die Klasse kennt zwei Fragen, weil sie nicht dieselbe sind:

- `isInlineServable(string $mime): bool` — was die Route `/attachments/{id}/preview` überhaupt
  mit `Content-Disposition: inline` ausliefert.
- `isModalPreviewable(string $mime): bool` — was einen Vorschau-Button bekommt, also im
  gemeinsamen Modal auch wirklich darstellbar ist. Immer eine Teilmenge von `isInlineServable()`.

| MIME | inline ausgeliefert | Modal-Vorschau |
|---|---|---|
| `application/pdf` | ja | `<iframe>` |
| `image/jpeg`, `image/png`, `image/webp`, `image/gif` | ja | `<img>` |
| `text/plain` | ja | `<pre>`, Inhalt per `fetch` geladen |
| `audio/mpeg` | ja | `<audio controls>` |
| `audio/midi`, `audio/x-midi`, `application/x-midi` | ja | **nein** |

MIDI bleibt außen vor, weil die Wiedergabe `Tone.js`, `magenta-music` und `html-midi-player`
braucht und diese drei nur im `scripts`-Block von `templates/songs/downloads.twig` geladen
werden. Ein global eingebundenes Modal hätte sie auf keiner anderen Seite. Sie überall zu laden,
wäre drei zusätzliche Bibliotheken auf jeder Seite für einen Dateityp, den heute niemand
hochladen kann (siehe unten). Der eingebettete Player auf der Downloads-Seite bleibt und zieht
seine Quelle künftig von `/attachments/{id}/preview` — dafür muss MIDI inline ausgeliefert
werden, aber nicht modalfähig sein.

Nicht vorschaubar sind die übrigen erlaubten Upload-Typen: `application/msword`,
`application/vnd.openxmlformats-officedocument.wordprocessingml.document`,
`application/vnd.ms-excel`,
`application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`.
Dort erscheint **kein** Vorschau-Button, nur der Download-Button.

`audio/mpeg` und die MIDI-Typen stehen nicht in den erlaubten Upload-Typen des
`UploadValidator` — über die Oberfläche kommt heute keine solche Datei herein. Die Zeilen
stammen aus Altbestand, den die Downloads-Seite aber aktiv abspielt. Die Auslieferung behält
sie deshalb; der Upload-Weg bleibt unverändert.

### Normalisierung

`UploadValidator::normalizeMimeType()` macht nur `trim` und `strtolower` — es schneidet keine
Parameter ab. Ein in der Datenbank gespeichertes `text/plain; charset=utf-8` fiele damit durch
jeden Vergleich. `AttachmentPreview` normalisiert deshalb selbst: alles ab dem ersten `;`
abschneiden, dann trimmen und kleinschreiben.

## Architektur

### AttachmentAccessRegistry

Neu: `src/Services/AttachmentAccessRegistry.php`. Eine Tabelle `entity_type` → Rechteprüfung,
im selben Geist wie die `GATES`-Tabelle in `src/Middleware/RoleMiddleware.php`.

| entity_type | Prüfung | Quelle heute |
|---|---|---|
| `finance` | `can_read_finances` oder `can_manage_finances` | Routen-Gate `requiresFinanceRead` |
| `task` | `TaskPolicy::canManageTasks()` | `TaskController::downloadAttachment()` |
| `sponsor` | `SponsoringPolicy::canSeeSponsorDetails($sponsor)` | `SponsorController::downloadAttachment()` |
| `sponsorship` | `SponsoringPolicy::canSeeSponsorshipDetails($sponsorship)` | `SponsorshipController::downloadAttachment()` |
| `song` | Projektmitgliedschaft über `project_song_assignments` **oder** `can_manage_song_library` | `DownloadController::findMemberAttachment()` |

Beim Song ist das Recht `can_manage_song_library` neu in der Prüfung. Heute scheitert der
Download aus `songs/detail.twig` für eine Repertoire-Verwaltung, die in keinem Projekt Mitglied
ist, weil der Link auf die mitgliedschaftsgebundene Downloads-Route zeigt. Das ist ein
bestehender Fehler, den die Zusammenführung mitnimmt.

Die Prüfung läuft **vor** dem Laden des Datei-Inhalts. Zuerst werden nur die Metadaten geholt
(`EntityAttachmentService::METADATA_COLUMNS`), dann entscheidet die Registry, erst danach wird
`file_content` nachgeladen. Sonst zieht der Server zweistellige Megabyte durch den Speicher, um
sie anschließend zu verwerfen.

Der Registry-Eintrag für einen unbekannten `entity_type` fehlt bewusst: unbekannt heißt
abgelehnt, nicht durchgelassen.

Zusätzlich prüft die Registry das Modul. Die alten Routen lagen innerhalb von
`if ($settings['modules']['finance'] ?? false)` bzw. `['sponsoring']` in `src/Routes.php` und
verschwanden mit dem abgeschalteten Modul. Die zentrale Route liegt außerhalb dieser Blöcke —
ohne eigene Prüfung wären Belege und Verträge bei abgeschaltetem Modul weiterhin abrufbar.
`finance` verlangt `modules.finance`, `sponsor` und `sponsorship` verlangen `modules.sponsoring`,
`task` verlangt `modules.tasks`. Für `song` gibt es kein Modul.

### AttachmentController

Neu: `src/Controllers/AttachmentController.php`, zwei Routen hinter `AuthMiddleware`, ohne
`RoleMiddleware` — das Recht hängt am `entity_type` des Anhangs, nicht am Pfad, und kann deshalb
nur im Controller fallen.

- `GET /attachments/{id:[0-9]+}/preview`
  `Content-Disposition: inline`. Typ nicht in `isInlineServable()` → **415**. Unterstützt
  `Range`, damit das Springen im MIDI- und MP3-Player erhalten bleibt.
- `GET /attachments/{id:[0-9]+}/download`
  `Content-Disposition: attachment`, alle Typen.

Beide antworten bei fehlendem Recht mit **404**, nicht 403: ein 403 verrät, dass es den Anhang
gibt. Dieselbe Antwort gilt für eine nicht existierende ID.

Dateiname immer über `DownloadFileName::sanitize()` plus `filename*=UTF-8`-Kodierung, wie bisher.

Die Range-Behandlung und `parseRangeHeader()` wandern aus `DownloadController` in einen
gemeinsamen Ort (`src/Services/AttachmentResponseFactory.php`), damit Download- und
Vorschau-Antwort denselben Code benutzen.

### Wegfallende Routen

Ersatzlos, mit Anpassung aller Templates und Tests:

- `GET /finances/attachments/{id}` → `FinanceController::viewAttachment()` entfällt
- `GET /tasks/{id}/attachments/{attachment_id}/download` → `TaskController::downloadAttachment()` entfällt
- `GET /sponsoring/sponsors/{id}/attachments/{attachment_id}` → `SponsorController::downloadAttachment()` entfällt
- `GET /sponsoring/sponsorships/{id}/attachments/{attachment_id}` → `SponsorshipController::downloadAttachment()` entfällt
- `GET /downloads/attachments/{attachment_id}/download` und `/stream` → beide Methoden in `DownloadController` entfallen

Keine Weiterleitungen: Die URLs stehen nur in eigenen Templates, ein Altbestand ohne Nutzen
wäre nur eine zweite zu pflegende Oberfläche. Upload- und Löschrouten bleiben unverändert, wo
sie sind — sie hängen an Rechten des jeweiligen Bereichs, nicht am Anhang.

`EntityAttachmentService::buildDownloadResponse()` entfällt zugunsten der
`AttachmentResponseFactory`; `storeUploads()`, `findWithContent()`, `deleteForEntity()` und
`deleteAllForEntities()` bleiben.

### Content Security Policy

Ein PDF im Modal braucht ein `<iframe>` auf die eigene Vorschau-Route. Die Antwort dieser Route
trägt heute `frame-ancestors 'none'` und `X-Frame-Options: DENY` und wäre damit nicht
einbettbar.

`SecurityHeadersMiddleware::allowsSelfFraming()` bekommt deshalb ein zweites Muster neben dem
Newsletter-Rahmen: `^/attachments/\d+/preview$` → `frame-ancestors 'self'` und
`X-Frame-Options: SAMEORIGIN`.

Vertretbar, weil die Route ausschließlich Dateiinhalte ausliefert, kein HTML-MIME auf der
Vorschau-Whitelist steht und `X-Content-Type-Options: nosniff` gesetzt bleibt. Jede andere Route
behält `'none'`.

## Oberfläche

### Bausteine

- `templates/partials/attachment_actions.twig` — rendert für **einen** Anhang das Buttonpaar.
  Parameter sind einfache Werte, kein Objekt: `attachment_id`, `attachment_name`,
  `attachment_mime`, `attachment_size`, optional `show_label` (Vorgabe `false`). Ein Objekt ginge
  nicht, weil die Aufrufstellen unterschiedliche Formen liefern — Eloquent-Modelle mit
  `original_name`/`file_size` an fünf Stellen, ein Array mit `name`/`size_bytes` in
  `SponsoringAttachmentController::baseRow()`. Der Vorschau-Button wird weggelassen, wenn
  `attachment_previewable(attachment_mime)` falsch ist; die Twig-Funktion bildet auf
  `AttachmentPreview::isModalPreviewable()` ab. Der Button trägt `data-attachment-id`,
  `data-attachment-name`, `data-attachment-mime` und `data-attachment-size`.
- `templates/partials/attachment_preview_modal.twig` — die Modal-Hülle, **einmal** in
  `templates/layout.twig` eingebunden. Damit steht sie in jedem Bereich zur Verfügung, ohne dass
  sechs Templates sie einzeln einbinden.
- `public/js/attachment-preview.js` — global in `layout.twig` geladen, delegiert auf
  `[data-attachment-id]`. Baut den Modal-Körper je MIME-Gruppe, setzt Titel, Größe, Typ und die
  `href` des Download-Buttons im Fußbereich. Beim Schließen wird der Körper geleert, damit ein
  laufender Player stoppt und das nächste Öffnen nicht die vorige Datei zeigt.
- Styles in `public/css/style.css`. Kein Inline-CSS, kein Inline-JS.

Fehlerfälle im Modal: Lädt die Vorschau nicht (404 nach Rechteentzug, 415, Netzfehler), zeigt der
Modal-Körper einen kurzen deutschen Hinweis und den Download-Button, statt leer zu bleiben.

### Die sechs Stellen

1. **`templates/partials/attachments.twig`** (Aufgaben) — Dateiname wird normaler Text, dahinter
   das Buttonpaar, Löschen bleibt rechts.
2. **`templates/finances/index.twig`** — die Dropdown-Einträge tragen je Datei Vorschau und
   Download statt eines `target="_blank"`-Links. Der Paperclip-Knopf mit Anzahl bleibt.
3. **`templates/sponsoring/attachments/index.twig`** — die Aktionsspalte bekommt beide Buttons;
   der Dateiname in der ersten Spalte wird Text statt Link.
4. **`templates/sponsoring/sponsors/detail.twig`** — beide Listen (Sponsor-Stammdaten und
   Vereinbarungen) nutzen den Baustein.
5. **`templates/songs/detail.twig`** — Buttonpaar statt Download-Link.
6. **`templates/songs/downloads.twig`** — die eingebetteten Audio- und MIDI-Player bleiben und
   ziehen ihre Quelle künftig von `/attachments/{id}/preview`. Für PDF und Bilder kommt der
   Vorschau-Button dazu, der Download-Link wird zum Download-Button des Bausteins.

## Tests

Unit:

- `AttachmentPreviewTest` — `isInlineServable()` und `isModalPreviewable()` je für vorschaubare
  und nicht vorschaubare Typen, MIDI nur in der ersten Liste, Normalisierung von
  `text/plain; charset=utf-8` und `TEXT/PLAIN`.
- Der bestehende Range-Parser-Test zieht auf den neuen Ort um.

Feature `tests/Feature/AttachmentAccessFeatureTest.php`, je `entity_type` (finance, task,
sponsor, sponsorship, song):

- Berechtigte Person, `/download` → 200, `Content-Disposition: attachment`
- Berechtigte Person, `/preview` mit PDF → 200, `Content-Disposition: inline`
- Unberechtigte Person → 404 auf beiden Routen
- Nicht vorschaubarer Typ (`.docx`) auf `/preview` → 415
- Nicht existierende ID → 404
- Song zusätzlich: Person mit `can_manage_song_library` ohne Projektmitgliedschaft → 200
- Abgeschaltetes Modul: berechtigte Person, `modules.finance` aus → 404 auf beiden Routen,
  dasselbe für `modules.sponsoring` und `modules.tasks`

Feature `tests/Feature/AttachmentPreviewCspFeatureTest.php`:

- `/attachments/{id}/preview` trägt `frame-ancestors 'self'` und `X-Frame-Options: SAMEORIGIN`
- `/attachments/{id}/download` und eine beliebige normale Seite tragen weiterhin `'none'` / `DENY`

## Seed-Daten

Die Seed-Anhänge tragen heute PDF-MIME, enthalten aber reinen Text
(`DevSeedService::seedSongAttachments()` und die Sponsoring-Definitionen). Im Modal bliebe die
PDF-Vorschau in Dev leer — genau der Fall, den man beim Entwickeln sehen will.

Neu: eine kleine Hilfsklasse für Seed-Dateiinhalte (`src/Services/DevSeedAttachmentFixtures.php`)
liefert vier echte Inhalte — ein minimal gültiges PDF, ein winziges PNG, eine `.txt` und eine
Datei mit `.docx`-MIME. Alle bestehenden Seed-Methoden für Anhänge nutzen sie, und jeder der
fünf Bereiche bekommt mindestens je einen vorschaubaren und einen nicht vorschaubaren Anhang,
damit beide Zustände der Oberfläche sofort sichtbar sind.

Checkliste nach `instructions/seed.md`: neue Tabellen entstehen keine, die bestehenden Zähler
`song_attachments`, `task_attachments`, `finance_attachments` und `sponsor_attachments` bleiben
unverändert — die Anzahl der Anhänge ändert sich nicht, nur ihr Inhalt und ihre MIME-Typen. Ein
echter Seed-Lauf und die Prüfung des Berichts gehören zur Umsetzung.

## Offene Randfälle

- Ein `text/plain`-Anhang von 10 MB würde per `fetch` komplett in den Modal-Körper geladen. Die
  Vorschau schneidet nach den ersten 200 KB ab und weist im Modal darauf hin.
- `Cache-Control` bleibt auf dem Vorgabewert `no-store` der `SecurityHeadersMiddleware`.
  Anhänge sind Mitgliederdaten und haben in keinem Zwischenspeicher etwas verloren.
