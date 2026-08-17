# Newsletter-Platzhalter

Datum: 2026-08-18

## Ziel

Newsletter sollen personalisiert versendet werden. Redakteure schreiben Platzhalter wie
`{{vorname}}` in Inhalt und Betreff; beim Versand ersetzt die Anwendung sie pro Empfänger.

Heute gibt es kein Platzhalter-System: `NewsletterService::send()` sanitisiert `content_html`
einmal und reiht denselben Text für alle Empfänger in die Queue ein.

## Umfang

Enthalten sind Platzhalter aus drei Gruppen: Empfängerdaten, Newsletter-Kontext und
Organisationsdaten. Nicht enthalten sind dynamische Auswertungen pro Empfänger (nächster Termin,
offene Rückmeldungen, offene Aufgaben) — sie brauchen Queries je Empfänger und gehören in einen
eigenen Schritt.

Ersetzt wird in `newsletters.content_html` und `newsletters.title`. Der Vorlagen-Editor
(`NewsletterTemplate`) bekommt keine eigene Validierung oder Vorschau. Platzhalter aus einer
Vorlage landen im Newsletter-Inhalt und werden dort beim Versand aufgelöst.

## Syntax

`{{token}}`, deutsche Token-Namen. Erkennungsmuster: `/\{\{\s*([a-z_]+)\s*\}\}/u`. Innenabstände
sind erlaubt.

Die Token-Namen sind Inhalt, den Redakteure tippen, kein Bezeichner im Sinne von
`instructions/naming.md` — deshalb deutsch, passend zur restlichen Oberfläche.

## Variablen

| Token | Scope | Wert | Fallback bei leer |
|---|---|---|---|
| `{{anrede}}` | recipient | `Hallo Georg` | `Hallo` |
| `{{vorname}}` | recipient | `Georg` | leer |
| `{{nachname}}` | recipient | `Pitterle` | leer |
| `{{name}}` | recipient | via `NameFormatterService`, respektiert `name_display_format` | E-Mail-Adresse |
| `{{email}}` | recipient | `georg@example.at` | entfällt, Versand filtert leere Adressen |
| `{{stimmgruppe}}` | recipient | `Sopran (Sopran 1)`, mehrere komma-getrennt in Reihenfolge Sopran, Alt, Tenor, Bass; Untergruppen alphabetisch | `ohne Stimmgruppe` |
| `{{titel}}` | newsletter | Newsletter-Betreff | leer |
| `{{projekt}}` | newsletter | Projektname | leer |
| `{{datum}}` | newsletter | Versanddatum `18.08.2026`, in der Vorschau das aktuelle Datum | entfällt |
| `{{absender}}` | newsletter | Ersteller, formatiert | leer |
| `{{app_name}}` | global | aus `AppSetting`, siehe `Util/MailBranding.php` | `ChorManager` |
| `{{login_url}}` | global | Basis-URL via `Util/AppUrlResolver` | entfällt |
| `{{archiv_link}}` | global | `<a href="…/newsletters/{id}/preview">Im Browser ansehen</a>` | entfällt |

`{{name}}` fällt auf die E-Mail-Adresse zurück, weil ein Nutzer ohne Vor- und Nachnamen sonst als
leere Zeile erscheint.

Ein Nutzername existiert nicht: `users` kennt nur `email`, `first_name`, `last_name`, `is_active`.
Der Login läuft über die E-Mail-Adresse. `{{email}}` deckt den Fall ab.

Scopes steuern, wann ein Token auflösbar ist. `recipient` braucht einen Empfänger, `newsletter` nur
den Datensatz, `global` gar nichts. Eine Vorschau ohne gewählten Empfänger zeigt für
`recipient`-Token den Fallback statt eines Fehlers.

## Architektur

Neu: `App\Services\NewsletterPlaceholderService` mit einer Registry. Jeder Eintrag hält Key,
deutsches Label, Beschreibung, Scope, Fallback und Resolver. Die Registry ist die einzige Quelle für
vier Konsumenten: Rendering, TinyMCE-Auswahlliste, Validierung unbekannter Token und Hilfetext.

Verworfen wurden Twig als Engine (Sandbox-Härtung nötig, Syntaxfehler von Redakteuren brechen den
Versand mitten in der Empfängerschleife, unlesbare Fehlermeldungen) und eine `str_replace`-Map
direkt in `NewsletterService` (keine Metadaten, Liste an drei Stellen dupliziert).

### Öffentliche Schnittstelle

- `renderHtml(string $html, RenderContext $context, ?User $recipient): string`
- `renderSubject(string $subject, RenderContext $context, ?User $recipient): string`
- `definitions(): array` — Registry für Auswahlliste und Hilfe
- `findUnknownTokens(string $text): array` — Keys, die nicht in der Registry stehen

`RenderContext` löst alles Empfänger-Unabhängige einmal auf: `app_name`, Basis-URL, `projekt`,
`absender`, `datum`, `titel`.

## Rendering-Pfad

`MailQueueService::enqueueNewsletterMail()` nimmt Subject und Body bereits pro Empfänger entgegen.
Es braucht keine Schemaänderung und keine Änderung an der Queue-Verarbeitung.

In `NewsletterService::send()`:

1. Sanitizing von `content_html` bleibt einmalig vor der Schleife.
2. `RenderContext` einmal aufbauen.
3. Je Empfänger Body und Betreff getrennt rendern und enqueuen.

Gerendert wird beim Enqueue, nicht beim Abarbeiten der Queue. Die Queue bleibt frei von
Newsletter-Wissen.

## Sicherheit

Zwei Escaping-Modi, abhängig vom Zielkontext:

**HTML-Kontext (Body).** Jeder aufgelöste Wert läuft durch
`htmlspecialchars($value, ENT_QUOTES, 'UTF-8')`. Die Ersetzung passiert nach dem Sanitizing; ohne
Escaping käme ein Nachname `<script>alert(1)</script>` ungeprüft in die Mail. Einzige Ausnahme ist
`{{archiv_link}}`, das selbst sicheres Markup erzeugt.

**Header-Kontext (Betreff).** Keine HTML-Entities, sonst steht `Müller & Sohn` als `Müller &amp;
Sohn` in der Betreffzeile. Stattdessen werden `\r` und `\n` aus jedem Wert entfernt; ohne das
erlaubt ein manipulierter Name Header-Injection.

Die Vorschau eines fremden Empfängers ist autorisierungspflichtig, siehe unten.

`{{email}}` gibt in einer weitergeleiteten Mail eine Adresse preis. Das wird nicht technisch
verhindert, aber im Hilfetext benannt.

## Leere und unbekannte Werte

Leere Werte werden durch den in der Registry hinterlegten Fallback ersetzt, nie durch einen leeren
String, wenn dadurch ein kaputter Satz entsteht (`Hallo ,`).

Unbekannte Token bleiben im Text unverändert stehen und werden gemeldet: beim Speichern eines
Entwurfs und vor dem Versand als Warnung mit Auflistung der betroffenen Keys. Stilles Entfernen
würde Tippfehler unsichtbar machen.

## Archiv und Vorschau

Das Archiv rendert die Rohfassung live mit den Daten des Betrachters. Es wird keine gerenderte
Fassung gespeichert; das spart Migration und Speicher. Bewusst in Kauf genommen: Wechselt ein
Mitglied später die Stimmgruppe, zeigt das Archiv den neuen Wert und weicht damit von der
tatsächlich versendeten Mail ab.

`NewsletterController::preview()` bekommt einen optionalen Query-Parameter für den
Betrachtungs-Empfänger:

- Empfänger öffnet sein Archiv: gerendert mit seinen eigenen Daten, Parameter wird ignoriert.
- Wer Newsletter verwalten darf (`canManageNewsletters()`), ohne Parameter: gerendert mit den
  eigenen Daten, Hinweiszeile im Kopf der Vorschau.
- Wer Newsletter verwalten darf, mit Parameter: gerendert mit dem gewählten Empfänger, nur wenn
  dieser zu den
  aufgelösten Empfängern des Newsletters gehört. Andernfalls 403. Ohne diese Prüfung wird die Route
  zum Auskundschafter für beliebige Nutzerdaten.

## Oberfläche

**Einfügen.** `public/js/tinymce-init.js` bekommt einen Toolbar-Button „Platzhalter" mit
Auswahlliste, aufgebaut im bestehenden `setup`-Hook. Die Liste kommt von einem neuen
`GET /newsletters/placeholders`, das die Registry als JSON liefert: Label, Key, Beschreibung,
Beispielwert. Eingefügt wird per `editor.insertContent()` als reiner Text.

TinyMCE kann Formatierung innerhalb der Klammern einfügen, wenn jemand mitten im Token markiert und
fett setzt; aus `{{vorname}}` wird `{{<strong>vorname</strong>}}` und die Ersetzung greift nicht
mehr. Deshalb fügt die Auswahlliste reinen Text ein, und die Validierung warnt zusätzlich bei
Fast-Treffern wie `{{` ohne passendes Ende im selben Textknoten.

**Vorschau.** Neben dem Vorschau-Knopf steht ein Empfänger-Auswahlfeld, gespeist aus der
bestehenden Empfängerauflösung.

**Testmail.** `POST /newsletters/{id}/test-mail` rendert mit den Daten des Auslösenden und versendet
an dessen eigene Adresse. Die Adresse kommt aus der Session, nie aus dem Request. Es entstehen weder
`NewsletterRecipient`- noch `NewsletterArchive`-Zeilen, der Status bleibt `draft`. Die Sperr-Prüfung
gilt wie beim Versand.

## Leistung

`NewsletterRecipientService::getRecipients()` lädt heute `user`. Für `{{stimmgruppe}}` kommen
`user.voiceGroups` und `user.subVoices` ins Eager Loading, sonst entstehen zwei zusätzliche Queries
je Empfänger.

## Tests

Testgetrieben, jeder Fall zuerst rot.

`NewsletterPlaceholderFeatureTest`:

- jeder Token der Registry löst korrekt auf
- Fallback bei fehlendem Vornamen (`{{anrede}}` ergibt `Hallo`)
- Fallback bei fehlender Stimmgruppe
- Nachname mit `<script>` wird im Body escaped
- Betreff mit `&` bleibt unverändert, wird nicht zu `&amp;`
- Betreff mit eingeschleustem `\r\n` im Namen wird bereinigt
- unbekannter Token bleibt stehen und wird von `findUnknownTokens()` gemeldet
- Token mit Innenabständen (`{{ vorname }}`) wird erkannt

Erweiterung von `NewsletterFeatureTest`:

- Versand erzeugt je Empfänger unterschiedliche Queue-Bodies
- personalisierter Betreff landet in der Queue

Neue Fälle:

- Vorschau mit fremdem Empfänger-Parameter ohne Empfängerbezug ergibt 403
- Testmail geht an die eigene Adresse, erzeugt keine Empfänger- oder Archivzeilen, Status bleibt
  `draft`

E2E über `tests/e2e/steps/newsletters.mjs`: Platzhalter einfügen, Vorschau prüfen, versenden.

## Seed-Daten

Keine neuen Tabellen, daher keine Erweiterung von `resetSeedData()` und keine neuen Zähler. Die
Newsletter-Inhalte in `DevSeedService` bekommen echte Platzhalter, damit die Funktion in Dev sofort
sichtbar ist, darunter:

- ein Entwurf mit Platzhalter im Betreff
- ein Nutzer ohne Vornamen unter den Empfängern, damit der Fallback-Pfad live prüfbar ist

## Hilfetexte

`help/newsletter/docs/newsletter-compose.md` bekommt einen Abschnitt mit der Platzhalter-Tabelle,
dem Hinweis auf leere Werte und dem Hinweis zu `{{email}}` in weitergeleiteten Mails. Ohne Nennung
konkreter Rollennamen.
