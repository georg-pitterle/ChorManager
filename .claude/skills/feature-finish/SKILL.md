---
name: feature-finish
description: >
  Zieht nach einem umgesetzten Feature alles nach, was in ChorManager dazugehört, bevor es
  nach main geht: Tests, Seed-Daten, bestehende Hilfe samt Screenshots, E2E-Szenario,
  Stil-Prüfungen und ein Review mit behobenen Befunden. Use this skill whenever the user says
  "ziehe die hilfe und e2e nach", "hilfe nachziehen", "doku und e2e nachziehen", "passe die
  hilfe an", "feature abschließen", "zieh alles nach", "fixe alle findings" after a feature,
  or invokes /feature-finish — also when only one of these parts is named right after a
  feature was built. Übergibt am Ende an /git-commit, ersetzt es aber nicht. Nicht für ein
  neues Hilfethema ohne vorheriges Feature (/create-help-topic) und nicht für reine
  Commit-Wünsche (/git-commit).
---

# Feature abschließen

Ein Feature ist hier erst fertig, wenn Tests, Seed-Daten, Hilfe und E2E-Netz mitgezogen
sind. Dieser Skill ruft dafür die bestehenden Skills in fester Reihenfolge auf. Ihre Regeln
gelten weiter: Er bündelt nur die Aufrufe und ersetzt keinen davon.

**Der Aufruf ist die Anforderung.** AGENT.md erlaubt Hilfetexte nur auf ausdrückliche
Anforderung, die Memory erlaubt E2E-Läufe nur auf Nachfrage. Wer diesen Skill aufruft
(oder "hilfe/e2e nachziehen" schreibt), hat beides angefordert, aber nur für das betroffene
Feature. Ein **neues** Hilfethema wird erst nach einmaliger Rückfrage angelegt. Die **volle**
E2E-Suite läuft nur, wenn Georg sie verlangt.

## 1. Umfang feststellen

```powershell
git diff --stat main...HEAD; git status --short
```

Daraus ergibt sich eine Liste: berührte Controller/Routen, Templates, Migrationen, Rechte
(`templates/roles/index.twig`). Alles Weitere bezieht sich auf diese Liste und nicht auf das
ganze Projekt.

Dann prüfen, was der Branch **schon enthält**: Tests, Seed, Hilfe-Abschnitte, Screenshots,
E2E-Schritte zum Feature (`git diff --stat main...HEAD -- tests help`). Ist ein Schritt
schon erledigt, wird er nur noch belegt (Lauf grün, Inhalt passt zum Code) und nicht noch
einmal geschrieben. Läuft die Arbeit direkt auf `main` ohne Branch, zuerst einen Branch anlegen
(Memory: ein Branch je Thema).

## 2. Die Schritte

Der Reihe nach. Jeder Schritt endet mit einem Beleg (Befehl und Ergebnis), nicht mit "erledigt".

| # | Schritt | Wie | Fertig, wenn |
|---|---|---|---|
| 1 | Tests | `/writing-tests` für Lücken im Hauptweg und in den Fehlerfällen; laufen lassen nach `/test-runs`, zuerst gefiltert | gefilterter Lauf grün |
| 2 | Seed-Daten | `/dev-seed-completeness`; neue Rechte bekommt der Admin im Seed immer | Seed läuft durch, neue Daten sichtbar |
| 3 | Hilfe | betroffenes Thema finden: Zuordnung Seite → Slug in `tests/Feature/HelpButtonFeatureTest.php`, sonst `git grep` nach Seitentitel in `help/*/docs/`. Text in `help/<slug>/docs/*.md`, Screenshots über `help/<slug>/scripts/screenshot.js` nach `/create-help-topic` | `ddev php vendor/bin/phpunit --filter Help` grün, geänderte Screenshots angesehen |
| 4 | E2E | bestehendes Szenario des Moduls unter `tests/e2e/scenarios/` erweitern (Bausteine in `steps/`, Daten in `data/`), sonst neues nach `/e2e-scenario`; **nur dieses Szenario** laufen lassen | Einzellauf grün |
| 5 | Stil | `ddev composer phpcs`; bei Twig-Änderungen `ddev composer twigcs` | beide ohne Befund |
| 6 | Review | ein Review-Subagent über `git diff main...HEAD` (Korrektheit, Rechte/Sicherheit, Projektregeln); **alle** Befunde selbst beheben, keine Fix-Subagenten | jeder Befund behoben oder mit Begründung verworfen |
| 7 | Volle Suite | `ddev composer test:parallel`, einmal am Schluss | grün |

Neue Seite mit Hilfethema → Kopf-Knopf `help.button(slug, titel)` aus
`templates/macros/help.twig` und Eintrag im HelpButton-Wächter.

Bei einem Plan aus `docs/superpowers/plans/` die erledigten Checkboxen mit abhaken.

## 3. Bericht und Übergabe

Am Ende ein Bericht mit genau diesen Teilen:

1. **Nachgezogen:** je Schritt eine Zeile mit Dateien und Ergebnis (bei übersprungenen
   Schritten der Grund, z. B. "keine Hilfe betroffen").
2. **Ausgeführt:** die Prüfbefehle mit Ergebnis (Testanzahl, phpcs/twigcs, E2E-Szenario).
3. **Review-Befunde:** behoben / verworfen mit Grund.
4. **Offen:** nur, was eine Entscheidung von Georg braucht.

Danach `/git-commit`, wenn der Aufruf einen Commit enthielt ("… und commit auf main"),
sonst danach fragen. Nie pushen.

## Häufige Fehler

- Neues Hilfethema ungefragt anlegen, statt nur bestehende Themen nachzuziehen.
- Die ganze E2E-Suite starten, obwohl nur ein Szenario betroffen ist.
- Screenshots neu erzeugen, aber nicht ansehen. Leere Listen und Seed-Lücken fallen nur beim
  Hinsehen auf und führen zurück zu Schritt 2.
- Review-Befunde als "offen" melden, die sich ohne Entscheidung beheben lassen.
- Die volle PHPUnit-Suite nach jedem Schritt laufen lassen statt einmal am Schluss.
