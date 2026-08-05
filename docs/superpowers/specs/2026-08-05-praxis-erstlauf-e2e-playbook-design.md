# Praxis-Erstlauf E2E-Playbook — Design

**Datum:** 2026-08-05
**Status:** Design (freigegeben, wartet auf Spec-Review)

## Ziel

Den echten Praxis-Erstlauf von ChorManager wiederholbar durchspielen: leere
Datenbank → erster Admin → 8 Mitglieder (eins je Untergruppe, SATB × 2) →
ein Projekt. Faithful "wie in der Praxis": über die echte UI, nicht über
Seed-Bulk-Insert. Ergebnis ist ein dokumentiertes Runbook **und** ein
lauffähiges Playwright-Skript, die denselben Flow 1:1 abbilden.

## Nicht-Ziele

- Kein Ersatz für `DevSeedService` (Sinn hier ist der leere Start).
- Keine Abdeckung sämtlicher Module (Events, Finanzen, Newsletter …). Nur der
  Erstlauf-Kern: Admin, Stimmgruppen/Untergruppen, Mitglieder, Projekt.
- Keine CI-Integration in diesem Schritt (Skript ist lokal gegen ddev gedacht).

## Artefakte

1. **Runbook** — `docs/e2e/praxis-erstlauf-runbook.md`
   Markdown zum Nachlesen und manuellen Nachvollziehen. Enthält:
   Vorbedingungen, DB-Reset-Befehl, jeden UI-Schritt (Route + Formularfelder +
   erwartetes Ergebnis), Abschluss/Reset. Dient zugleich als Referenz für das
   Skript — Schritte im Runbook und im Skript bleiben deckungsgleich.

2. **Playwright-Skript** — `tests/e2e/praxis-erstlauf.e2e.test.mjs`
   Neuer Ordner `tests/e2e`, bewusst getrennt von den seed-abhängigen
   `tests/js/*.e2e.test.mjs`. Treibt die echte UI über
   `https://chormanager.ddev.site` mit Assertions je Schritt.

3. **DB-Reset-Wrapper** — `bin/fresh-db.sh`
   Kapselt den Reset, wird von Runbook und Playwright-Setup genutzt.

## DB-Reset (freigegeben: DROP/CREATE)

```bash
ddev mysql -e "DROP DATABASE IF EXISTS db; CREATE DATABASE db;"
ddev exec ./vendor/bin/phinx migrate
```

Ergebnis: leere, migrierte DB **ohne** User. Beim ersten `GET /` leitet die App
auf `/setup` (siehe `AuthController::showLogin`, Redirect wenn keine User
existieren). Kein `dev_seed`-Aufruf.

`bin/fresh-db.sh` führt genau diese zwei Befehle aus und bricht bei Fehler ab
(`set -euo pipefail`). LF-Zeilenenden.

## Flow (Skript == Runbook, identische Schritte)

| # | Route                              | Aktion                                                                 | Assertion                          |
|---|------------------------------------|-----------------------------------------------------------------------|------------------------------------|
| 1 | `GET /` → `/setup`, `POST /setup`  | Admin-Formular: Vorname, Nachname, E-Mail, Passwort → Admin + Rolle „Admin" | Weiterleitung auf `/login`         |
| 2 | `POST /login`                      | Als Admin einloggen                                                    | Dashboard sichtbar                 |
| 3 | `POST /voice-groups` ×4            | Sopran, Alt, Tenor, Bass (kanonische Reihenfolge)                      | 4 Gruppen in Liste, SATB-Ordnung   |
| 4 | `POST /voice-groups/{id}/sub` ×8   | Je Gruppe Untergruppe „1" und „2"                                      | 8 Untergruppen, je 2 pro Gruppe    |
| 5 | `POST /users` ×8                   | 1 Mitglied je Untergruppe, deutsche Namen, Stimm-/Untergruppe zugewiesen | 8 Mitglieder, korrekte Zuordnung   |
| 6 | `POST /projects`                   | 1 Projekt anlegen                                                      | Projekt in Projektliste sichtbar   |

Jeder Schritt läuft über echte UI-Interaktion (Formulare ausfüllen, Buttons
klicken), nicht über Raw-POST. Selektoren/Feldnamen werden bei der Umsetzung
aus den Templates verifiziert.

### Mitglieder-Daten (deterministisch)

Ein Mitglied je Untergruppe, deutsche Namen mit echten Umlauten:

| Untergruppe | Vorname  | Nachname   |
|-------------|----------|------------|
| Sopran 1    | Anna     | Bäcker     |
| Sopran 2    | Sofia    | Möller     |
| Alt 1       | Lena     | Schröder   |
| Alt 2       | Klara    | Günther    |
| Tenor 1     | Jonas    | Färber     |
| Tenor 2     | Paul     | Löwe       |
| Bass 1      | Max      | Kühn       |
| Bass 2      | Erik     | Bäumer     |

(Endgültige Feldliste — z. B. ob Passwort/Einladung nötig — bei Umsetzung aus
`UserController::store` + Template abgeleitet.)

## Wiederholbarkeit / Idempotenz

Das Skript startet immer mit `bin/fresh-db.sh` (Playwright `globalSetup`).
Deterministische Namen und feste Reihenfolge → identisches Ergebnis bei jedem
Lauf, kein State-Leak zwischen Läufen. Das Runbook nennt denselben
Reset-Befehl als ersten Schritt.

## Offene Detailpunkte (Umsetzung, nicht Design)

- Exakte Formularfeld-Selektoren/Namen je Formular (aus Templates verifizieren).
- Ob Mitglied-Anlegen ein Passwort setzt oder einen Einladungs-/Registrierungs-
  Flow auslöst.
- Projekt-Pflichtfelder (Name, Zeitraum?).
- Playwright-Config: bislang keine `playwright.config`; ggf. minimale Config für
  `tests/e2e` (baseURL, `globalSetup`) ergänzen — bestehende `tests/js`-Läufe
  nicht stören.

## Teststrategie

Das Skript ist selbst der Test (Assertions je Schritt). Zusätzlich: nach
Umsetzung ein realer Lauf gegen die laufende ddev-Instanz, Ergebnis
(grün/rot + erzeugte Counts) berichten. Runbook-Schritte einmal manuell
gegengeprüft, dass Routen/Felder stimmen.
