# Automatisches E2E-Erkundungs-Netz — Design

**Datum:** 2026-08-05
**Status:** Design (in Abstimmung)

## Ziel (der eigentliche Zweck)

Solo-Entwickler, Projekt zu groß, um bei jeder Änderung alles manuell
durchzuklicken. Es soll bis in jeden Winkel **automatisch erkundet und
durchgeklickt** werden — die ganze App, in vertretbarer Geschwindigkeit, als
regelmäßig laufendes Regressions-Sicherheitsnetz. Ersetzt das manuelle
Durchklicken.

Kernerkenntnis: „jeden Winkel" von Hand als Szenario zu schreiben löst das
Problem nicht — dann schreibt man alles selbst. Deshalb **Hybrid** aus
automatischer Breiten-Erkundung (Crawler) und handgeschriebener Tiefe
(Szenarien), beide auf einer deterministisch bebootstrappten DB.

## Nicht-Ziele

- Kein Ersatz für `DevSeedService` (Bootstrap startet bewusst leer).
- Keine Visual-Regression/Pixelvergleiche in diesem Schritt.
- Keine CI-Pipeline in diesem Schritt (lokal gegen ddev; CI später möglich).

## Strategie: Hybrid auf deterministischer DB

Reihenfolge jedes Laufs:

1. **Fundament** — fresh-DB → Bootstrap (Praxis-Erstlauf): erster Admin über
   `/setup`, dann SATB-Struktur + 8 Mitglieder + Projekt über echte UI. Erzeugt
   die Daten, die überhaupt erst etwas zum Erkunden liefern.
2. **storageState** — Login einmal, danach von allen weiteren Läufen/Projekten
   wiederverwendet (Speed).
3. **Ebene A — Crawler (Breite, automatisch).** Entdeckt alle Routen, besucht
   jede Seite, klickt Buttons/Modals **inkl. zustandsändernder Aktionen**,
   prüft automatisch die Fehler-Kriterien. Neue Route → automatisch mitgeprüft.
4. **Ebene B — Szenarien (Tiefe, Bausteine).** Handgeschriebene Flows mit
   echten Assertions für kritische Anlege-/Bearbeiten-/Lösch-Pfade. Wächst
   modulweise. Liefert zugleich Bausteine, die der Bootstrap nutzt.

## DB-Reset (freigegeben: DROP/CREATE)

```bash
ddev mysql -e "DROP DATABASE IF EXISTS db; CREATE DATABASE db;"
ddev exec ./vendor/bin/phinx migrate
```

Leere migrierte DB ohne User → App leitet auf `/setup`. Gekapselt in
`bin/fresh-db.sh` (`set -euo pipefail`, LF). Kein `dev_seed` — der Bootstrap
baut die Daten über die echte UI auf.

## Ebene A — Crawler im Detail

**Routen-Entdeckung.** Quelle ist `src/Routes.php` (maßgebliche, vollständige
Liste — nicht nur was verlinkt ist). `crawler/routes.mjs` leitet daraus eine
Routen-Tabelle ab (Methode, Muster, Parameter). Parametrisierte Routen
(`/projects/{id}/…`) werden mit **echten IDs aus der bebootstrappten DB**
konkretisiert (IDs aus den Listenseiten gescraped bzw. aus dem Bootstrap-
Ergebnis bekannt).

**Besuchen & Klicken (aggressiver Modus, gewählt).** GET-Routen direkt
navigieren. Auf jeder Seite: Modals/Tabs/Akkordeons öffnen und Buttons klicken,
auch absendende/ändernde. POST-Aktionen werden über ihre echten UI-Formulare
ausgelöst (nicht blind gePOSTet), damit CSRF-Tokens automatisch mitgehen.

**Denylist (Selbst-Sabotage verhindern).** `crawler/denylist.mjs` überspringt
Aktionen, die den Lauf oder die Umgebung unrettbar kaputt machen:
- Logout / Session-Beendigung
- Backup wiederherstellen / einspielen, DB-Reset-Aktionen
- Mail-Key-Rotation
- Selbstlöschung des Admin-Accounts
- Rollen-/Rechteänderungen, die den Admin aussperren

**Isolation.** Weil aggressive Klicks Daten zerstören, läuft der Crawler auf
einer **eigenen, frisch bebootstrappten DB** (eigenes Playwright-Projekt bzw.
eigener Lauf) und darf darin frei „wüten". Nichts hängt an seinem Endzustand.

**Fehler-Kriterien (alle gewählt).** Ein Seitenbesuch gilt als Fehler bei:
- HTTP 5xx, sowie unerwartetes 4xx bei **GET**-Navigation interner Seiten
  (Validierungs-4xx bei Probe-Submits sind erwartet → **kein** Fehler)
- PHP-/Exception-/Stacktrace-/Whoops-Ausgabe im HTML
- JS-Konsolen-`error` oder uncaught `pageerror`
- kaputte interne Links/Assets (404 auf intern verlinkte URL/CSS/JS)

Ergebnis: Report mit besuchten Routen, geklickten Aktionen, gefundenen Fehlern
(Route + Kriterium + Kurzauszug).

## Ebene B — Szenarien / Bausteine

Wiederverwendbare Bausteine (Page-Object-artig), jede Funktion nimmt `page` +
Daten, macht echte UI-Interaktion + Assertion, gibt erzeugte Referenz zurück.
Neues Modul = neue Datei unter `steps/`. Komplexe Szenarien = Bausteine
komponieren.

Bootstrap-Szenario (`praxis-erstlauf`) nutzt genau diese Bausteine:

| # | Route                            | Aktion                                             |
|---|----------------------------------|----------------------------------------------------|
| 1 | `/setup`                         | Admin anlegen → Rolle „Admin"                      |
| 2 | `/login`                         | einloggen                                          |
| 3 | `/voice-groups` ×4               | Sopran, Alt, Tenor, Bass (kanonisch)               |
| 4 | `/voice-groups/{id}/sub` ×8      | je Gruppe Untergruppe „1"/„2"                       |
| 5 | `/users` ×8                      | 1 Mitglied je Untergruppe, deutsche Namen (Umlaute) |
| 6 | `/projects`                      | 1 Projekt                                          |

Deterministische Mitglieder-Daten (echte Umlaute):

| Untergruppe | Vorname | Nachname |
|-------------|---------|----------|
| Sopran 1 | Anna | Bäcker |
| Sopran 2 | Sofia | Möller |
| Alt 1 | Lena | Schröder |
| Alt 2 | Klara | Günther |
| Tenor 1 | Jonas | Färber |
| Tenor 2 | Paul | Löwe |
| Bass 1 | Max | Kühn |
| Bass 2 | Erik | Bäumer |

## Geschwindigkeit („vertretbar")

- **storageState:** Login 1×, überall wiederverwendet.
- **Parallele Playwright-Worker** für Crawler-Breite und Szenarien.
- **Crawler = überwiegend GET-Navigation** (billig), ändernde Klicks gebündelt.
- **Iterativ-Modus (opt-in `E2E_KEEP_DB=1`):** überspringt fresh-DB + Bootstrap,
  wenn `.auth/admin.json` gültig → direkt ins neue Modul-Szenario beim
  Entwickeln. Default aus (voller deterministischer Lauf).
- Ziel-Budget: Volldurchlauf im Minutenbereich; wird nach erstem realen Lauf
  gemessen und getunt.

## Artefakte / Struktur

```
bin/fresh-db.sh                       DB-Reset
tests/e2e/
  playwright.config.mjs               baseURL; Projekte: bootstrap, scenarios, crawler
  global-setup.mjs                    fresh-db → setupAdmin → login → .auth/admin.json
  .auth/admin.json                    gespeicherte Session (gitignored)
  data/fixtures.mjs                   deterministische Daten (Mitglieder, Struktur)
  steps/                              wiederverwendbare Bausteine (wachsend)
    auth.mjs        setupAdmin, login
    voiceGroups.mjs createGroup, createSubVoice
    members.mjs     createMember
    projects.mjs    createProject
  scenarios/
    praxis-erstlauf.e2e.test.mjs      Bootstrap-Szenario
  crawler/
    routes.mjs                        Routen-Tabelle aus src/Routes.php ableiten
    denylist.mjs                      gefährliche Aktionen/Routen
    crawl.e2e.test.mjs                besucht alle, klickt, prüft Fehler-Kriterien
docs/e2e/praxis-erstlauf-runbook.md   Runbook: Modi, Reset, Bausteine, Crawler
```

Getrennt von den bestehenden seed-abhängigen `tests/js/*.e2e.test.mjs`. Eigene
`playwright.config.mjs` für `tests/e2e`, stört bestehende Läufe nicht.

## Teststrategie / Verifikation

Das Skript ist selbst der Test. Nach Umsetzung: realer Lauf gegen ddev, Report
(grün/rot + Abdeckungs-Counts: Routen besucht, Aktionen geklickt, Fehler
gefunden) berichten. Runbook-Schritte einmal manuell gegengeprüft.

## Wachstumspfad

- Neues Modul: `steps/<modul>.mjs` (+ optional Szenario unter `scenarios/`).
- Crawler nimmt neue Routen **automatisch** aus `src/Routes.php` auf.
- Komplexere Szenarien: Bausteine + storageState komponieren.

## Offene Detailpunkte (Umsetzung, nicht Design)

- Exakte Formularfeld-Selektoren je Formular (aus Templates verifizieren).
- Ob Mitglied-Anlegen Passwort setzt oder Einladungs-Flow auslöst.
- Projekt-Pflichtfelder.
- Wie IDs für parametrisierte Routen am robustesten beschafft werden
  (Listenseiten-Scrape vs. Bootstrap-Rückgabe).
- Genaue Denylist-Selektoren aus den echten Templates.
- Playwright-Projekt-Abhängigkeiten (bootstrap → scenarios/crawler) in Config.
