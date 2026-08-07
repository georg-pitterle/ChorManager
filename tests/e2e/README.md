# E2E-Tests (Playwright) — Run-Befehle

Automatisches Erkundungs-Netz für ChorManager: Bootstrap-Szenarien, Rollen-Autorisierung
und ein aggressiver Crawler über alle Routen. Läuft gegen die lokale DDEV-Instanz.

> Alle Befehle vom **Projekt-Root** ausführen (`d:\Proggen\ChorManager`).
> `--config tests/e2e/playwright.config.mjs` ist immer nötig (isoliert von `tests/js`).
>
> **Auf dem Host ausführen, NICHT im DDEV-Container** (`ddev exec` o. Ä.): Der Browser
> (Chromium) liegt auf dem Host, die Tests rufen die Seite über `https://chormanager.ddev.site`
> auf, und `globalSetup` nutzt die `ddev`-CLI (die es nur auf dem Host gibt). `php`/`mysql`
> laufen automatisch über `ddev ...` im `bin/fresh-db.sh`-Skript.

## Vorbedingungen

```bash
ddev start                       # DDEV läuft, https://chormanager.ddev.site erreichbar
npx playwright install chromium  # einmalig: Browser installieren
```

> ⚠️ Jeder Lauf startet mit **fresh-db** (`globalSetup`) und **leert die Dev-DB**.
> Danach ggf. `ddev composer seed:dev`, um Dev-Daten zurückzuholen.

## Häufigste Befehle

```bash
# Komplette Suite (checks + scenarios + crawler), headless
npx playwright test --config tests/e2e/playwright.config.mjs

# Nur die Szenarien (Bootstrap + Rollen-Autorisierung)
npx playwright test --config tests/e2e/playwright.config.mjs --project=scenarios

# Nur der Crawler (läuft dank dependency zuerst die scenarios)
npx playwright test --config tests/e2e/playwright.config.mjs --project=crawler

# Nur die reinen Unit-Checks (Crawler-Helfer, kein Browser nötig — aber fresh-db läuft trotzdem)
npx playwright test --config tests/e2e/playwright.config.mjs --project=checks
```

## Einzelne Dateien / Tests

```bash
# Praxis-Erstlauf (SATB prüfen, 8 Mitglieder, Projekt)
npx playwright test --config tests/e2e/playwright.config.mjs scenarios/praxis-erstlauf.e2e.test.mjs

# Rollen-Autorisierung (jede Rolle sieht nur Erlaubtes)
npx playwright test --config tests/e2e/playwright.config.mjs scenarios/role-authorization.e2e.test.mjs

# Crawler
npx playwright test --config tests/e2e/playwright.config.mjs crawler/crawl.e2e.test.mjs

# Nach Testnamen filtern (Teilstring)
npx playwright test --config tests/e2e/playwright.config.mjs -g "8 Mitglieder"
```

## Zuschauen (Browser sichtbar)

```bash
# Interaktiver UI-Modus: Tests anklicken, zuschauen, zurückspulen
npx playwright test --config tests/e2e/playwright.config.mjs --ui

# Sichtbares Browserfenster (maximiert), ein Worker — gut zum Mitschauen
npx playwright test --config tests/e2e/playwright.config.mjs scenarios/praxis-erstlauf.e2e.test.mjs --headed --workers=1
npx playwright test --config tests/e2e/playwright.config.mjs crawler/crawl.e2e.test.mjs --headed --workers=1

# Schritt-für-Schritt mit Inspector
npx playwright test --config tests/e2e/playwright.config.mjs scenarios/role-authorization.e2e.test.mjs --debug
```

> Hinweis: Setup + Login laufen im `globalSetup` in einem eigenen, **headless** Browser —
> die sichst du auch mit `--headed` nicht. Die Szenario-/Crawler-Tests starten bereits
> angemeldet (gespeicherte Session).

## Iterativ entwickeln (DB behalten, schneller)

```bash
# Überspringt fresh-db + Bootstrap, WENN eine gültige Session (.auth/admin.json) existiert.
# Erster Lauf ohne die Variable erzeugt die Session; danach:
E2E_KEEP_DB=1 npx playwright test --config tests/e2e/playwright.config.mjs scenarios/role-authorization.e2e.test.mjs
```

## DB manuell zurücksetzen

```bash
bash bin/fresh-db.sh
# leere, migrierte DB ohne User -> App zeigt /setup
```

## Nützliche Flags

```bash
--workers=1        # seriell (stabiler zum Zuschauen/Debuggen)
--headed           # sichtbares Browserfenster
--ui               # interaktiver UI-Modus
--debug            # Playwright Inspector (Schritt für Schritt)
-g "<text>"        # nur Tests mit passendem Namen
--project=<name>   # checks | scenarios | crawler
--trace on         # Trace immer aufzeichnen (sonst nur retain-on-failure)
npx playwright show-report   # letzten HTML-Report öffnen
```

## Fehlerbehebung

- **`WSL ... execvpe(/bin/bash) failed` beim Start (z. B. im VS-Code-Terminal):** Dort löst
  `bash` auf WSL-bash statt Git Bash auf. Der Test sucht Git Bash automatisch
  (`C:\Program Files\Git\bin\bash.exe`). Liegt Git woanders, den Pfad per Umgebungsvariable
  setzen: `E2E_BASH="C:\Pfad\zu\Git\bin\bash.exe"`. Voraussetzung: **Git for Windows** installiert.
- **`fresh-db.sh fehlgeschlagen`:** Läuft DDEV? `ddev start`. Danach `bash bin/fresh-db.sh`
  einzeln testen; die Meldung nennt Exit-Code und stderr.

## Ergebnisse deuten

- **Szenarien**: harte Assertions (Struktur, Anlegen, Rollen-403 + Navigation).
- **Crawler**: prüft je Seite HTTP 5xx / unerwartetes 4xx, PHP-/Exception-Ausgabe,
  JS-Konsolen-Fehler, kaputte interne Links. Gefährliche Aktionen (Logout, Backup-Restore,
  Key-Rotation, Admin-Selbstlöschung, DB-Reset) sind per Denylist gesperrt.
- Ausführliches Runbook: [../../docs/e2e/praxis-erstlauf-runbook.md](../../docs/e2e/praxis-erstlauf-runbook.md)
