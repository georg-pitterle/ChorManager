# Naming (Bezeichner)

Gilt projektweit für **jeden** Bereich: `src/`, `templates/`, `public/`, `tests/`, `db/`, `bin/`,
`docs/`, `.claude/`, Konfigurationsdateien.

## Englisch: alles, was ein Bezeichner ist

- Datei- und Ordnernamen (inkl. Templates, Assets, Migrationen, Doku-Dateien, Testdateien)
- Klassen, Interfaces, Traits, Enums, Namespaces
- Methoden, Funktionen, Closures
- Variablen, Parameter, Konstanten, Enum-Cases
- Array-Schlüssel und Konfig-Keys, die im Code adressiert werden
- Datenbank-Tabellen, -Spalten und -Indizes
- Routen-Pfade und Route-Namen
- CSS-Klassen, HTML-`id`s, `data-*`-Attribute, JS-Module und -Exporte
- Log-`event`-Keys, Feature-Flags, Env-Variablen

Keine deutschen Wörter, keine Mischformen (`getMitglieder`, `kassabuch_entry`, `termine.twig`) und
keine Transliterationen (`pruefung`, `groesse`) in Bezeichnern.

## Deutsch: alles, was Inhalt ist

- UI-Texte, Labels, Flash-Meldungen, E-Mail-Inhalte
- Hilfetexte und Doku-**Inhalt** (`docs/*.md`) — der Dateiname bleibt englisch
- Code-Kommentare und PHPDoc-Beschreibungen
- Test- und Szenario-**Beschreibungen** (`test('Kassabuch: ...')`) — der Dateiname bleibt englisch
- Seed- und Fixture-**Daten** (Namen, Betreffzeilen, Beschreibungen)

Deutscher Text nutzt immer echte Umlaute `ä ö ü ß`, nie `ae/oe/ue/ss`. Ausnahme: E-Mail-Adressen,
Passwörter und andere Werte, die technisch ASCII bleiben müssen.

Das gilt auch für **Commit-Nachrichten** — sie sind deutscher Fließtext wie Kommentare.

Durchgesetzt wird die Regel vom Hook `.claude/hooks/check-german-umlauts.sh`: er prüft vor jedem
`Write`, `Edit` und `git commit` gegen eine Liste transliterierter Wortstämme
(`.claude/hooks/check-german-umlauts.patterns`) und weist den Aufruf ab. Muss ein Wert technisch
ASCII bleiben, trägt **dieselbe Zeile** den Marker `naming:ascii` — der Hook prüft zeilenweise, ein
Marker in der Zeile darüber wirkt nicht.

## Fachbegriffe ohne direkte Übersetzung

Etablierten englischen Fachbegriff wählen (`cashbook`, `voiceGroup`, `attendance`, `dues`), nicht
das deutsche Wort durchreichen. Gibt es keinen, im Code-Kommentar kurz begründen.

## Bestandscode

Kein flächendeckendes Umbenennen. Wird eine Datei mit deutschem Namen ohnehin angefasst und sind
die Referenzen überschaubar, wird sie im selben Schritt umbenannt (`git mv`, Referenzen mitziehen).
