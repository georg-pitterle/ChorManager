# Newsletter-Anhänge

Stand: 2026-09-28

## Ziel

Ein Newsletter kann Dateien mitführen. Je Datei entscheidet die Redaktion, ob sie als
echter Mail-Anhang mitreist oder ob die Mail nur einen Download-Link enthält.

## Entscheidungen

| Frage | Entscheidung |
|---|---|
| Zustellart | beides, je Datei wählbar |
| Wer entscheidet | Redaktion, vorbelegt nach Dateigröße |
| Schwelle Vorbelegung | `< 2 MB` → Anhang, sonst Link |
| Hartes Gesamtlimit | 10 MB echte Anhänge je Mail, sonst bricht der Versand ab |
| Link-Zugriff | wer den Newsletter sehen darf, darf auch die Datei laden |
| Darstellung der Links | automatischer Block „Dateien" unter dem Inhalt |
| Nach dem Versand | eingefroren: kein Hinzufügen, kein Löschen, kein Moduswechsel |

## Datenmodell

Bestehende Tabelle `attachments` (`entity_type`/`entity_id`, BLOB in `file_content`)
wird mitgenutzt: `entity_type = 'newsletter'`, `entity_id = newsletters.id`.

Migration `add_delivery_mode_to_attachments`:

- `delivery_mode ENUM('attach','link') NOT NULL DEFAULT 'link'` nach `file_size`

Default `'link'`, weil das die harmlose Richtung ist: eine Zeile ohne gesetzten Modus
bläht keine Mail auf. Bestandszeilen anderer Entity-Typen erhalten den Wert ebenfalls
und ignorieren ihn.

Kein eigenes Modell, keine Join-Tabelle. Ein Newsletter geht genau einmal raus; eine
Wiederverwendung derselben Datei über mehrere Newsletter ist kein Bedarf, der heute
besteht.

## Verwaltung

`edit.twig` erhält einen eigenen Bereich „Dateien" mit Mehrfach-Datei-Feld,
außerhalb des Editor-Formulars — HTML erlaubt keine verschachtelten Formulare.

Dateien werden ausschließlich auf der Edit-Seite verwaltet, über drei eigene
Formular-Routen in der Manage-Gruppe:

- `POST /newsletters/{id}/attachments` — hochladen
- `POST /newsletters/{id}/attachments/{attachment_id}/mode` — Zustellweg umschalten
- `POST /newsletters/{id}/attachments/{attachment_id}/delete` — entfernen

Nicht über `store()` und `update()`: beide sind reine JSON-Endpunkte. `store()` läuft
aus einem Modal-Dialog und leitet sofort in den Editor weiter, `update()` ist der
Autosave des Editors und feuert mehrfach je Sitzung — Dateien dort mitzuschicken
hieße, sie bei jedem Autosave erneut hochzuladen.
- Die Edit-Seite listet vorhandene Anhänge mit Name, Größe, Umschalter
  **Anhang / Link** und Löschen-Knopf.

Upload läuft über `EntityAttachmentService` mit `UploadValidator`; dessen Grenzen
(2 MB Bild, 10 MB Dokument, 30 MB Audio) bleiben die Obergrenze für den Upload selbst.

Vorbelegung des Modus beim Upload: `file_size < 2 MB` → `attach`, sonst `link`.
Frei umschaltbar — auch ein 5-MB-PDF darf die Redaktion bewusst anhängen, solange
das Gesamtlimit hält.

Ist `status = 'sent'`, werden Upload, Löschen und Moduswechsel abgelehnt (Flash und
Redirect, wie die übrige Entwurfssperre). Verlinkte Dateien bleiben abrufbar, sonst
zeigten bereits zugestellte Mails ins Leere.

## Versand

`NewsletterService::send()` prüft **vor** dem Status-Claim, ob die Summe der Anhänge
mit `delivery_mode = 'attach'` 10 MB übersteigt, und wirft sonst
`NewsletterAttachmentsTooLargeException`. Vor dem Claim, damit ein abgelehnter Versand
den Entwurf unangetastet lässt.

Das Queue-Schema bleibt unverändert: `payload_json.newsletter_id` steht dort bereits,
der Worker lädt die Dateien zur Sendezeit nach. Eine Kopie des BLOBs je Empfängerzeile
wäre bei 80 Empfängern und einem 3-MB-PDF eine Viertelgigabyte in `mail_queue`.

- `MailDeliveryService::sendEntry()` lädt bei `mail_type = 'newsletter'` die
  `attach`-Anhänge des Newsletters und reicht sie an den Mailer weiter.
- `Mailer::sendHtmlMailDetailed()` und `buildMimeMessage()` erhalten einen optionalen
  Parameter `array $attachments = []` mit `['content', 'name', 'mime']`;
  `composeMessage()` hängt sie per `addStringAttachment()` an. Das dort bereits
  vorhandene `clearAttachments()` deckt das Zurücksetzen zwischen zwei Mails ab.
- Die Testmail nimmt denselben Weg: `enqueueNewsletterTestMail()` trägt die
  `newsletter_id` in den Payload.

## Link-Block in der Mail

`NewsletterMailRenderer::renderHtml()` erhält die `link`-Anhänge als Liste
(Name, Größe, URL `/attachments/{id}/download`). `templates/emails/newsletter.twig`
rendert daraus unter dem Inhalt einen Abschnitt „Dateien".

Echte Anhänge erscheinen im Block nicht — sie hängen an der Mail.

Vorschau und Versand laufen durch denselben Renderer, der Block kann also nicht
auseinanderlaufen.

## Zugriff

Regel: **Wer den Newsletter ansehen darf, darf auch seine Dateien laden.**

Diese Regel steht heute als privater Helfer in `NewsletterController`
(`canManageNewsletters()` oder eine Zeile in `newsletter_archive`). Sie zieht in eine
neue `App\Policies\NewsletterPolicy::canView(int $newsletterId): bool` um, die sowohl
der Controller als auch die `AttachmentAccessRegistry` nutzt. Zwei Kopien derselben
Zugriffsregel liefen sonst auseinander, sobald eine von beiden angepasst wird.

`AttachmentAccessRegistry::mayAccess()` bekommt den Zweig:

```php
'newsletter' => $this->moduleEnabled('newsletter') && $this->newsletterPolicy->canView($entityId),
```

Das `default => false` der Registry bleibt unangetastet.

## Tests

Feature:

- Upload am Entwurf legt eine `attachments`-Zeile mit `entity_type = 'newsletter'` an
- Modus wird nach Größe vorbelegt (klein → `attach`, groß → `link`)
- Upload, Löschen und Moduswechsel an einem versendeten Newsletter werden abgelehnt
- Gesamtlimit über 10 MB bricht den Versand ab und lässt `status = 'draft'` stehen
- die eingereihte Mail trägt den Anhang — nachgewiesen über `Mailer::buildMimeMessage()`
- der Block „Dateien" steht im gerenderten Mail-HTML und verlinkt die `link`-Anhänge
- Download durch jemanden ohne Newsletter-Zugriff endet auf 404

Unit:

- Vorbelegungsregel an der Schwelle (knapp unter und knapp über 2 MB)
- Summenprüfung zählt nur `attach`, nicht `link`

## Seed

`DevSeedService`: ein Newsletter erhält zwei Anhänge — ein kleines PDF als `attach`,
eine größere Datei als `link`. `attachments`-Zeilen mit `entity_type = 'newsletter'`
gehören in `resetSeedData()`, der Zähler in den Bericht in `run()`.

## Bewusst nicht enthalten

- Konfigurierbare Schwellen in den App-Einstellungen — zwei Konstanten reichen, bis
  jemand einen zweiten Wert braucht
- Reihenfolge der Anhänge
- Wiederverwendung einer Datei über mehrere Newsletter
- Token-Links für externe Empfänger: alle Empfängerquellen
  (`project_members`, `event_attendees`, `role`, `user`) sind interne Konten
