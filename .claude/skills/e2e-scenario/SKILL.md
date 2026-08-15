---
name: e2e-scenario
description: >
  Workflow zum Erstellen eines neuen End-to-End-Szenarios im Playwright-"Erkundungs-Netz"
  unter `tests/e2e/`. Use this skill whenever the user wants to add, write, or extend an
  automated browser/UI test scenario for ChorManager — e.g. "e2e-szenario", "neues Szenario",
  "e2e-test schreiben", "durchklick-test", "playwright-szenario", "teste den Flow X über die UI",
  "prüfe per Browser, dass ...", or wants to verify a user flow (Anlegen/Bearbeiten/Löschen,
  Rollen/Rechte, Sichtbarkeit) through the real UI rather than PHP-Feature-Tests. Also trigger
  when a needed UI building block (steps/) is missing and must be added for such a test.
  Nicht für PHPUnit-Feature-Tests (tests/Feature) oder die alten tests/js-Läufe.
---

# E2E-Szenario erstellen

Neues Szenario im isolierten Playwright-Netz unter `tests/e2e/` — treibt die **echte UI**
gegen die laufende DDEV-Instanz. Baukasten: wiederverwendbare Bausteine (`steps/`),
deterministische Daten (`data/`), Szenarien (`scenarios/`), plus ein aggressiver Crawler.

**Erst lesen** (kanonische Beispiele — nicht raten, dem Muster folgen):
- [tests/e2e/README.md](../../../tests/e2e/README.md) — alle Run-Befehle
- [docs/e2e/first-run-runbook.md](../../../docs/e2e/first-run-runbook.md) — Konzept/Modi
- Vorbild-Szenarien: [scenarios/first-run.e2e.test.mjs](../../../tests/e2e/scenarios/first-run.e2e.test.mjs) (einfach), [scenarios/role-authorization.e2e.test.mjs](../../../tests/e2e/scenarios/role-authorization.e2e.test.mjs) (Login-als-anderer-User, DB-Helper, 403/Navi)

## Wie das Netz tickt (Kurzabriss)

- **Vor jedem Lauf** läuft `global-setup.mjs`: setzt die DB per `bin/fresh-db.sh` zurück
  (leer + migriert — die Migration seedet SATB-Stimmgruppen + 8 Untergruppen "Sopran 1"…"Bass 2"
  als Produkt-Default), legt über `/setup` einen Admin an, loggt ein und speichert die Session
  (`storageState` → `.auth/admin.json`).
- **Szenario-Tests** (`scenarios/*.e2e.test.mjs`) laufen dadurch **schon als Admin eingeloggt**.
- **DB ist bei jedem Lauf frisch** — Szenarien müssen ihre eigenen Daten anlegen und dürfen sich
  nicht auf Daten anderer Tests verlassen (parallele Ausführung, geteilte DB).

## Pflicht-Reihenfolge

1. **Vorbild lesen** — das nächstliegende bestehende Szenario + die passenden `steps/`.
2. **Daten definieren** — deterministische Fixtures in `tests/e2e/data/` (deutsche Namen mit
   echten Umlauten ä/ö/ü/ß, nie ae/oe/ue/ss).
3. **Bausteine nutzen/erweitern** — vorhandene `steps/` verwenden; fehlt eine UI-Aktion, neuen
   Baustein anlegen (Selektoren gegen `templates/` verifizieren, siehe unten).
4. **Szenario schreiben** — `scenarios/<name>.e2e.test.mjs`, Bausteine komponieren + echte
   Assertions. TDD, wenn sinnvoll: erst die fehlschlagende Assertion, dann grün.
5. **LF + Lauf + grün** — LF normalisieren, isoliert laufen lassen, dann Full-Suite; erst bei
   grün fertig. **Kein `git push`** (macht der Entwickler).

## Vorhandene Bausteine (`tests/e2e/steps/`)

- `auth.mjs` — `setupAdmin(page, admin)`, `login(page, {email, password})`
- `members.mjs` — `createMember(page, member)` — `member`: `{firstName,lastName,email,group,sub,role?}`.
  Wählt **genau eine** Rolle (`member.role`, Default "Mitglied") + Stimmgruppe/Untergruppe;
  `email` ist Pflicht, eine Rolle ist serverseitig Pflicht.
- `projects.mjs` — `createProject(page, {name,description,startDate,endDate})`
- `authz.mjs` — `setMemberPassword(email, plain)`, `readRolePermissions()` (DB via `ddev php`)
- `shell.mjs` — `resolveBash()` (Git Bash finden, siehe Fallen)

Es gibt **bewusst keinen** Baustein zum Anlegen von Stimmgruppen — SATB ist geseedet. Szenarien
**verifizieren** die geseedete Struktur, sie legen sie nicht an.

## Neuen Baustein anlegen (steps/)

Ein Baustein ist eine benannte `async`-Funktion, die `page` + Daten nimmt, **echte
UI-Interaktion** macht und ggf. eine erzeugte Referenz zurückgibt. Muster (aus `members.mjs`):

```javascript
export async function createThing(page, thing) {
    await page.goto('/things');
    await page.click('[data-bs-toggle="modal"][data-bs-target="#addThingModal"]');
    const modal = page.locator('#addThingModal');
    await modal.waitFor({ state: 'visible' });
    await modal.locator('input[name="name"]').fill(thing.name);
    await modal.locator('button[type="submit"]').click();
    await page.waitForURL('**/things');
}
```

**Selektoren immer gegen das echte Template verifizieren** — nicht raten. Formularfelder, Modal-IDs
und Submit-Buttons stehen in `templates/**/*.twig`:

```bash
grep -nE "id=\"[a-zA-Z]+Modal|name=\"[a-z_]+\"|action=\"/|type=\"submit\"" templates/<bereich>/*.twig
```

Kommentiere im Baustein, aus welchem Template die Selektoren stammen (siehe bestehende `steps/`).
Bootstrap-Modals: nach Trigger-Klick auf `state: 'visible'` warten; viele POST-Aktionen laden die
Seite neu (`waitForURL`).

## Häufige Muster & Fallen

- **Als anderen User (nicht Admin) einloggen:** eine per `browser.newContext()` erzeugte Session
  **erbt hier den Admin-`storageState`** aus der Config. Vor dem Login `clearCookies()`, sonst
  leitet `/login` sofort auf `/dashboard`:
  ```javascript
  const context = await browser.newContext({ baseURL: 'https://chormanager.ddev.site', ignoreHTTPSErrors: true });
  await context.clearCookies();
  const userPage = await context.newPage();
  await login(userPage, { email, password });
  // ... prüfen ...
  await context.close();
  ```
- **Mitglied-Passwort für Login:** das `/users`-Formular vergibt **kein** Passwort. Nach
  `createMember` das Login-Passwort per `setMemberPassword(email, pw)` direkt in der DB setzen
  (nutzt den App-Hash; `/login` prüft `password_verify`).
- **Erwartungen aus der DB ableiten, nicht hartkodieren:** z. B. Rollen-Rechte über
  `readRolePermissions()` lesen und die Erwartung daraus berechnen — so testet das Szenario echte
  Durchsetzung statt eine Kopie der Annahmen. Für eigene DB-Abfragen dem Muster in `authz.mjs`
  folgen (`ddev php -r` via `resolveBash()`, PDO auf `mysql:host=db;dbname=db`, User/Pass `db`/`db`).
- **Autorisierung/403 prüfen:** `RoleMiddleware` liefert bei fehlendem Recht **HTTP 403**, nicht
  eingeloggt → **302 /login**. Statuscode robust ohne Render holen:
  `const status = (await page.request.get('/pfad')).status();` (nutzt die Session-Cookies des
  Kontexts). Für "Navi zeigt keinen verbotenen Link": `page.locator('a[href^="/pfad"]:visible')`
  → Count 0 (das Menü ist serverseitig rechtegefiltert).
- **Neue Datei/Selektor hängt statt schnell zu failen?** Actions haben in dieser Config
  `actionTimeout: 15s` (nicht mehr 0). Eigene Waits mit explizitem `{ timeout }` versehen.
- **Git Bash:** DB-/`ddev`-Helfer laufen über `resolveBash()` — im VS-Code-Terminal löst blankes
  `bash` sonst auf WSL-bash auf und schlägt fehl. Neue Node-Helfer, die `bash`/`ddev` brauchen,
  ebenfalls über `resolveBash()` starten.
- **Modul-gegatete Bereiche** (Finanzen/Aufgaben/Sponsoring/Budget/Newsletter) sind nur
  registriert, wenn das `FEATURE_*`-Modul an ist — sonst 404. Für umgebungsunabhängige Szenarien
  entweder meiden oder das Modul zur Laufzeit prüfen.

## Verifizieren & abschließen

1. **LF normalisieren** (alle neuen/geänderten Dateien außer `.bat/.cmd/.ps1`):
   ```bash
   perl -i -pe 's/\r\n/\n/g' tests/e2e/scenarios/<name>.e2e.test.mjs tests/e2e/steps/<neu>.mjs tests/e2e/data/<neu>.mjs
   ```
2. **Syntax:** `node --check <datei>` je Datei.
3. **Isoliert laufen** (schnelle Iteration; Befehle in der README):
   ```bash
   npx playwright test --config tests/e2e/playwright.config.mjs scenarios/<name>.e2e.test.mjs
   ```
   Zuschauen: `--headed --workers=1` oder `--ui`. Iterativ ohne DB-Reset: `E2E_KEEP_DB=1`.
4. **Full-Suite** zur Regressions-/Parallel-Prüfung:
   ```bash
   npx playwright test --config tests/e2e/playwright.config.mjs
   ```
   Grün = alle `passed`, Crawler ohne echte Funde. **Auf dem Host ausführen, nicht im Container.**
5. **Ergebnis berichten** (Counts/Laufzeit) und **lokal committen** (kein Push). Bei Feature-Arbeit
   gilt die Projektregel: Tests gehören dazu — Szenario ist Teil der Definition-of-Done.

## Namens-/Ablagekonvention

Alle Datei-, Funktions- und Variablennamen **englisch** (Projektregel: `instructions/naming.md`);
deutsch bleiben nur Testbeschreibungen, Kommentare und Fixture-Daten — dort immer echte Umlaute.

- Szenario: `tests/e2e/scenarios/<kebab-name>.e2e.test.mjs` (matcht das `scenarios`-Projekt)
- Baustein: `tests/e2e/steps/<domain>.mjs` (eine Domäne je Datei)
- Daten: `tests/e2e/data/<name>.mjs` (deterministisch, Umlaute echt)
- Reine Node-Unit-Checks von Helfern: `tests/e2e/**/_<name>.spec.mjs` (matcht das `checks`-Projekt)
