# Migration Joomla + Projekt-Excels nach ChorManager

Datum: 2026-07-26
Status: Entwurf zur Freigabe

## Ziel

Einmalige Übernahme des bestehenden Chor-Datenbestands nach ChorManager:

- Mitgliederstammdaten aus der Joomla-Installation (373 Accounts)
- Projekthistorie und exakte Stimmzuordnung aus 11 gewachsenen Projekt-Excels
- Ergebnis: `users`, `voice_groups`, `sub_voices`, `user_voice_groups`, `projects`, `project_users`

Keine Schemaänderung, keine neue Anwendungsfunktion. Der Code ist bewusst Wegwerf-Code
mit begrenzter Lebensdauer und liegt unter `bin/migration/`.

## Datenquellen

### Joomla

Export über phpMyAdmin nach `var/import/joomla_users.csv` (Präfix `ko7jk_`).
Spalten: `joomla_id`, `anzeigename`, `username`, `email`, `aktiv`, `registriert_am`,
`letzter_login`, `stimmgruppe`, `gruppen`.

Erkenntnisse aus der Analyse:

- Das Custom Field `Stimmgruppe` (`ko7jk_fields.id = 1`) ist eine Liste mit den
  Klartextwerten `Sopran`, `Alt`, `Tenor`, `Bass`. Keine Wertübersetzung nötig.
- Die Aktivität ergibt sich **nicht** aus `users.block`, sondern aus der Gruppen-
  zugehörigkeit: `aktive Chormitglieder` (ID 11, 86 Personen) gegenüber
  `ehemalige Chormitglieder` (ID 12, 286 Personen).
- Die Joomla-Gruppen `Chorleiter`, `Stimmsprecher`, `Administrator` und `Super Users`
  werden bewusst nicht ausgewertet. Rollen vergibt der Betreiber später in ChorManager.

### Projekt-Excels

11 Dateien in `var/import/`, in zwei Layout-Familien:

**Familie A – Spaltenliste** (2019, 2020, 2021, 2022 F, 2022 W)

Kopfzeile `Nr. | Vorname | Nachname | Stimmlage | Funktionen`. Vor- und Nachname sind
getrennt. `Stimmlage` enthält teilweise Mehrfachwerte wie `Bass/Tenor`. In den beiden
2022er-Dateien ist die Kopfzelle über der Vornamenspalte leer; die Spalte wird deshalb
über die Position relativ zu `Nachname` bestimmt, nicht über ihren Titel.

**Familie B – Blockliste** (2023 Strings, 2023/2024 Frankreich, 2024 MundART, 2025 Same
but different, 2025 W Elemente, 2026 Tag Nacht)

Spalte B trägt das Gruppenlabel (`Sopran 33`, `Alt 28`, …) ausschließlich in der ersten
Zeile eines Blocks; alle folgenden Zeilen des Blocks lassen sie leer. Spalte C enthält
den vollständigen Namen, Spalte D die Unterstimme als `1` oder `2`. Ein Block endet,
sobald Spalte B wieder gefüllt ist.

`2026 Tag Nacht.xlsx` hat zusätzlich Blätter je Stimmgruppe sowie ein Blatt `gesamt`.
Maßgeblich sind die Stimmgruppen-Blätter; `gesamt` ist redundant. Das mitgeführte Blatt
`W25 Elemente` ist eine Kopie des Vorjahres und wird übersprungen – Quelle für dieses
Projekt bleibt `2025 W Elemente.xlsx`.

### Stammdatenquelle

`Mitgliederverzeichnis.xlsx` (Kopfzeile `Nr. | Vorname | Nachname | Aktivität | Eintritt |
Austritt | Stimmlage | Funktionen | Projekte`) wird nicht als Projekt gewertet. Es dient
als Referenz für die korrekte Trennung von Vor- und Nachname bei 131 Personen und
verbessert damit die Namensauflösung.

Die drei `NeueMitglieder*.xlsx` bleiben unberücksichtigt.

## Projektliste

| Quelldatei | Projektname | Jahr |
|---|---|---|
| 2019 Projekt Radio_ World Winter Master Games_Mitglieder.xlsx | Radio / World Winter Master Games | 2019 |
| 2020 Projekt Zeit_Mitglieder_kein Konzert wegen Corona.xlsx | Zeit 2020 (ohne Konzert) | 2020 |
| 2021 Projekt Zeit_Mitglieder.xlsx | Zeit 2021 | 2021 |
| 2022 F Projekt Chorkuma goes to hollywood_Mitglieder.xlsx | Chorkuma goes to Hollywood | 2022 |
| 2022 W Projekt A-capella challenges.xlsx | A-capella Challenges | 2022 |
| 2023 Strings attached.xlsx | Strings attached | 2023 |
| 2023 und 2024 Frankreich und 10 Jahre.xlsx | Frankreich und 10 Jahre | 2023 |
| 2024 W Projekt MundART Brass.xlsx | MundART Brass | 2024 |
| 2025 Same but different .xlsx | Same but different | 2025 |
| 2025 W Elemente.xlsx | Elemente | 2025 |
| 2026 Tag Nacht.xlsx | Tag Nacht | 2026 |

`2023 und 2024 Frankreich und 10 Jahre` wird als **ein** Projekt angelegt.
`start_date` und `end_date` werden mit dem 1. Januar bzw. 31. Dezember des Jahres
vorbelegt und sind in der Arbeitsmappe korrigierbar.

## Ablauf

Zwei Skripte, jedes für sich wiederholbar.

### Schritt 1 – `bin/migration/build_workbook.php`

Liest alle Quellen und schreibt `var/import/migration.xlsx` mit fünf Blättern:

| Blatt | Inhalt |
|---|---|
| `Projekte` | Projektname, Jahr, Startdatum, Enddatum, Quelldatei, Teilnehmerzahl – editierbar |
| `Personen` | eine Zeile je erkannter Person: Excel-Name, Joomla-Treffer, Score, Status, E-Mail, Vorname, Nachname, Stimmgruppe, Unterstimme, Aktion. Stimmgruppe und Unterstimme sind hier der abgeleitete Zielwert aus dem jüngsten Projekt, in dem die Person vorkommt; die projektweise Zuordnung steht im Blatt `Teilnahmen`. |
| `Teilnahmen` | Projekt × Person × Stimmgruppe × Unterstimme (Rohzuordnung) |
| `Joomla` | Rohdaten des CSV-Exports zur Nachvollziehbarkeit |
| `Verworfen` | Zeilen, die als Nicht-Person eingestuft wurden, mit Begründung |

Die Spalte `Status` in `Personen` hat drei Werte:

- `OK` – exakter Treffer nach Normalisierung
- `PRUEFEN` – Ähnlichkeitstreffer, Vorschlag eingetragen, Score in Nachbarspalte
- `KEIN_TREFFER` – kein Joomla-Account gefunden

Die Spalte `Aktion` ist die Freigabe: `uebernehmen` oder `ueberspringen`.
Vorbelegt wird `uebernehmen` bei `OK` und `PRUEFEN`, `ueberspringen` bei `KEIN_TREFFER`.
Der Bearbeiter korrigiert direkt in der Datei; Änderungen an `E-Mail`, `Vorname`,
`Nachname` und `Aktion` gewinnen gegenüber den automatisch ermittelten Werten.

### Schritt 2 – Manuelle Durchsicht

Der Bearbeiter prüft die Blätter `Personen` und `Projekte`. Erwarteter Umfang laut
Vorabanalyse: 304 exakte Treffer, 44 Ähnlichkeitstreffer, 16 ohne Account.

### Schritt 3 – `bin/migration/import_workbook.php`

Liest die freigegebene Mappe und schreibt in die ChorManager-Datenbank.
Standardmodus ist `--dry-run`: es wird nur berichtet, was geschehen würde.
Erst `--commit` schreibt tatsächlich, in genau einer Transaktion.

## Namensabgleich

Normalisierung vor jedem Vergleich:

1. Klammerzusätze entfernen (`Kathrin Gietl (Projektausstieg)` → `Kathrin Gietl`)
2. Trimmen, Mehrfach-Leerzeichen zusammenfassen, Kleinschreibung
3. `ß` → `ss`, Diakritika entfernen (`Höck` und `Hoeck` werden vergleichbar)
4. Alles außer Buchstaben und Leerzeichen verwerfen
5. Wortreihenfolge normalisieren, damit `Nachname Vorname` ebenso trifft

Der Vergleich läuft in zwei Stufen: zuerst Gleichheit der normalisierten Form, danach
Ähnlichkeit über Levenshtein-Distanz. Ab einem Verhältnis von 0.86 gilt ein Treffer als
Vorschlag mit Status `PRUEFEN`, darunter als `KEIN_TREFFER`.

Nicht automatisch auflösbar und deshalb bewusst der manuellen Durchsicht überlassen sind
Kurzformen wie `Lisi` für `Elisabeth`, `Max` für `Maximilian` oder `Vreni` für `Veronika`.

Zeilen werden als Nicht-Person verworfen, wenn sie kürzer als vier Zeichen sind, keinen
Buchstaben enthalten, mit einer Stimmgruppenbezeichnung beginnen oder Schlüsselwörter wie
`gesamt`, `Sänger`, `Nachname` oder `Funktionen` tragen. Jede verworfene Zeile erscheint
mit Begründung im Blatt `Verworfen` – die Vorabanalyse hat auf diesem Weg etwa die Zeile
`war schon mal dabei!` erkannt.

## Zielabbildung

| Ziel | Quelle und Regel |
|---|---|
| `users.email` | Joomla-E-Mail. Ohne Adresse wird die Person übersprungen. |
| `users.first_name` / `last_name` | Aus `Mitgliederverzeichnis.xlsx`, sonst Aufteilung des Joomla-Anzeigenamens. Adelspartikel (`von`, `van`, `de`, `der`, `zu`) bleiben beim Nachnamen. |
| `users.password` | `password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT)` – kein nutzbares Kennwort. |
| `users.is_active` | `1` bei Joomla-Gruppe `aktive Chormitglieder`, sonst `0`. |
| `voice_groups` | `Sopran`, `Alt`, `Tenor`, `Bass`, angelegt sofern nicht vorhanden. |
| `sub_voices` | Je Stimmgruppe `Sopran 1` / `Sopran 2` usw., abgeleitet aus Spalte D der Familie B. |
| `user_voice_groups` | Stimmgruppe aus dem jüngsten Projekt, in dem die Person vorkommt; ersatzweise die Joomla-Stimmgruppe. Mehrfachangaben wie `Bass/Tenor` erzeugen zwei Zeilen. |
| `projects` | Aus der Projektliste oben bzw. dem Blatt `Projekte`. |
| `project_users` | Aus dem Blatt `Teilnahmen`, beschränkt auf Personen mit Aktion `uebernehmen`. |

Rollen (`user_roles`) werden nicht befüllt. Es werden keine Einladungen und keine
E-Mails verschickt; die Stimmvertretungen laden die Mitglieder später über die
bestehende Einladungsfunktion von ChorManager ein.

## Wiederholbarkeit

Beide Skripte sind mehrfach ausführbar. `import_workbook.php` arbeitet als Upsert:
Personen werden über die E-Mail-Adresse, Projekte über den Namen wiedererkannt.
Ein zweiter Lauf mit unveränderter Mappe darf keine Duplikate erzeugen.

## Bewusst nicht im Umfang

- keine Weboberfläche für den Import
- keine automatisierten Tests (bewusste Entscheidung des Auftraggebers für minimalen Umfang)
- keine Phinx-Migration, da kein Schema verändert wird
- keine Erweiterung der Dev-Seed-Daten, da keine neue Anwendungsfunktion entsteht
- keine Übernahme von Telefonnummern; das Schema sieht kein solches Feld vor
- keine Übernahme der Joomla-Kennwörter

## Abhängigkeit

`phpoffice/phpspreadsheet` wird als Entwicklungsabhängigkeit ergänzt
(`ddev composer require --dev phpoffice/phpspreadsheet`). Ohne sie lassen sich die
`.xlsx`-Quellen in PHP weder lesen noch schreiben.

## Sicherheit

`var/import/` liegt innerhalb des bereits vollständig ignorierten Verzeichnisses `var/`
und gelangt damit nicht in die Versionsverwaltung. Der Joomla-Export enthält bewusst
weder Kennwort-Hashes noch `otpKey` oder `otep`.
