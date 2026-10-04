# Entwurf: Zielgruppen-Filter für Termine, Newsletter und Vorlagen

> Stand: 2026-10-04. Freigegeben im Gespräch, Abschnitt für Abschnitt.
> Baut auf `2026-10-02-audience-filters-design.md` auf.

## Anlass

Seit dem 2026-10-02 sind Datei-Freigaben Zielgruppen-Filter: innerhalb einer
Kategorie ODER, zwischen den Kategorien UND, mehrere Filter untereinander ODER.
Termine, Newsletter und Newsletter-Vorlagen wählen ihre Zielgruppe noch über
Quellen, die alle untereinander ODER verknüpft sind. „Nur die Soprane im
Projekt XY“ lässt sich dort nicht ausdrücken.

## Ziel

Termine, Newsletter und Vorlagen bekommen dieselbe Zielgruppen-Auswahl wie die
Freigaben: eine Liste von Filter-Zeilen mit den Kategorien Rolle, Stimmgruppe,
Untergruppe, Projekt, Mitglied und dem Häkchen „Alle Mitglieder“. Gleichzeitig
wird die Anbindung der Filter an ihre Besitzer vereinheitlicht – auch für die
Freigaben.

## Entscheidungen

| Frage | Entscheidung |
| --- | --- |
| Umfang | Termine, Newsletter, Newsletter-Vorlagen; Freigaben werden auf die neue Anbindung umgestellt |
| Newsletter-Quelle „Zielgruppe eines Termins“ | Bleibt als eigene Quelle neben den Filtern, ODER-verknüpft |
| „Keine Auswahl“ bei Terminen | Ausdrücklich: jede Zeile braucht eine Bedingung oder „Alle Mitglieder“; ein Termin braucht mindestens eine Zeile |
| „Keine Auswahl“ bei Newslettern | Wie heute: keine Empfänger; speichern erlaubt, Versand verlangt Empfänger |
| Anbindung | Besitzer-Spalten mit Fremdschlüssel an `audience_filters`, je Besitzerart eine Spalte |

Verworfen:

- **Zuordnungstabelle je Modul** (`event_audience_filters` …) – jedes weitere
  Modul brächte eine weitere Tabelle.
- **`owner_type`/`owner_id`** – ohne Fremdschlüssel bleiben Filter verwaist,
  sobald ein Besitzer per Massenabfrage oder Cascade gelöscht wird
  (`Event::whereIn(...)->delete()` beim Löschen einer Serie).
- **Benannte Zielgruppe als eigene Ebene** – kein Bedarf; Freigaben passen
  wegen ihrer Stufe je Zeile nicht hinein.
- **„Termin“ als sechste Filter-Kategorie** – wäre bei Freigaben sinnlos und
  bei Terminen zirkulär.

## 1. Datenmodell und Umstellung

### `audience_filters`

Neue Spalten, alle `NULL` erlaubt, jede mit Fremdschlüssel `ON DELETE CASCADE`
und Index:

| Spalte | zeigt auf |
| --- | --- |
| `event_id` | `events.id` |
| `newsletter_id` | `newsletters.id` |
| `newsletter_template_id` | `newsletter_templates.id` |
| `file_folder_share_id` | `file_folder_shares.id` |
| `file_share_id` | `file_shares.id` |

`CHECK`: genau eine Besitzer-Spalte ist gesetzt. Einen Filter ohne Besitzer
gibt es danach nicht mehr. Ein weiteres Modul braucht nur eine weitere Spalte
und eine Erweiterung des `CHECK`.

Gelöscht wird ausschließlich über den Cascade: Termin, Newsletter, Vorlage oder
Freigabe weg → Filter weg → Bedingungen weg. Das gilt auf jedem Weg, auch bei
Massenlöschung und Cascades von weiter oben (Ordner → Freigabe → Filter).

### Freigaben

Die Richtung dreht sich: Statt `file_folder_shares.audience_filter_id` bzw.
`file_shares.audience_filter_id` zeigt der Filter auf seine Freigabe.
`audience_filter_id` entfällt in beiden Tabellen. Das händische Mitlöschen der
Filter in `FileFolderService` und `FileTrashService` entfällt; der Cascade
übernimmt.

### Termine

`event_audience_sources` → je Quelle ein Filter mit genau einer Bedingung:

| alt `source_type` | neue Bedingung |
| --- | --- |
| `project_members` | `project` |
| `role` | `role` |
| `voice_group` | `voice_group` |
| `user` | `user` |

Ein Termin ohne Quelle bekommt einen Filter ohne Bedingung („alle“). Danach
entfällt `event_audience_sources` samt Model `EventAudienceSource`.

### Newsletter und Vorlagen

`newsletter_recipient_sources` und `newsletter_template_recipient_sources`:
Quellen `project_members`, `role`, `user` → je Quelle ein Filter wie oben.
`event_attendees` bleibt in der Tabelle; deren Enum schrumpft auf diesen einen
Typ. Ein Newsletter oder eine Vorlage ohne Quelle bekommt keinen Filter.

`newsletter_recipients` (aufgelöste Empfänger, bei versendeten Newslettern das
Versandprotokoll) bleibt unverändert.

### Migrationen

Je Modul eine Phinx-Migration nach `/phinx-migration`, jede in dieser Folge:

1. Besitzer-Spalte anlegen (Fremdschlüssel, Index).
2. Daten übertragen.
3. **Prüfung vor dem destruktiven Schritt:** Je Besitzer muss die Zahl der
   neuen Filter der Zahl der übertragenen Quellen entsprechen (Termine ohne
   Quelle: genau ein Filter ohne Bedingung; Freigaben: genau ein Filter je
   Freigabe). Sonst `RuntimeException`, bevor eine Spalte oder Tabelle fällt.
4. Alte Spalten bzw. Tabellen entfernen bzw. Enum verkleinern.

Eine letzte Migration legt den `CHECK` an, nachdem alle Filter einen Besitzer
haben; sie prüft das vorher und bricht sonst ab.

`down()` je Modul:

1. **Prüfung zuerst:** Gibt es einen Filter mit mehr als einer Bedingung oder
   einer Kategorie, die das alte Modell nicht kennt (Untergruppe überall;
   Stimmgruppe bei Newsletter und Vorlage), Abbruch mit `RuntimeException` und
   klarer Meldung.
2. Alte Struktur anlegen und zurückschreiben. Termin: ein Filter ohne Bedingung
   wird zu „keine Quelle“, sofern es sein einziger Filter ist; sonst Abbruch.
3. Filter des Moduls löschen, Besitzer-Spalte entfernen.

Jede Phinx-Kette endet mit `create()`/`save()`/`update()`
(`MigrationChainCompletionTest`).

## 2. Auswertung

### Gemeinsamer Baustein (`App\Services\Audience\`)

`AudienceFilterService` – die Regel steht weiter an einer Stelle:

- `membersQueryForOwner(string $column, int $ownerId)` – aktive Mitglieder, die
  mindestens ein Filter des Besitzers trifft. Besitzer ohne Filter: niemand.
- `matchingOwnerIds(MemberProfile $profile, string $column, ?array $ownerIds)` –
  welche Besitzer mindestens einen auf das Mitglied passenden Filter haben.
- `replaceForOwner(string $column, int $ownerId, array $conditionSets)` – alle
  Filter des Besitzers in einer Transaktion ersetzen.
- `conditionsForOwners(string $column, array $ownerIds)` – Bedingungsmengen je
  Besitzer, für Anzeige, Formular und Signaturen.

`$column` ist eine der Besitzer-Spalten; der Service prüft sie gegen eine feste
Liste.

`AudienceFilterNormalizer` bleibt; zusätzlich legt er mehrere Zeilen mit
gleicher Bedingungsmenge zu einer zusammen (bei Freigaben weiterhin mit der
höheren Stufe).

`FileShareDescriber` wird zu `AudienceDescriber`: Zusammenfassung
(„Stimmgruppe: Sopran · Projekt: XY“), Auswahllisten, gelöschte Werte – für
alle Module. Mehrere Filter eines Besitzers werden mit „ / “ getrennt.

### Termine

- `Event::eligibleUsersQuery()` bleibt die einzige Quelle der Wahrheit, baut auf
  `membersQueryForOwner('event_id', …)` auf und lädt weiter nur
  `User::LIST_COLUMNS`.
- `EventAudienceService::visibleEventsQuery()` über `matchingOwnerIds`.
- `AttendanceScopeService::canAccessEvent()` prüft das eigene Profil und die
  Profile der verwaltbaren Mitglieder **einzeln**. Merkmale verschiedener
  Personen werden nicht vermischt: „Sopran · Projekt X“ passt nicht, nur weil
  ein verwaltetes Mitglied Sopran ist und ein anderes in Projekt X.
- „Termin gehört zu Projekt X“ heißt: ein Filter des Termins hat die Bedingung
  Projekt X. Ein Scope am Event-Model (`forProject($projectId)`) ersetzt die
  `whereHas('audienceSources', …)`-Stellen in `Project::events()`,
  `EventController`, `EvaluationController` und Anwesenheit.
- `eligibleUserIdsForEvents()` bildet die Signatur aus den Bedingungsmengen;
  eine Serie braucht weiter eine Abfrage.
- `CalendarFeedService` beschriftet über `AudienceDescriber`.

### Newsletter

- Empfänger = Mitglieder der Filter ODER Zielgruppe der gewählten Termine
  (`event_attendees`, wie bisher über `Event::eligibleUsersQuery()`).
- Listenfilter „Empfängerart“ → Kategorien Rolle, Stimmgruppe, Untergruppe,
  Projekt, Mitglied, Termin: Newsletter mit einer Bedingung bzw. Quelle dieser
  Art.

### Trefferzahl

`POST /files/audience-preview` wird zu `POST /audience-preview`. Zugelassen:
wer Dateien verwalten darf (bisherige Regel), Termine bearbeiten darf oder
Newsletter bearbeiten darf. Antwort nur `{"count": n}`.
`/newsletters/resolve-recipients-preview` (Gesamtzahl) bleibt und nimmt das
neue Formularformat an.

### Randfälle

- Gelöschter Bezug: wie bei den Freigaben – trifft niemanden; eine Kategorie
  ohne gültigen Wert wird beim Speichern abgelehnt.
- Termin ohne Filter (nur durch Eingriff an der Datenbank möglich): trifft
  niemanden, nicht alle.
- Archiviertes Mitglied als Bedingung bleibt gespeichert und zählt erst nach
  Reaktivierung wieder.

## 3. Oberfläche

### Gemeinsames Partial und Skript

- `partials/audience/filter_row.twig` und `partials/audience/condition_select.twig`
  (aus `files/partials/share_row.twig` und `share_condition_select.twig`).
  Parameter: Feldname-Präfix (`shares`, `audience`), Index, gespeicherter
  Filter, Auswahllisten, optionaler Zusatz in der Kopfzeile (Stufe bei
  Freigaben).
- Aufbau unverändert: Kopfzeile mit Live-Zusammenfassung, Trefferzahl, ×;
  aufklappbar „Alle Mitglieder“, fünf Mehrfachauswahlen, Hinweis „Mehrere Werte
  in einem Feld: eines davon genügt. Mehrere Felder: alle müssen zutreffen.“
  Gelöschte und inaktive Werte bleiben sichtbar gewählt.
- „+ Zielgruppe hinzufügen“; neue Zeilen aus einem `<template>`.
- `public/js/file-audience.js` → `public/js/audience-filter.js` mit
  `data-audience-*`-Attributen: Aufklappen, Sperren, Zusammenfassung,
  Trefferzahl (entprellt), Zeilen hinzufügen/entfernen.
  `public/js/events-audience.js` und `sources_json` entfallen.
- Ohne JavaScript: `<select multiple>`, normaler Formularversand.

### Termine

Anlege-Modal (`events/index.twig`) und Bearbeiten (`events/edit.twig`):
Abschnitt „Zielgruppe“, Felder `audience[i][all]`,
`audience[i][conditions][<kategorie>][]`. Neuer Termin: eine Zeile mit
„Alle Mitglieder“. Ohne gültige Zeile lehnt der Server mit Meldung ab, die
Eingaben bleiben erhalten. Serien: „auf alle Termine der Serie anwenden“
unverändert, jeder Termin bekommt eigene Filter.

### Newsletter und Vorlagen

- Zeilen wie bei Terminen; ein neuer Newsletter startet ohne Zeile. Darunter
  das eigene Feld „Zielgruppe eines Termins“.
- Ein gewähltes Projekt im Kopf belegt beim Anlegen eine Zeile „Projekt: X“
  vor (ersetzt die Vorbelegung „Projektmitglieder“).
- Vorlage wählen: Der JSON-Endpunkt `/newsletters/template/{id}` liefert
  Bedingungsmengen und Termin-Kennungen; das Skript erzeugt die Zeilen.
- Badge mit Gesamtempfängerzahl bleibt.

### Anzeige anderswo

Kalender-Feed, die Spalte „Zielgruppe“ der Terminliste (bisher nur „Alle“ /
„Ausgewählt“) und die Detailansicht eines Newsletters zeigen die
Zusammenfassung des `AudienceDescriber`.

## 4. Tests, Seed, Hilfe

### Tests

Testgetrieben, jeder Test zuerst rot.

- **Baustein:** mehrere Filter eines Besitzers ODER; `matchingOwnerIds` je
  Besitzerart; `replaceForOwner` atomar; Besitzer löschen entfernt Filter und
  Bedingungen, auch per Massenlöschung; `CHECK` lehnt null und zwei Besitzer
  ab; unbekannte Besitzer-Spalte wird abgelehnt.
- **Migrationen je Modul:** jede alte Quellart richtig übertragen; Termin ohne
  Quelle → „alle“; `event_attendees` bleibt; Prüfung bricht vor dem
  destruktiven Schritt ab; `down()` gelingt bei einfachen Filtern, verweigert
  bei kombinierten bzw. unbekannten Kategorien. Freigaben: Richtung gedreht,
  Zuordnung erhalten.
- **Termine:** „Sopran UND Projekt X“ – sichtbar, berechtigt und im
  Kalender-Feed nur für Soprane im Projekt; `canAccessEvent` vermischt keine
  Merkmale verwalteter Mitglieder; Projektzuordnung über die Bedingung;
  Speichern ohne gültige Zeile abgelehnt; Serie mit eigenen Filtern je Termin.
- **Newsletter/Vorlagen:** Empfänger = Filter ODER Termin-Zielgruppe; Vorlage →
  Newsletter übernimmt Filter; Listenfilter je Kategorie; ohne Zeile keine
  Empfänger.
- **Freigaben:** bestehende Tests umgestellt, Aussagen unverändert.
- **Vorschau-Endpunkt:** nur Zahl; ohne eines der drei Rechte abgelehnt.
- **Bestehende Tests** (Termine, Anwesenheit, Anmeldung, Auswertung,
  Newsletter, Kalender, Schema-/Index-Tests) aufs neue Modell umgestellt.
- **Schärfeprobe:** UND zu ODER; Vermischung in `canAccessEvent`; `CHECK`
  entfernt; Migrationsprüfung entfernt – jede Sabotage läuft rot.

### Seed

- Alle Module über Filter.
- Neu: ein Termin „Sopran · Projekt (laufendes)“, ein Newsletter
  „Stimmgruppe Alt“.
- Zähler im Bericht: `audience_filters` je Besitzerart.

### Hilfe

Keine Überarbeitung (AGENT.md, „Hilfetexte“). Die Seiten zu Terminen und
Newslettern bekommen nur einen kurzen Hinweis, dass sich die Zielgruppen-Auswahl
geändert hat.

## Bewusst nicht enthalten

- Benannte, wiederverwendbare Zielgruppen.
- NICHT-Bedingungen.
- „Termin“ als Filter-Kategorie.
- Nachträgliches Ändern der Empfänger versendeter Newsletter.

## Verifikation

```bash
ddev exec ./vendor/bin/phinx migrate
ddev php vendor/bin/phpunit --filter "Audience|Event|Newsletter|File"
ddev composer test:parallel
ddev composer phpcs
ddev composer twigcs
ddev exec env APP_ENV=development ALLOW_DEV_SEED=1 php bin/dev_seed.php --mode=reset-and-seed
```

Migrationen einmal zurück und wieder vor (`phinx rollback` / `migrate`) auf
einem Bestand mit einfachen Quellen und Freigaben.
