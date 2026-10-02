# Entwurf: Zielgruppen-Filter mit UND-Verknüpfung

> Stand: 2026-10-02. Freigegeben im Gespräch, Abschnitt für Abschnitt.

## Anlass

Freigaben in der Dateiverwaltung haben heute genau ein Ziel: eine Rolle, eine
Stimmgruppe, die Mitglieder eines Projekts, ein Mitglied oder alle. Mehrere
Freigaben eines Ordners oder einer Datei sind ODER-verknüpft.

„Nur die Soprane im Projekt XY“ lässt sich damit nicht ausdrücken. Wer
„Projekt XY“ und „Stimmgruppe Sopran“ freigibt, öffnet den Ordner für alle
Projektmitglieder und zusätzlich für alle Soprane des Chors. Bleibt nur, die
Personen einzeln freizugeben – und wer später ins Projekt kommt, fehlt.

## Ziel

Eine Freigabe ist ein **Filter** aus Bedingungen in mehreren Kategorien:

- innerhalb einer Kategorie genügt **einer** der genannten Werte (ODER),
- zwischen den Kategorien müssen **alle** zutreffen (UND),
- ein Filter ohne Bedingung trifft alle Mitglieder.

Mehrere Freigaben eines Ordners oder einer Datei bleiben untereinander ODER.
Stufen (Lesen bis Verwalten bei Ordnern, Lesen/Bearbeiten bei Dateien),
Vererbung auf Unterordner, Papierkorb-Regeln und das Recht „Dateiverwaltung
verwalten“ bleiben unverändert.

Beispiel: „Stimmgruppe: Sopran, Alt · Projekt: Frühjahrskonzert“ trifft
Soprane und Alt-Stimmen, die im Projekt sind – sonst niemanden.

## Entscheidungen

| Frage | Entscheidung |
| --- | --- |
| Umfang | Gemeinsamer Baustein; in diesem Schritt nur an Ordner- und Dateifreigaben angeschlossen |
| Ausschlüsse | Keine. Nur UND/ODER wie oben, kein NICHT, kein Entzug |
| Aufbau | Filter je Kategorie (ODER innen, UND zwischen Kategorien) |
| Kategorien | Rolle, Stimmgruppe, Untergruppe, Projekt, Mitglied |
| Projekte in der Auswahl | Nur laufende und künftige; bestehende Bedingungen auf beendete Projekte bleiben gültig |
| Speicherung | Eigene Tabellen für Filter und Bedingungen, Freigaben verweisen darauf |

Verworfen:

- **JSON-Spalte an der Freigabe** – ohne Fremdschlüssel blieben gelöschte
  Bezüge als tote Werte stehen, und Termine und Newsletter bekämen später
  jeweils eigene JSON-Spalten statt eines gemeinsamen Bausteins.
- **Zeilen der heutigen Tabellen per Gruppennummer bündeln** – vermischt Stufe
  und Bedingungen, nicht wiederverwendbar.
- **Fester Typ „Stimmgruppe im Projekt“** – deckt nur diesen einen Fall.
- **NICHT-Bedingungen** – machen „wer sieht das?“ schwer prüfbar; kein
  konkreter Bedarf.

## 1. Datenmodell und Umstellung

### Neue Tabellen

`audience_filters`

| Spalte | Typ |
| --- | --- |
| `id` | int unsigned, PK |
| `created_at` | datetime |

`audience_filter_conditions`

| Spalte | Typ |
| --- | --- |
| `id` | int unsigned, PK |
| `audience_filter_id` | int unsigned, FK → `audience_filters.id`, `ON DELETE CASCADE` |
| `category` | enum `role`, `voice_group`, `sub_voice`, `project`, `user` |
| `reference_id` | int |

Unique-Index auf (`audience_filter_id`, `category`, `reference_id`).

`reference_id` bekommt **keinen** Fremdschlüssel – die Bedingung zeigt je nach
Kategorie auf fünf verschiedene Tabellen. Wird ein Bezug gelöscht, bleibt die
Bedingung stehen und trifft niemanden mehr (siehe Abschnitt 2).

### Freigabe-Tabellen

`file_folder_shares` und `file_shares`:

- neu: `audience_filter_id` (int unsigned, FK → `audience_filters.id`),
- entfällt: `target_type`, `reference_id` und der Unique-Index
  `uniq_file_folder_shares_target` bzw. `uniq_file_shares_target`.

Jede Freigabe besitzt **genau einen eigenen** Filter; Filter werden nicht
geteilt. Beim Löschen oder Ersetzen einer Freigabe löscht der Service ihren
Filter mit (ein Fremdschlüssel kann das nicht, weil die Freigabe auf den
Filter zeigt). Doppelte Freigaben – gleiche Menge an Bedingungen am selben
Ordner bzw. derselben Datei – legt der Service zusammen, die höhere Stufe
bleibt.

### Migration

Eine Phinx-Migration nach `/phinx-migration`:

1. `audience_filter_id` anlegen, zunächst `NULL` erlaubt.
2. Je bestehender Freigabe einen Filter anlegen und die Bedingung übertragen:

   | alt `target_type` | neu |
   | --- | --- |
   | `role` | `role` |
   | `voice_group` | `voice_group` |
   | `user` | `user` |
   | `project_members` | `project` |
   | `all_members` | keine Bedingung |

3. **Prüfung vor dem destruktiven Schritt:** Gibt es noch eine Freigabe ohne
   Filter, bricht die Migration mit `RuntimeException` ab – bevor eine Spalte
   fällt.
4. `audience_filter_id` auf `NOT NULL` setzen, Fremdschlüssel anlegen,
   `target_type`, `reference_id` und die Unique-Indizes entfernen.

`down()`:

1. **Prüfung zuerst:** Hat ein Filter mehr als eine Bedingung oder eine
   Bedingung der Kategorie `sub_voice`, gibt es im alten Modell keine
   Entsprechung – Abbruch mit `RuntimeException` und klarer Meldung.
2. `target_type` und `reference_id` wieder anlegen und aus den Bedingungen
   zurückschreiben (`project` → `project_members`, keine Bedingung →
   `all_members`, Bezug 0).
3. Unique-Indizes wieder anlegen, `audience_filter_id` entfernen, Filter-
   Tabellen löschen.

Jede Phinx-Kette endet mit `create()`/`save()`/`update()`
(`MigrationChainCompletionTest`).

## 2. Auswertung

### `App\Services\Audience\AudienceFilterService`

Nicht an die Dateiverwaltung gebunden.

- `profileOf(int $userId): MemberProfile` – Rollen, Stimmgruppen,
  Untergruppen (aus `user_voice_groups.sub_voice_id`), Projekte (aus
  `project_users`) und die eigene Kennung. Einmal je Prüfung geladen.
- `matchingFilterIds(MemberProfile $profile, array $filterIds): array` –
  welche der übergebenen Filter auf das Mitglied passen. Ein Filter passt,
  wenn das Mitglied in **jeder** Kategorie, die der Filter verwendet,
  mindestens einen der genannten Werte hat. Ein Filter ohne Bedingung passt
  immer. Eine Abfrage lädt die Bedingungen aller übergebenen Filter;
  ausgewertet wird in PHP.
- `membersQuery(int $filterId)` – Abfrage auf die aktiven Mitglieder
  (`users.is_active = 1`), die der Filter trifft. Heute für die Trefferzahl,
  später für Termine und Newsletter.

`MemberProfile` ist ein schlichtes, unveränderliches Wertobjekt.

### Anschluss in `FileAccessService`

Die gemeinsame Abgleichsfunktion für Ordner- und Dateifreigaben
(`matchingLevels`) lädt die Freigaben mit Kennung, Stufe und
`audience_filter_id`, fragt einmal `matchingFilterIds` und bildet daraus wie
bisher die höchste Stufe je Ordner bzw. Datei. Alles darüber – Vererbung,
Papierkorb, Einstiegspunkte unter „Mit mir geteilt“, Dateistufe,
„Dateiverwaltung verwalten“ – bleibt unverändert.

### Randfälle

- **Gelöschter Bezug** (Rolle, Stimmgruppe, Untergruppe, Projekt, Mitglied):
  der Wert trifft niemanden. Bleibt in einer Kategorie kein gültiger Wert,
  trifft der Filter niemanden – er sperrt, statt zu öffnen.
- **Inaktive Mitglieder** können sich nicht anmelden; `membersQuery` zählt sie
  nicht.
- **Untergruppe ohne Stimmgruppe im Filter** ist erlaubt: „Untergruppe:
  Sopran 1“ trifft genau Sopran 1.

## 3. Oberfläche

Ein gemeinsames Partial für den Freigabe-Dialog des Ordners und den
Freigabe-Bereich der Dateiseite.

### Freigabe-Zeile

- **Kopf:** Zusammenfassung („Stimmgruppe: Sopran, Alt · Projekt:
  Frühjahrskonzert“), Trefferzahl („trifft derzeit 14 Mitglieder“), Stufe,
  **×**.
- **Aufgeklappt:** fünf Mehrfachauswahlen (TomSelect, lokal unter
  `public/vendor`):
  - Rolle,
  - Stimmgruppe in der Reihenfolge Sopran, Alt, Tenor, Bass,
  - Untergruppe, nach Stimmgruppe gruppiert in derselben Reihenfolge, darin
    alphabetisch,
  - Projekt – nur laufende und künftige; ein schon gewähltes beendetes
    Projekt bleibt in der Liste und ist als „beendet“ markiert,
  - Mitglied.
- Hinweis unter den Feldern: „Mehrere Werte in einem Feld: eines davon
  genügt. Mehrere Felder: alle müssen zutreffen.“

### „Alle Mitglieder“

Eigenes Häkchen. Es sperrt die fünf Felder. Eine Zeile ohne Bedingung und
ohne Häkchen lehnt der Server mit Meldung ab – eine halb ausgefüllte Zeile
darf nicht versehentlich den ganzen Chor freigeben.

### Trefferzahl

Bei jeder Änderung fragt das Skript (entprellt) `POST /files/audience-preview`
und erhält nur `{"count": n}`. Der Endpunkt steht nur offen, wer
„Dateiverwaltung verwalten“ hat oder in mindestens einem Ordner die Stufe
Verwalten. Eine Zeile mit 0 Treffern wird gelb markiert; speichern bleibt
möglich (etwa für ein Projekt, das sich erst füllt).

### Formular

`shares[i][level]`, `shares[i][all]`,
`shares[i][conditions][role][]`, `…[voice_group][]`, `…[sub_voice][]`,
`…[project][]`, `…[user][]`.

`App\Services\Audience\AudienceFilterNormalizer` prüft Kategorien und
Kennungen (nur existierende), sortiert, entfernt Doppelte und erkennt gleiche
Bedingungsmengen.

### Anzeige anderswo

- Geerbte Freigaben im Ordner-Dialog: dieselbe Zusammenfassung.
- Ohne JavaScript bleiben die Felder einfache `<select multiple>`; Speichern
  funktioniert, Trefferzahl und Aufklappen entfallen.

## 4. Tests, Seed, Hilfe

### Tests

Testgetrieben, jeder Test zuerst rot.

- `tests/Unit/Services/Audience/AudienceFilterServiceTest` bzw. Feature-Test
  mit Datenbank: ODER innerhalb, UND zwischen Kategorien; leerer Filter trifft
  alle; jede der fünf Kategorien; Untergruppe allein; gelöschter Bezug sperrt;
  `membersQuery` nur aktive Mitglieder.
- `AudienceFilterNormalizerTest`: unbekannte Kategorien und Kennungen fallen
  weg; sortiert, ohne Doppelte; gleiche Bedingungsmengen werden zusammengelegt;
  Zeile ohne Bedingung und ohne „Alle Mitglieder“ wird abgelehnt.
- Migrationstest: alle fünf alten Zieltypen werden richtig übertragen;
  Prüfung bricht vor dem destruktiven Schritt ab, wenn eine Freigabe ohne
  Filter übrig wäre; `down()` geht bei einfachen Filtern und verweigert bei
  mehreren Bedingungen bzw. Untergruppe.
- Bestehende Tests der Dateiverwaltung (`FileAccess`, `FileShareAccess`,
  `FileFolderService`, `FileShareService`, Controller-Tests) auf Filter
  umgestellt, Aussagen unverändert; je ein neuer Fall „Sopran UND Projekt XY“:
  Soprane außerhalb des Projekts sehen nichts, Alt-Stimmen im Projekt auch
  nicht.
- Vorschau-Endpunkt: nur Anzahl, kein Name; ohne Verwalten-Recht abgelehnt.
- Schärfeprobe: UND zu ODER umgebaut, Häkchen-Pflicht entfernt,
  Migrationsprüfung entfernt – jede Sabotage läuft rot.

### Seed

- Bestehende Freigaben laufen über Filter.
- Neu: Ordner „Stimmproben Frühjahrskonzert“ mit „Stimmgruppe Sopran ·
  Projekt (laufendes)“; eine Datei mit „Untergruppe Alt 2 · Rolle Mitglied“.
- `audience_filters` und `audience_filter_conditions` in `resetSeedData()`
  und als Zähler im Bericht.

### Hilfe

Keine neue Seite (AGENT.md, „Hilfetexte“). Die bestehende Seite „Ordner
freigeben“ (`help/files/docs/files-sharing.md`) beschreibt die alte Auswahl
und bekommt deshalb nur einen kurzen Hinweis, dass sich die Auswahl geändert
hat. Die Überarbeitung folgt auf Anforderung.

## Bewusst nicht enthalten

- Anschluss von Termin-Zielgruppen (`event_audience_sources`) und
  Newsletter-Empfängern (`newsletter_recipient_sources`) – späterer Schritt
  über denselben Baustein.
- NICHT-Bedingungen und Entzug.
- Benannte, wiederverwendbare Zielgruppen („Sopran Frühjahrskonzert“ an
  mehreren Ordnern). Das Modell lässt sie zu: ein Filter bräuchte einen Namen
  und dürfte von mehreren Freigaben genutzt werden.
- WebDAV-Zugriff auf Teamordner.

## Verifikation

```bash
ddev exec ./vendor/bin/phinx migrate
ddev php vendor/bin/phpunit --filter "Audience|File"
ddev composer test:parallel
ddev composer phpcs
ddev composer twigcs
ddev exec env APP_ENV=development ALLOW_DEV_SEED=1 php bin/dev_seed.php --mode=reset-and-seed
```

Seed-Bericht: `audience_filters` und `audience_filter_conditions` > 0.
Migration einmal zurück und wieder vor (`phinx rollback` / `migrate`) auf
einem Bestand mit einfachen Freigaben.
