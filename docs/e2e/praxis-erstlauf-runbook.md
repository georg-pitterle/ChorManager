# Runbook: Automatisches E2E-Erkundungs-Netz

## Zweck

Die ganze App wird auf einer frisch bebootstrappten Dev-DB automatisch
erkundet (Crawler) und in kritischen Flows tief getestet (Szenarien).
Ersatz fürs manuelle Durchklicken vor einem Release oder nach größeren
Änderungen.

Die Suite liegt unter `tests/e2e/` und besteht aus drei Playwright-Projekten
(`tests/e2e/playwright.config.mjs`):

- `checks` — reine Unit-Tests der Crawler-Helfer (`_*.spec.mjs`, kein Browser).
- `scenarios` — Szenario-Tests, die reale Nutzerflüsse nachbauen
  (`scenarios/*.e2e.test.mjs`).
- `crawler` — der aggressive Seiten-Crawler (`crawler/crawl.e2e.test.mjs`).

Alle drei Projekte teilen sich eine gemeinsame `globalSetup`
(`tests/e2e/global-setup.mjs`) und dieselbe gespeicherte Login-Session
(`use.storageState` = `tests/e2e/.auth/admin.json`).

## Vorbedingungen

- DDEV läuft: `ddev start`
- Erreichbar: https://chormanager.ddev.site
- Playwright-Browser installiert: `npx playwright install chromium`

## Datenbank-Reset (manuell, optional)

```bash
bash bin/fresh-db.sh
```

Leert die Dev-DB (DROP/CREATE) und migriert neu. Danach hat die App keine
User → `/setup`. Die Migration seedet dabei produktseitig die SATB-Struktur
(Sopran, Alt, Tenor, Bass) inkl. je zwei Untergruppen ("Sopran 1"/"Sopran 2"
… "Bass 1"/"Bass 2") als Auslieferungszustand. Dieser Schritt ist manuell
selten nötig — `global-setup.mjs` (siehe unten) führt ihn im Volllauf
automatisch aus.

## Voller Lauf (deterministisch)

```bash
npx playwright test --config tests/e2e/playwright.config.mjs
```

`global-setup.mjs` macht automatisch:

1. `bash bin/fresh-db.sh` (frische, migrierte DB — SATB-Struktur bereits
   geseedet, keine User).
2. Admin über `/setup` anlegen (`tests/e2e/data/fixtures.mjs` → `ADMIN`),
   danach Cookies leeren und über `/login` neu einloggen (damit der echte
   Login-Formularpfad getestet wird, nicht nur die Setup-Auto-Anmeldung).
3. Die eingeloggte Session als `tests/e2e/.auth/admin.json` speichern.

Danach laufen `checks`, `scenarios` und `crawler` gegen dieselbe Session.

## Iterativ-Modus (schnell beim Entwickeln)

```bash
E2E_KEEP_DB=1 npx playwright test --config tests/e2e/playwright.config.mjs scenarios/<datei>
```

Überspringt fresh-db + Bootstrap, wenn eine gültige Session
(`tests/e2e/.auth/admin.json`) bereits existiert. Existiert sie noch nicht,
bootstrapped `global-setup.mjs` trotz `E2E_KEEP_DB=1` einmalig ganz normal
(sonst würde `/setup` gegen eine nicht-leere DB laufen). Nützlich, um einen
einzelnen Baustein oder ein einzelnes Szenario ohne kompletten Reset
mehrfach hintereinander zu testen.

## Bausteine (steps/)

- `auth.mjs` — `setupAdmin(page, admin)`, `login(page, { email, password })`
- `members.mjs` — `createMember(page, member)` (Mitglied inkl. Rolle,
  Stimmgruppe und **bereits vorhandener** Untergruppe anlegen)
- `projects.mjs` — `createProject(page, project)`

Es gibt bewusst **keinen** `voiceGroups.mjs`-Baustein: Die SATB-Struktur ist
Produkt-Standard und wird per Migration geseedet
(`db/migrations/20260314130000_initial.php`), nicht von der Testsuite
angelegt. Das Bootstrap-Szenario verifiziert lediglich, dass die geseedete
Struktur nach `fresh-db` vorhanden ist (siehe `scenarios/praxis-erstlauf.e2e.test.mjs`,
Test „Bootstrap: geseedete SATB-Struktur vorhanden").

Neues Modul: neue Datei unter `steps/`, im Szenario komponieren. Testdaten
(deterministisch, mit echten Umlauten) liegen zentral in
`tests/e2e/data/fixtures.mjs`.

## Crawler

Liest alle GET-Routen aus `src/Routes.php` (`tests/e2e/crawler/routes.mjs`
löst dabei auch verschachtelte Slim-`group()`-Präfixe auf, damit z. B.
`/projects/…` nicht ungeprefixt als `/…` extrahiert wird), besucht jede so
ermittelte sowie jede im Markup tatsächlich gefundene interne Seite und
klickt anschließend alle sichtbaren, nicht-denylisteten Buttons/Links
(maximal 25 pro Seite, siehe `MAX_BUTTONS_PER_PAGE` in
`crawl.e2e.test.mjs`).

Pro Seite werden vier Fehlerkriterien geprüft (`crawler/detectors.mjs`):

1. HTTP 5xx oder unerwartetes 4xx (401/403 sind als bewusste Auth-Sperren
   erlaubt, siehe `checkResponse`).
2. PHP-/Exception-Ausgabe im HTML (`Fatal error`, `Whoops`, `Stack trace:`, …).
3. JS-Konsolenfehler (`console.error`) oder unbehandelte `pageerror` —
   ungefiltert, damit kein echter Bug hinter einer pauschalen Ausnahme
   verschwindet.
4. Kaputte interne Links (Navigationsfehler, die keine erwarteten Downloads
   sind).

Läuft aggressiv auf einer isolierten, frisch geseedeten DB. Gefährliche
Aktionen sind in `tests/e2e/crawler/denylist.mjs` gesperrt: Logout,
Backup-Restore, Key-Rotation, Selbstlöschung/-deaktivierung des Admin-Accounts
(ID 1), DB-Reset.

**Eine dokumentierte, eng gefasste Ausnahme:** `/newsletters/create` liefert
für den Bootstrap-Admin bewusst HTTP 403 (`NewsletterController::create()`
verlangt mindestens ein Projekt, in dem der User Mitglied ist — der
E2E-Bootstrap-Admin legt aber nur sich selbst an, keine Projektmitgliedschaft
außer als Ersteller). Das ist kein Autorisierungsbug, sondern ein bewusster
Leerzustands-Guard; die Ausnahme steht explizit und begründet in
`KNOWN_BENIGN_CONSOLE_ERRORS` in `crawl.e2e.test.mjs`.

## Befunde interpretieren

- 5xx / PHP-Fehler / JS-Fehler = echter Bug → fixen.
- Neues, tatsächlich erwartbares 4xx (bewusste Auth-Sperre) → analog zum
  Newsletter-Fall eng gefasst und begründet in
  `KNOWN_BENIGN_CONSOLE_ERRORS` bzw. `detectors.checkResponse` ausnehmen,
  nie pauschal.
- 404 auf einer geraten-parametrisierten Route (`paramSubstitutedUrls`, ID
  per Präfix-Heuristik geraten, nicht im Markup verlinkt) wird automatisch
  übersprungen — das ist ein bekanntes Extraktionsartefakt, kein Befund.
  404 auf einer statischen Route oder einem tatsächlich verlinkten Pfad ist
  dagegen immer ein echter Befund.
- Ein neuer Routen-Präfix in `src/Routes.php` (neue `group()`-Ebene) wird von
  `routes.mjs` automatisch mit aufgelöst — bei Verdacht auf eine Lücke dort
  zuerst `tests/e2e/crawler/_routes.spec.mjs` gegen die neue Route prüfen.

## Manuelles Mitklicken (Praxis-Erstlauf)

Reihenfolge entspricht `scenarios/praxis-erstlauf.e2e.test.mjs`:

1. `/setup` — Admin anlegen (Vorname, Nachname, E-Mail, Passwort;
   Passwortrichtlinie: mindestens 12 Zeichen, gemischte Zeichenklassen).
2. `/login` — mit den Admin-Zugangsdaten einloggen.
3. `/voice-groups` — prüfen, dass Sopran, Alt, Tenor, Bass bereits mit je
   zwei Untergruppen vorhanden sind (produktseitig geseedet, **nicht** neu
   anlegen).
4. `/users` — 8 Mitglieder anlegen, je eines pro geseedeter Untergruppe
   (Rolle „Mitglied" ankreuzen, Stimmgruppe ankreuzen, passende Untergruppe
   im dann sichtbaren Select wählen).
5. `/projects` — ein Projekt anlegen.

## Gemessene Laufzeit

Gemessen am 2026-08-06 mit:

```bash
time npx playwright test --config tests/e2e/playwright.config.mjs
```

Ergebnis: **8 Tests grün** (Volllauf inkl. `fresh-db` + Bootstrap),
Wall-Clock **~2 min 52 s** (`real 2m52.036s`), mit **8 Workern**
(`workers` ist in `playwright.config.mjs` außerhalb von CI nicht gesetzt →
Playwright wählt automatisch die CPU-Kernzahl).

Aufschlüsselung:

- `checks` (4 Tests): < 20 ms insgesamt.
- `scenarios` (3 Tests): SATB-Verifikation 521 ms, 8 Mitglieder anlegen
  10,2 s, Projekt anlegen 1,6 s.
- `crawler` (1 Test): **2,7 min** — 55 URLs besucht, 257 Buttons geklickt.
  Dominiert die Gesamtlaufzeit fast vollständig, da alle anderen Tests
  parallel dazu längst fertig sind.

Zwei `CRAWLER-WARNUNG`-Zeilen (`/users`, `/voice-groups`) zeigen, dass dort
`MAX_BUTTONS_PER_PAGE` (25) erreicht wurde — kein Befund, nur ein Hinweis,
dass auf diesen beiden Seiten nicht zwingend jeder Button geklickt wurde.

Für ~2:52 min ist keine gesonderte Beschleunigung nötig. Sollte die Laufzeit
später als unangenehm empfunden werden (z. B. durch mehr Routen/Seiten oder
einen langsameren Host), lässt sich gezielt tunen:

- `npx playwright test --config tests/e2e/playwright.config.mjs --workers=N`
  — mehr Worker parallelisieren `checks`/`scenarios` weiter, ändern aber
  nichts an der Laufzeit des einzelnen `crawler`-Tests (der läuft als ein
  Test sequenziell Seite für Seite).
- Da der Crawler die Breite dominiert, wirkt eine Reduktion von
  `MAX_BUTTONS_PER_PAGE` oder eine gezieltere URL-Auswahl in
  `crawler/routes.mjs` stärker als zusätzliche Worker.
