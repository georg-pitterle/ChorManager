# Newsletter von der Projektmitgliedschaft entkoppeln

**Datum:** 2026-08-11
**Status:** freigegeben, bereit für Implementierungsplan

## Problem

Das Newsletter-Modul ist doppelt abgesichert: Das Recht `can_manage_newsletters` öffnet die Routen,
danach filtert `NewsletterController::getAccessibleProjects()` alles über `$user->projects()` – die
Projektmitgliedschaft der angemeldeten Person. Eine Admin-Ausnahme gibt es nicht; die einzige Klasse
mit Admin-Bypass (`src/Middleware/NewsletterAuthMiddleware.php`) ist nirgends registriert und damit
toter Code.

Daraus folgen drei Fehlverhalten:

- Wer das Recht hat, aber in keinem Projekt Mitglied ist, sieht ein leeres, funktionsloses Modul
  (`index()` rendert leer, `create()`/`store()` antworten 403).
- Ein Rundschreiben an eine Rolle oder an eine Einzelperson erzwingt trotzdem die Auswahl eines
  Projekts, weil `newsletters.project_id` NOT NULL ist.
- Vorlagen fremder Projekte sind gesperrt (`canAccessTemplateContext()`), obwohl es reine
  Textbausteine sind.

Zusätzlich verlangt bereits das *Speichern* eines Entwurfs mindestens eine Empfängerquelle, während
der *Versand* ohne Empfänger heute nur eine generische `Exception` auslöst, die als HTTP 500 mit der
Meldung „Fehler beim Versand." beim Nutzer ankommt.

## Zielbild

- Einzige Voraussetzung für Anlegen, Bearbeiten und Versenden eines Newsletters ist das Recht
  **„Newsletter verwalten"** (`can_manage_newsletters`).
- Der Projektbezug ist eine optionale Zuordnung, keine Zugangsbedingung.
- Ein Newsletter kann nicht versendet werden, wenn er keine Empfänger hat.

## Entscheidungen

| Frage | Entscheidung |
|-------|--------------|
| Projektbezug | `newsletters.project_id` wird nullable. Newsletter ohne Projekt ist zulässig. |
| Listenfilter | Projektfilter gilt für beide Status, Vorgabe „Alle Projekte", zusätzlich „Ohne Projekt". |
| Empfängerpflicht | Entwurf ohne Empfänger speicherbar; gesperrt wird nur der Versand, gemessen an der aufgelösten Personenzahl. |
| Vorlagen | Mit dem Recht sind alle Vorlagen sichtbar und nutzbar; der Kontext bleibt als Etikett und als Gruppierung im Auswahlfeld. |
| Umsetzungsweg | Gate ersatzlos entfernen **und** die Vorlagenverwaltung in einen eigenen Controller ausgliedern. |

## Datenmodell

Phinx-Migration:

- `newsletters.project_id` von `NOT NULL` auf `NULL` ändern.
- Fremdschlüssel `newsletters_ibfk_1` neu anlegen mit `ON DELETE SET NULL` statt `ON DELETE CASCADE`.
  Beim Löschen eines Projekts bleibt die Versandhistorie erhalten und wird projektlos.
- `newsletter_templates.project_id` ist bereits nullable und bleibt unverändert.

`App\Models\Newsletter` bleibt unverändert; `project()` darf künftig `null` liefern, was alle
Aufrufer berücksichtigen müssen (in Twig bereits über `|default("-")` abgedeckt).

## Autorisierung

**Entfällt ersatzlos:**

- `getAccessibleProjects()` wird zu `selectableProjects()` und liefert `Project::orderBy('name')->get()`.
- `getAccessibleProjectIds()`, `canAccessNewsletterById()`, `canAccessTemplateContext()` werden
  gelöscht, samt der zugehörigen 403-Zweige an ihren Aufrufstellen.
- `src/Middleware/NewsletterAuthMiddleware.php` wird gelöscht (toter Code).

**Bleibt und wird umgebaut:**

`/newsletters/archive` und `/newsletters/{id}/preview` liegen bewusst außerhalb der Rechte-Gruppe,
damit Empfängerinnen ohne Recht ihre eigenen Newsletter lesen können. `preview()` prüft künftig:

```
Recht can_manage_newsletters (aus $_SESSION) ODER eigener Eintrag in newsletter_archive
```

statt bisher „Projektmitglied oder Archiv-Eintrag". `canAccessReceivedNewsletterById()` bleibt dafür
erhalten. `archive()` bleibt unverändert personenbezogen.

**Folge:** Sichtbarkeit und Bearbeitbarkeit fallen zusammen. Wer das Recht hat, sieht und bearbeitet
jeden Entwurf. Die 30-Minuten-Sperre (`NewsletterLockingService`) bleibt unverändert der Schutz gegen
gleichzeitiges Bearbeiten.

## Versandregel

- `validateNewsletterSourcesInput()` akzeptiert eine leere Quellenliste. Entwurf und Autosave laufen
  damit auch ohne Empfänger durch. Ungültige Einzelquellen (gelöschtes Projekt, inaktive Person)
  werden weiterhin still verworfen.
- Maßgeblich ist die **aufgelöste Personenzahl**, nicht die Zahl der Quellen: Eine Rolle ohne aktive
  Mitglieder zählt als 0.
- `NewsletterService::send()` bricht bei 0 Empfängern mit einer benannten Regel ab. Der Controller
  antwortet **HTTP 422** mit „Newsletter hat keine Empfänger." statt der heutigen 500-Antwort
  „Fehler beim Versand.". Der Status bleibt `draft`, es wird nichts in die Mail-Warteschlange gestellt.
- Im Bearbeiten-Dialog ist der Versenden-Button deaktiviert, solange die Live-Empfängerzahl 0 ist.
  Die Serverprüfung bleibt zusätzlich bestehen.

## Oberfläche

**`templates/newsletters/index.twig`**

- Projektfilter gilt für beide Status; Einträge „Alle Projekte" (Vorgabe, leerer Wert) und
  „Ohne Projekt" (Wert `none`, serverseitig als `whereNull('project_id')` ausgewertet). Ein
  unbekannter Wert wird wie „Alle Projekte" behandelt.
- Verstecktes `project_id`-Feld und Vorbelegung auf das erste Projekt entfallen.
- Warnung „Keine Projekte verfuegbar." entfällt.
- Neue Spalte **Projekt**, bei projektlosen Newslettern „–".
- „Neuer Newsletter" wird ohne `project_id`-Parameter aufgerufen und ist unabhängig davon sichtbar,
  ob Projekte existieren (weiterhin nur bei Status „Entwürfe").

**`templates/newsletters/create.twig` und `edit.twig`**

- Projekt-Feld ohne `required`, mit Option „— kein Projekt —" (leerer Wert).
- Quelle „Projektmitglieder" listet alle Projekte.
- „Vorlage laden" zeigt alle Vorlagen, nach Kontext in `<optgroup>` gruppiert (Global zuerst, dann je
  Projekt). Die Gruppierung liefert der Controller als vorbereitete Struktur, nicht das Template.

**`public/js/newsletters-create.js` / `newsletters-edit.js`**

- Leeres `project_id` ist zulässig und wird als „kein Projekt" gesendet.
- `newsletters-edit.js` schaltet den Versenden-Button anhand der Empfängerzahl, die bereits über
  `POST /newsletters/resolve-recipients-preview` ermittelt wird. Kein neuer Endpunkt.

## Struktur

Neuer `src/Controllers/NewsletterTemplateController.php` übernimmt aus dem `NewsletterController`:

| Bisher | Neu | Route (unverändert) |
|--------|-----|---------------------|
| `listTemplates` | `index` | `GET /newsletters/templates` |
| `createTemplate` | `store` | `POST /newsletters/templates` |
| `editTemplate` | `edit` | `GET /newsletters/templates/{id}/edit` |
| `updateTemplate` | `update` | `POST /newsletters/templates/{id}` |
| `cloneTemplate` | `clone` | `POST /newsletters/templates/{id}/clone` |
| `getTemplate` | `show` | `GET /newsletters/template/{id}` |
| `saveAsTemplate` | `storeFromNewsletter` | `POST /newsletters/{id}/save-as-template` |

Abhängigkeiten des neuen Controllers: `Twig`, `HtmlSanitizer`, `NewsletterTemplateQuery`,
`NewsletterTemplatePersistence`. Die URLs bleiben unverändert, nur die Routenziele in
`src/Routes.php` zeigen auf den neuen Controller. Der `NewsletterController` behält Übersicht,
Entwurf, Versand, Sperre, Archiv und Vorschau und schrumpft von 1159 auf etwa 600 Zeilen.

## Tests

Vorgehen nach TDD: erst der fehlschlagende Test, dann die Implementierung.

Neu oder angepasst in `tests/Feature/`:

1. Person mit Recht, ohne jede Projektmitgliedschaft: Übersicht lädt mit Inhalt, Anlegen, Bearbeiten
   und Versenden sind erlaubt.
2. Newsletter ohne Projekt: anlegen, speichern, versenden; erscheint im Archiv der Empfänger.
3. Entwurf ohne Empfängerquelle speichern → erfolgreich (kein 422 mehr).
4. Versand bei 0 aufgelösten Empfängern → 422, Status bleibt `draft`, kein Eintrag in der
   Mail-Warteschlange.
5. Vorschau: mit Recht → 200; ohne Recht, aber mit Archiv-Eintrag → 200; ohne beides → 403.
6. Vorlagen aus fremdem Projekt bearbeiten, klonen und laden → 200 (bisher 403).

`tests/Feature/NewsletterSecurityHardeningFeatureTest.php` prüft heute genau die wegfallenden
403-Antworten und wird auf die neue Regel umgeschrieben; die Vorschau-Abgrenzung aus Punkt 5 bleibt
darin der harte Kern. Die übrigen bestehenden Newsletter-Tests dienen als Regressionsnetz.

## Seed-Daten

`src/Services/DevSeedService::seedNewsletters()` erzeugt zusätzlich:

- einen projektlosen Entwurf und einen projektlos versendeten Newsletter (inklusive Archiv-Einträge),
- Entwürfe mit den Quellen `role` und `user`, damit alle vier Quelltypen abgedeckt sind.

Keine neuen Tabellen und keine neuen Zähler; die bestehenden Zähler `newsletters`,
`newsletter_recipient_sources`, `newsletter_recipients` und `newsletter_archive` decken die neuen
Datensätze mit ab.

## Dokumentation

`help/newsletter/docs/newsletter.md`, `newsletter-compose.md` und `newsletter-templates.md` werden
angepasst:

- Stolperfalle „Keine Projekte, keine Newsletter" entfällt.
- Projekt wird als optionale Zuordnung beschrieben.
- Versand-Sperre bei 0 Empfängern wird als Regel beschrieben.
- Vorlagen sind projektübergreifend nutzbar.

Betroffene Screenshots werden mit `help/newsletter/scripts/screenshot.js` neu aufgenommen.

## Abschlussschritte

1. `ddev exec ./vendor/bin/phinx migrate`
2. Feature-Tests ausführen
3. `ddev composer phpcs` und `ddev composer twigcs`
4. Dev-Seed-Lauf und Prüfung des Berichts
5. Screenshots und Hilfetexte aktualisieren

## Bewusst nicht enthalten

- Kein weiterer Rechte-Schalter (etwa „nur eigene Entwürfe bearbeiten"). Wer das Recht hat, darf alles.
- Keine Policy- oder Autorisierungsschicht: Nach der Entkopplung entscheidet sie nur noch
  „hat Recht → ja" und wäre reiner Ballast.
- Kein Löschen von Vorlagen. Das fehlt heute schon und ist ein eigenes Thema.
- Keine Änderung an Mail-Versand, Warteschlange oder Sperrmechanik.
