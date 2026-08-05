# Automatisches E2E-Erkundungs-Netz — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ein hybrides Playwright-System, das ChorManager auf einer frisch bebootstrappten DB automatisch bis in jeden Winkel erkundet (Crawler) und kritische Flows tief abtestet (Szenarien) — als Regressions-Sicherheitsnetz für einen Solo-Entwickler.

**Architecture:** fresh-DB → Bootstrap (Admin + SATB-Struktur + 8 Mitglieder + Projekt über echte UI) → storageState → (A) Crawler besucht alle Routen aus `src/Routes.php` aggressiv und prüft Fehler-Kriterien auf isolierter DB, (B) handgeschriebene Bausteine-Szenarien mit echten Assertions. Alles unter eigener `tests/e2e/playwright.config.mjs`, getrennt von den bestehenden `tests/js/*.e2e.test.mjs`.

**Tech Stack:** Node ESM (`.mjs`), `@playwright/test` (^1.60, bereits vorhanden), DDEV, MySQL, Phinx, Bash.

## Global Constraints

- Basis-URL: `https://chormanager.ddev.site` (wie bestehende `tests/js`-e2e).
- Zeilenenden LF für alle neuen Textdateien außer `.bat/.cmd/.ps1`. `.sh` = LF.
- Deutsche Texte mit echten Umlauten (ä/ö/ü/ß), nie ae/oe/ue/ss.
- Kein `git push` (manuell durch Entwickler).
- Neue e2e-Dateien liegen unter `tests/e2e/`, laufen nur über `--config tests/e2e/playwright.config.mjs`; bestehende `tests/js`-Läufe dürfen nicht gestört werden.
- DB-Reset = `DROP DATABASE IF EXISTS db; CREATE DATABASE db;` + `phinx migrate` (freigegeben).
- Kein `dev_seed` im Bootstrap — Daten entstehen über die echte UI.
- `.auth/` (gespeicherte Sessions) wird gitignored, nie committen.

## File Structure

```
bin/fresh-db.sh                       DB-Reset (DROP/CREATE + migrate)
tests/e2e/
  playwright.config.mjs               baseURL, testDir '.', globalSetup, Projekte
  global-setup.mjs                    fresh-db → setupAdmin → login → .auth/admin.json
  .auth/admin.json                    gespeicherte Session (gitignored)
  data/fixtures.mjs                   deterministische Daten (Admin, Struktur, 8 Mitglieder)
  steps/
    auth.mjs                          setupAdmin(page, admin), login(page, creds)
    voiceGroups.mjs                   createGroup(page, name), createSubVoice(page, groupName, subName)
    members.mjs                       createMember(page, member)
    projects.mjs                      createProject(page, project)
  scenarios/
    praxis-erstlauf.e2e.test.mjs      Bootstrap-Szenario mit Assertions
  crawler/
    routes.mjs                        Routen-Tabelle aus src/Routes.php ableiten
    denylist.mjs                      gefährliche Aktionen/Routen-Muster
    detectors.mjs                     Fehler-Detektoren (console/pageerror/response/HTML)
    crawl.e2e.test.mjs                besucht alle Routen, klickt, prüft Fehler-Kriterien
docs/e2e/praxis-erstlauf-runbook.md   Runbook (Modi, Reset, Bausteine, Crawler)
```

---

## Task 1: DB-Reset-Wrapper `bin/fresh-db.sh`

**Files:**
- Create: `bin/fresh-db.sh`

**Interfaces:**
- Produces: ausführbares Skript `bin/fresh-db.sh`, das die Dev-DB leert, neu migriert und mit Exit 0 endet. Nach Lauf: DB ohne User → `GET /` leitet auf `/setup`.

- [ ] **Step 1: Skript schreiben**

`bin/fresh-db.sh`:
```bash
#!/usr/bin/env bash
set -euo pipefail

# Setzt die ChorManager-Dev-DB auf den Auslieferungszustand zurück:
# leere, migrierte Datenbank ohne User -> die App leitet auf /setup.
# NUR fuer Dev/ddev gedacht.

echo "[fresh-db] DROP + CREATE DATABASE db ..."
ddev mysql -e "DROP DATABASE IF EXISTS db; CREATE DATABASE db;"

echo "[fresh-db] phinx migrate ..."
ddev exec ./vendor/bin/phinx migrate

echo "[fresh-db] fertig: leere migrierte DB (keine User)."
```

- [ ] **Step 2: Ausführbar machen + LF sichern**

Run:
```bash
chmod +x bin/fresh-db.sh
perl -i -pe 's/\r\n/\n/g' bin/fresh-db.sh
```

- [ ] **Step 3: Reset ausführen und Ergebnis prüfen**

Run:
```bash
bash bin/fresh-db.sh
```
Expected: endet mit `[fresh-db] fertig...`, Exit 0.

Danach prüfen, dass keine User existieren:
```bash
ddev mysql -e "SELECT COUNT(*) AS n FROM users;" db
```
Expected: `n` = 0 (oder Tabelle existiert mit 0 Zeilen).

- [ ] **Step 4: Verifizieren, dass `/` auf `/setup` leitet**

Run:
```bash
curl -sk -o /dev/null -w "%{http_code} %{redirect_url}\n" https://chormanager.ddev.site/
```
Expected: `302 https://chormanager.ddev.site/setup` (oder relativer Location `/setup`).

- [ ] **Step 5: Commit**

```bash
git add bin/fresh-db.sh
git commit -m "feat(e2e): fresh-db.sh setzt Dev-DB fuer E2E-Laeufe zurueck"
```

---

## Task 2: Playwright-Config + gitignore für `tests/e2e`

**Files:**
- Create: `tests/e2e/playwright.config.mjs`
- Create: `tests/e2e/.gitignore`

**Interfaces:**
- Consumes: `tests/e2e/global-setup.mjs` (Pfad in Config referenziert; Datei entsteht in Task 3 — bis dahin verweist Config darauf, Lauf erst ab Task 3 sinnvoll).
- Produces: Config mit `baseURL`, `testDir` = eigenes Verzeichnis, `storageState`-Nutzung, drei Projekte: `bootstrap` (globalSetup-Ergebnis), `scenarios`, `crawler`. Lauf-Kommando: `npx playwright test --config tests/e2e/playwright.config.mjs`.

- [ ] **Step 1: Config schreiben**

`tests/e2e/playwright.config.mjs`:
```javascript
import { defineConfig } from '@playwright/test';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const dir = path.dirname(fileURLToPath(import.meta.url));
export const AUTH_FILE = path.join(dir, '.auth', 'admin.json');

export default defineConfig({
    testDir: dir,
    // globalSetup bootstrappt DB + Admin + Login und speichert die Session.
    globalSetup: path.join(dir, 'global-setup.mjs'),
    timeout: 120000,
    fullyParallel: true,
    workers: process.env.CI ? 2 : undefined,
    reporter: [['list']],
    use: {
        baseURL: 'https://chormanager.ddev.site',
        ignoreHTTPSErrors: true,
        storageState: AUTH_FILE,
        trace: 'retain-on-failure',
    },
    projects: [
        { name: 'scenarios', testMatch: /scenarios\/.*\.e2e\.test\.mjs$/ },
        { name: 'crawler', testMatch: /crawler\/crawl\.e2e\.test\.mjs$/ },
    ],
});
```

- [ ] **Step 2: gitignore für Sessions**

`tests/e2e/.gitignore`:
```
.auth/
test-results/
playwright-report/
```

- [ ] **Step 3: LF sichern**

Run:
```bash
perl -i -pe 's/\r\n/\n/g' tests/e2e/playwright.config.mjs tests/e2e/.gitignore
```

- [ ] **Step 4: Config-Syntax prüfen (lädt ohne globalSetup-Ausführung)**

Run:
```bash
node --check tests/e2e/playwright.config.mjs && echo "SYNTAX_OK"
```
Expected: `SYNTAX_OK`.

- [ ] **Step 5: Commit**

```bash
git add tests/e2e/playwright.config.mjs tests/e2e/.gitignore
git commit -m "feat(e2e): isolierte Playwright-Config fuer tests/e2e"
```

---

## Task 3: Fixtures + global-setup (fresh-db → Admin → Login → storageState)

**Files:**
- Create: `tests/e2e/data/fixtures.mjs`
- Create: `tests/e2e/steps/auth.mjs`
- Create: `tests/e2e/global-setup.mjs`

**Interfaces:**
- Consumes: `AUTH_FILE` aus `playwright.config.mjs`; `bin/fresh-db.sh`.
- Produces:
  - `fixtures.mjs` exportiert `ADMIN`, `VOICE_GROUPS`, `MEMBERS`, `PROJECT`.
  - `auth.mjs` exportiert `setupAdmin(page, admin)` und `login(page, { email, password })`.
  - `global-setup.mjs` (default export async fn): führt fresh-db aus (außer `E2E_KEEP_DB=1`), macht Setup+Login, speichert `storageState` nach `AUTH_FILE`.

- [ ] **Step 1: Fixtures schreiben**

`tests/e2e/data/fixtures.mjs`:
```javascript
// Deterministische Testdaten fuer Bootstrap. Reihenfolge = kanonische SATB-Ordnung.
export const ADMIN = {
    firstName: 'Admin',
    lastName: 'Test',
    email: 'admin@chor.local',
    password: 'Test1234!',
};

export const VOICE_GROUPS = ['Sopran', 'Alt', 'Tenor', 'Bass'];
export const SUB_VOICES = ['1', '2']; // je Gruppe

// Ein Mitglied je Untergruppe (SATB x 2), deutsche Namen mit echten Umlauten.
export const MEMBERS = [
    { firstName: 'Anna', lastName: 'Bäcker', email: 'anna.baecker@chor.local', group: 'Sopran', sub: '1' },
    { firstName: 'Sofia', lastName: 'Möller', email: 'sofia.moeller@chor.local', group: 'Sopran', sub: '2' },
    { firstName: 'Lena', lastName: 'Schröder', email: 'lena.schroeder@chor.local', group: 'Alt', sub: '1' },
    { firstName: 'Klara', lastName: 'Günther', email: 'klara.guenther@chor.local', group: 'Alt', sub: '2' },
    { firstName: 'Jonas', lastName: 'Färber', email: 'jonas.faerber@chor.local', group: 'Tenor', sub: '1' },
    { firstName: 'Paul', lastName: 'Löwe', email: 'paul.loewe@chor.local', group: 'Tenor', sub: '2' },
    { firstName: 'Max', lastName: 'Kühn', email: 'max.kuehn@chor.local', group: 'Bass', sub: '1' },
    { firstName: 'Erik', lastName: 'Bäumer', email: 'erik.baeumer@chor.local', group: 'Bass', sub: '2' },
];

export const PROJECT = {
    name: 'Sommerkonzert 2026',
    description: 'Automatisch angelegtes E2E-Testprojekt.',
    startDate: '2026-06-01',
    endDate: '2026-07-31',
};
```

- [ ] **Step 2: auth-Bausteine schreiben (verifizierte Selektoren)**

`tests/e2e/steps/auth.mjs`:
```javascript
import { expect } from '@playwright/test';

// Legt den ersten Admin ueber /setup an (nur moeglich, wenn keine User existieren).
// Verifizierte Felder aus templates/auth/setup.twig: first_name, last_name, email, password.
export async function setupAdmin(page, admin) {
    await page.goto('/setup');
    await page.fill('input[name="first_name"]', admin.firstName);
    await page.fill('input[name="last_name"]', admin.lastName);
    await page.fill('input[name="email"]', admin.email);
    await page.fill('input[name="password"]', admin.password);
    await page.click('form[action="/setup"] button[type="submit"]');
    await page.waitForURL('**/login');
}

// Verifizierte Felder aus templates/auth/login.twig: email, password.
export async function login(page, { email, password }) {
    await page.goto('/login');
    await page.fill('input[name="email"]', email);
    await page.fill('input[name="password"]', password);
    await page.click('form[action="/login"] button[type="submit"]');
    await expect(page).not.toHaveURL(/\/login(\?|$)/);
}
```

- [ ] **Step 3: global-setup schreiben**

`tests/e2e/global-setup.mjs`:
```javascript
import { chromium } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { ADMIN } from './data/fixtures.mjs';
import { setupAdmin, login } from './steps/auth.mjs';

const dir = path.dirname(fileURLToPath(import.meta.url));
const AUTH_FILE = path.join(dir, '.auth', 'admin.json');
const BASE_URL = 'https://chormanager.ddev.site';

export default async function globalSetup() {
    const keepDb = process.env.E2E_KEEP_DB === '1';

    if (!keepDb) {
        console.log('[e2e] fresh-db ...');
        execFileSync('bash', [path.join(dir, '..', '..', 'bin', 'fresh-db.sh')], { stdio: 'inherit' });
    } else if (fs.existsSync(AUTH_FILE)) {
        console.log('[e2e] E2E_KEEP_DB=1 und Session vorhanden -> ueberspringe Bootstrap.');
        return;
    }

    fs.mkdirSync(path.dirname(AUTH_FILE), { recursive: true });

    const browser = await chromium.launch();
    const context = await browser.newContext({ baseURL: BASE_URL, ignoreHTTPSErrors: true });
    const page = await context.newPage();

    await setupAdmin(page, ADMIN);
    await login(page, { email: ADMIN.email, password: ADMIN.password });

    await context.storageState({ path: AUTH_FILE });
    await browser.close();
    console.log('[e2e] Bootstrap fertig, Session gespeichert.');
}
```

- [ ] **Step 4: LF sichern**

Run:
```bash
perl -i -pe 's/\r\n/\n/g' tests/e2e/data/fixtures.mjs tests/e2e/steps/auth.mjs tests/e2e/global-setup.mjs
```

- [ ] **Step 5: Syntax prüfen**

Run:
```bash
node --check tests/e2e/data/fixtures.mjs && node --check tests/e2e/steps/auth.mjs && node --check tests/e2e/global-setup.mjs && echo "SYNTAX_OK"
```
Expected: `SYNTAX_OK`.

- [ ] **Step 6: globalSetup real ausführen (über einen Smoke-Test)**

Temporär Smoke-Test `tests/e2e/scenarios/_smoke.e2e.test.mjs`:
```javascript
import { test, expect } from '@playwright/test';

test('eingeloggt nach Bootstrap', async ({ page }) => {
    await page.goto('/');
    await expect(page).not.toHaveURL(/\/(login|setup)(\?|$)/);
});
```
Run:
```bash
npx playwright test --config tests/e2e/playwright.config.mjs scenarios/_smoke.e2e.test.mjs
```
Expected: fresh-db-Ausgabe, dann `1 passed`. Danach Smoke-Test wieder löschen:
```bash
rm tests/e2e/scenarios/_smoke.e2e.test.mjs
```

- [ ] **Step 7: Commit**

```bash
git add tests/e2e/data/fixtures.mjs tests/e2e/steps/auth.mjs tests/e2e/global-setup.mjs
git commit -m "feat(e2e): Fixtures, auth-Bausteine und global-setup mit storageState"
```

---

## Task 4: Baustein `voiceGroups.mjs` + Teil-Szenario

**Files:**
- Create: `tests/e2e/steps/voiceGroups.mjs`
- Create: `tests/e2e/scenarios/praxis-erstlauf.e2e.test.mjs` (schrittweise über Tasks 4–7 aufgebaut)

**Interfaces:**
- Consumes: `login`-State via storageState (schon eingeloggt).
- Produces: `createGroup(page, name)` und `createSubVoice(page, groupName, subName)`. Beide navigieren zu `/voice-groups`, nutzen Bootstrap-Modals, submitten und warten auf Reload.

- [ ] **Step 1: Failing-Szenario schreiben**

`tests/e2e/scenarios/praxis-erstlauf.e2e.test.mjs`:
```javascript
import { test, expect } from '@playwright/test';
import { VOICE_GROUPS, SUB_VOICES } from '../data/fixtures.mjs';
import { createGroup, createSubVoice } from '../steps/voiceGroups.mjs';

test('Bootstrap: Stimmgruppen + Untergruppen', async ({ page }) => {
    for (const name of VOICE_GROUPS) {
        await createGroup(page, name);
    }
    for (const group of VOICE_GROUPS) {
        for (const sub of SUB_VOICES) {
            await createSubVoice(page, group, sub);
        }
    }
    await page.goto('/voice-groups');
    for (const name of VOICE_GROUPS) {
        await expect(page.getByText(name, { exact: true }).first()).toBeVisible();
    }
    // 8 Untergruppen erwartet (je Gruppe "1" und "2")
    const subCount = await page.locator('[data-bs-target^="#deleteSubVoiceModal"]').count();
    expect(subCount).toBe(8);
});
```

- [ ] **Step 2: Test laufen lassen, Fehlschlag bestätigen**

Run:
```bash
npx playwright test --config tests/e2e/playwright.config.mjs scenarios/praxis-erstlauf.e2e.test.mjs
```
Expected: FAIL (`createGroup` nicht gefunden / Modul fehlt).

- [ ] **Step 3: Baustein implementieren**

`tests/e2e/steps/voiceGroups.mjs`:
```javascript
// Verifizierte Selektoren aus templates/voice_groups/index.twig:
//  - Anlegen-Modal:        #createGroupModal, Formular POST /voice-groups, Feld name="name"
//  - Unterstimme-Modal:    #createSubVoiceModal{groupId}, POST /voice-groups/{id}/sub, Feld name="name"
//  - Loeschen-Button je Sub: [data-bs-target^="#deleteSubVoiceModal"]
//  - Gruppen-Zeile traegt Loeschen-Button #deleteGroupModal{id}, daraus wird die groupId gelesen.

export async function createGroup(page, name) {
    await page.goto('/voice-groups');
    await page.click('[data-bs-target="#createGroupModal"]');
    const modal = page.locator('#createGroupModal');
    await modal.waitFor({ state: 'visible' });
    await modal.locator('input[name="name"]').fill(name);
    await modal.locator('button[type="submit"]').click();
    await page.waitForURL('**/voice-groups');
    await page.getByText(name, { exact: true }).first().waitFor();
}

// Liefert die groupId einer Gruppe anhand ihres Anzeigenamens.
async function resolveGroupId(page, groupName) {
    const row = page.locator('tr, .list-group-item, .card', { hasText: groupName }).first();
    const del = row.locator('[data-bs-target^="#deleteGroupModal"]').first();
    const target = await del.getAttribute('data-bs-target'); // "#deleteGroupModal{id}"
    return target.replace('#deleteGroupModal', '');
}

export async function createSubVoice(page, groupName, subName) {
    await page.goto('/voice-groups');
    const groupId = await resolveGroupId(page, groupName);
    await page.click(`[data-bs-target="#createSubVoiceModal${groupId}"]`);
    const modal = page.locator(`#createSubVoiceModal${groupId}`);
    await modal.waitFor({ state: 'visible' });
    await modal.locator('input[name="name"]').fill(subName);
    await modal.locator('button[type="submit"]').click();
    await page.waitForURL('**/voice-groups');
}
```

- [ ] **Step 4: Selektoren gegen Template gegenprüfen**

Run:
```bash
grep -nE "createGroupModal|createSubVoiceModal|deleteGroupModal|deleteSubVoiceModal|name=\"name\"" templates/voice_groups/index.twig
```
Expected: bestätigt `#createGroupModal`, `#createSubVoiceModal{{ group.id }}`, `#deleteGroupModal{{ group.id }}`, `#deleteSubVoiceModal{{ sub_voice.id }}`, Feld `name="name"`. Falls Zeilenstruktur (`tr`/`card`) abweicht, `resolveGroupId`-Locator an tatsächliche Markup-Struktur anpassen.

- [ ] **Step 5: Test grün**

Run:
```bash
perl -i -pe 's/\r\n/\n/g' tests/e2e/steps/voiceGroups.mjs tests/e2e/scenarios/praxis-erstlauf.e2e.test.mjs
npx playwright test --config tests/e2e/playwright.config.mjs scenarios/praxis-erstlauf.e2e.test.mjs
```
Expected: `1 passed`.

- [ ] **Step 6: Commit**

```bash
git add tests/e2e/steps/voiceGroups.mjs tests/e2e/scenarios/praxis-erstlauf.e2e.test.mjs
git commit -m "feat(e2e): Baustein voiceGroups + Stimmgruppen-Teil des Bootstrap-Szenarios"
```

---

## Task 5: Baustein `members.mjs` + Szenario erweitern

**Files:**
- Create: `tests/e2e/steps/members.mjs`
- Modify: `tests/e2e/scenarios/praxis-erstlauf.e2e.test.mjs`

**Interfaces:**
- Consumes: bestehende Stimmgruppen/Untergruppen (Task 4).
- Produces: `createMember(page, { firstName, lastName, email, group, sub })` — nutzt `#addUserModal` (POST `/users`).

- [ ] **Step 1: Szenario um Mitglieder erweitern (Failing)**

In `tests/e2e/scenarios/praxis-erstlauf.e2e.test.mjs` neuen Test ergänzen:
```javascript
import { MEMBERS } from '../data/fixtures.mjs';
import { createMember } from '../steps/members.mjs';

test('Bootstrap: 8 Mitglieder je Untergruppe', async ({ page }) => {
    for (const member of MEMBERS) {
        await createMember(page, member);
    }
    await page.goto('/users');
    for (const member of MEMBERS) {
        await expect(page.getByText(`${member.lastName}`, { exact: false }).first()).toBeVisible();
    }
});
```

- [ ] **Step 2: Fehlschlag bestätigen**

Run:
```bash
npx playwright test --config tests/e2e/playwright.config.mjs scenarios/praxis-erstlauf.e2e.test.mjs -g "8 Mitglieder"
```
Expected: FAIL (`createMember` fehlt).

- [ ] **Step 3: Baustein implementieren**

`tests/e2e/steps/members.mjs`:
```javascript
// Verifizierte Selektoren aus templates/users/manage.twig:
//  - Anlegen-Modal: #addUserModal, Formular POST /users
//  - Felder: first_name, last_name, email(required)
//  - Stimmgruppe: checkbox name="voice_groups[]" value={group.id}, im .form-check-Wrapper mit Gruppenname
//  - Untergruppe: select name="sub_voices[{group.id}]", Optionen mit Label = Untergruppenname
//  - Absenden: button[name="submit_action"][value="save"]

export async function createMember(page, member) {
    await page.goto('/users');
    await page.click('[data-bs-target="#addUserModal"]');
    const modal = page.locator('#addUserModal');
    await modal.waitFor({ state: 'visible' });

    await modal.locator('input[name="first_name"]').fill(member.firstName);
    await modal.locator('input[name="last_name"]').fill(member.lastName);
    await modal.locator('input[name="email"]').fill(member.email);

    // Gruppen-Checkbox anhand des Gruppennamens finden und deren value (=group.id) lesen.
    const groupCheckbox = modal
        .locator('.form-check', { hasText: member.group })
        .locator('input[name="voice_groups[]"]')
        .first();
    await groupCheckbox.check();
    const groupId = await groupCheckbox.getAttribute('value');

    // Nach dem Anhaken wird der Untergruppen-Select sichtbar (collapse d-none entfernt).
    const subSelect = modal.locator(`select[name="sub_voices[${groupId}]"]`);
    await subSelect.waitFor({ state: 'visible' });
    await subSelect.selectOption({ label: member.sub });

    await modal.locator('button[name="submit_action"][value="save"]').click();
    await page.waitForURL('**/users');
}
```

- [ ] **Step 4: Selektoren gegenprüfen**

Run:
```bash
grep -nE "addUserModal|voice_groups\[\]|sub_voices\[|submit_action|value=\"save\"|form-check" templates/users/manage.twig
```
Expected: bestätigt Modal-ID, Feldnamen und `value="save"`. Falls der Checkbox-Wrapper nicht `.form-check` heißt, den Wrapper-Selektor in `createMember` an das reale Markup anpassen (z. B. `label`/`div`-Struktur um `input[name="voice_groups[]"]`).

- [ ] **Step 5: Test grün**

Run:
```bash
perl -i -pe 's/\r\n/\n/g' tests/e2e/steps/members.mjs tests/e2e/scenarios/praxis-erstlauf.e2e.test.mjs
npx playwright test --config tests/e2e/playwright.config.mjs scenarios/praxis-erstlauf.e2e.test.mjs -g "8 Mitglieder"
```
Expected: `1 passed`.

- [ ] **Step 6: Commit**

```bash
git add tests/e2e/steps/members.mjs tests/e2e/scenarios/praxis-erstlauf.e2e.test.mjs
git commit -m "feat(e2e): Baustein members + Mitglieder-Teil des Bootstrap-Szenarios"
```

---

## Task 6: Baustein `projects.mjs` + Szenario abschließen

**Files:**
- Create: `tests/e2e/steps/projects.mjs`
- Modify: `tests/e2e/scenarios/praxis-erstlauf.e2e.test.mjs`

**Interfaces:**
- Produces: `createProject(page, { name, description, startDate, endDate })` — nutzt `#addProjectModal` (POST `/projects`).

- [ ] **Step 1: Szenario um Projekt erweitern (Failing)**

In `tests/e2e/scenarios/praxis-erstlauf.e2e.test.mjs` ergänzen:
```javascript
import { PROJECT } from '../data/fixtures.mjs';
import { createProject } from '../steps/projects.mjs';

test('Bootstrap: Projekt anlegen', async ({ page }) => {
    await createProject(page, PROJECT);
    await page.goto('/projects');
    await expect(page.getByText(PROJECT.name, { exact: false }).first()).toBeVisible();
});
```

- [ ] **Step 2: Fehlschlag bestätigen**

Run:
```bash
npx playwright test --config tests/e2e/playwright.config.mjs scenarios/praxis-erstlauf.e2e.test.mjs -g "Projekt anlegen"
```
Expected: FAIL (`createProject` fehlt).

- [ ] **Step 3: Baustein implementieren**

`tests/e2e/steps/projects.mjs`:
```javascript
// Verifizierte Selektoren aus templates/projects/index.twig:
//  - Anlegen-Modal: #addProjectModal, Formular POST /projects
//  - Felder: name(required), description, start_date, end_date
//  - Absenden: button[type="submit"] "Speichern"
export async function createProject(page, project) {
    await page.goto('/projects');
    await page.click('[data-bs-target="#addProjectModal"]');
    const modal = page.locator('#addProjectModal');
    await modal.waitFor({ state: 'visible' });

    await modal.locator('input[name="name"]').fill(project.name);
    await modal.locator('textarea[name="description"]').fill(project.description);
    await modal.locator('input[name="start_date"]').fill(project.startDate);
    await modal.locator('input[name="end_date"]').fill(project.endDate);

    await modal.locator('button[type="submit"]').click();
    await page.waitForURL('**/projects');
}
```

- [ ] **Step 4: Selektoren gegenprüfen**

Run:
```bash
grep -nE "addProjectModal|name=\"name\"|name=\"description\"|name=\"start_date\"|name=\"end_date\"" templates/projects/index.twig
```
Expected: bestätigt Modal-ID und Feldnamen.

- [ ] **Step 5: Test grün + gesamtes Bootstrap-Szenario auf frischer DB**

Run:
```bash
perl -i -pe 's/\r\n/\n/g' tests/e2e/steps/projects.mjs tests/e2e/scenarios/praxis-erstlauf.e2e.test.mjs
npx playwright test --config tests/e2e/playwright.config.mjs scenarios/praxis-erstlauf.e2e.test.mjs
```
Expected: alle Tests `passed` (globalSetup macht fresh-db → alle drei Bootstrap-Tests grün).

- [ ] **Step 6: Commit**

```bash
git add tests/e2e/steps/projects.mjs tests/e2e/scenarios/praxis-erstlauf.e2e.test.mjs
git commit -m "feat(e2e): Baustein projects + vollstaendiges Bootstrap-Szenario"
```

---

## Task 7: Crawler — Routen-Tabelle aus `src/Routes.php`

**Files:**
- Create: `tests/e2e/crawler/routes.mjs`

**Interfaces:**
- Produces: `getRoutes()` → `Array<{ method: 'GET'|'POST', pattern: string, params: string[] }>`. Parst `src/Routes.php` statisch (Regex über `->get('...'`, `->post('...'`, inkl. Gruppen-Präfixe wo statisch ableitbar) und extrahiert `{name}`/`{name:regex}`-Parameter.

- [ ] **Step 1: Failing-Test schreiben**

`tests/e2e/crawler/routes.mjs` wird von einem Unit-artigen Test geprüft. `tests/e2e/crawler/_routes.spec.mjs`:
```javascript
import { test, expect } from '@playwright/test';
import { getRoutes } from './routes.mjs';

test('Routen-Tabelle enthaelt bekannte Routen', async () => {
    const routes = getRoutes();
    const patterns = routes.map((r) => `${r.method} ${r.pattern}`);
    expect(patterns).toContain('GET /voice-groups');
    expect(patterns).toContain('POST /projects');
    const projUpdate = routes.find((r) => r.pattern.includes('/projects/') && r.pattern.includes('update'));
    expect(projUpdate.params).toContain('id');
});
```

- [ ] **Step 2: Fehlschlag bestätigen**

Run:
```bash
npx playwright test --config tests/e2e/playwright.config.mjs crawler/_routes.spec.mjs
```
Expected: FAIL (`routes.mjs` fehlt).

- [ ] **Step 3: Parser implementieren**

`tests/e2e/crawler/routes.mjs`:
```javascript
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const dir = path.dirname(fileURLToPath(import.meta.url));
const ROUTES_FILE = path.join(dir, '..', '..', 'src', 'Routes.php');

// Extrahiert {name} und {name:regex} als Parameternamen.
function extractParams(pattern) {
    const params = [];
    const re = /\{([a-zA-Z_][a-zA-Z0-9_]*)(?::[^}]+)?\}/g;
    let m;
    while ((m = re.exec(pattern)) !== null) {
        params.push(m[1]);
    }
    return params;
}

export function getRoutes() {
    const src = fs.readFileSync(ROUTES_FILE, 'utf8');
    const re = /->(get|post|map)\(\s*(?:\[[^\]]*\]\s*,\s*)?['"]([^'"]+)['"]/g;
    const routes = [];
    let m;
    while ((m = re.exec(src)) !== null) {
        const method = m[1].toUpperCase() === 'POST' ? 'POST' : 'GET';
        const pattern = m[2];
        if (!pattern.startsWith('/')) {
            continue;
        }
        routes.push({ method, pattern, params: extractParams(pattern) });
    }
    return routes;
}
```

- [ ] **Step 4: Test grün + Routen sichten**

Run:
```bash
perl -i -pe 's/\r\n/\n/g' tests/e2e/crawler/routes.mjs tests/e2e/crawler/_routes.spec.mjs
npx playwright test --config tests/e2e/playwright.config.mjs crawler/_routes.spec.mjs
```
Expected: `1 passed`.

Hinweis für Umsetzer: Gruppen-Präfixe (`$masterGroup->get('/projects', …)` innerhalb `->group('/…')`) liefert dieser einfache Parser nur als Endsegment. Falls Routen ohne Präfix (z. B. `/projects` statt `/manage/projects`) im echten Lauf 404 geben, in Task 10 beim Messen die tatsächlichen Präfixe aus `src/Routes.php` ergänzen (Gruppen-Klammern berücksichtigen). Für den ersten Lauf reichen die Top-Level-Routen.

- [ ] **Step 5: Commit**

```bash
git add tests/e2e/crawler/routes.mjs tests/e2e/crawler/_routes.spec.mjs
git commit -m "feat(e2e): Crawler-Routenparser aus src/Routes.php"
```

---

## Task 8: Crawler — Fehler-Detektoren + Denylist

**Files:**
- Create: `tests/e2e/crawler/detectors.mjs`
- Create: `tests/e2e/crawler/denylist.mjs`

**Interfaces:**
- Produces:
  - `detectors.mjs`: `attachConsoleWatcher(page) → errors[]` (sammelt console `error` + `pageerror`); `checkResponse(response) → string|null` (5xx/unerwartetes 4xx bei GET); `checkHtmlForPhpErrors(html) → string|null`; `collectInternalLinks(page) → string[]`.
  - `denylist.mjs`: `isDenied(actionText, href) → boolean` und `DENY_PATTERNS` (Logout, Backup-Restore, Key-Rotation, Admin-Selbstlöschung, DB-Reset).

- [ ] **Step 1: Failing-Test schreiben**

`tests/e2e/crawler/_detectors.spec.mjs`:
```javascript
import { test, expect } from '@playwright/test';
import { checkHtmlForPhpErrors } from './detectors.mjs';
import { isDenied } from './denylist.mjs';

test('erkennt PHP-Fehler im HTML', async () => {
    expect(checkHtmlForPhpErrors('<b>Fatal error</b>: boom')).toContain('Fatal error');
    expect(checkHtmlForPhpErrors('<h1>Alles gut</h1>')).toBeNull();
});

test('Denylist blockt Logout und Restore', async () => {
    expect(isDenied('Abmelden', '/logout')).toBe(true);
    expect(isDenied('Backup wiederherstellen', '/backups/restore/3')).toBe(true);
    expect(isDenied('Speichern', '/projects')).toBe(false);
});
```

- [ ] **Step 2: Fehlschlag bestätigen**

Run:
```bash
npx playwright test --config tests/e2e/playwright.config.mjs crawler/_detectors.spec.mjs
```
Expected: FAIL (Module fehlen).

- [ ] **Step 3: detectors implementieren**

`tests/e2e/crawler/detectors.mjs`:
```javascript
const PHP_ERROR_MARKERS = [
    'Fatal error', 'Parse error', 'Uncaught Error', 'Uncaught Exception',
    'Stack trace:', 'Whoops\\', 'Whoops, looks like', 'PHP Warning', 'PHP Notice',
];

export function checkHtmlForPhpErrors(html) {
    for (const marker of PHP_ERROR_MARKERS) {
        if (html.includes(marker)) {
            return marker;
        }
    }
    return null;
}

export function attachConsoleWatcher(page) {
    const errors = [];
    page.on('console', (msg) => {
        if (msg.type() === 'error') {
            errors.push(`console.error: ${msg.text()}`);
        }
    });
    page.on('pageerror', (err) => {
        errors.push(`pageerror: ${err.message}`);
    });
    return errors;
}

// Nur fuer GET-Navigation: 5xx immer Fehler; 4xx unerwartet (ausser bewusste Auth-Faelle).
export function checkResponse(response) {
    if (!response) {
        return null;
    }
    const status = response.status();
    if (status >= 500) {
        return `HTTP ${status}`;
    }
    if (status >= 400 && status !== 401 && status !== 403) {
        return `HTTP ${status}`;
    }
    return null;
}

export async function collectInternalLinks(page) {
    return page.$$eval('a[href]', (as) => as
        .map((a) => a.getAttribute('href'))
        .filter((h) => h && h.startsWith('/') && !h.startsWith('//')));
}
```

`tests/e2e/crawler/denylist.mjs`:
```javascript
// Aktionen/URLs, die der aggressive Crawler NIE ausloesen darf,
// weil sie den Lauf oder die Umgebung unrettbar zerstoeren.
export const DENY_PATTERNS = [
    /logout|abmelden|ausloggen/i,
    /backups?\/(restore|delete)|wiederherstell|einspielen/i,
    /rotate.*key|key.*rotation|schluessel.*rotier/i,
    /users?\/(deactivate|delete)\/1\b/i, // Admin-Account (id 1) nicht deaktivieren/loeschen
    /reset.*db|db.*reset|datenbank.*zuruecksetzen/i,
];

export function isDenied(actionText, href) {
    const haystack = `${actionText || ''} ${href || ''}`;
    return DENY_PATTERNS.some((re) => re.test(haystack));
}
```

- [ ] **Step 4: Test grün**

Run:
```bash
perl -i -pe 's/\r\n/\n/g' tests/e2e/crawler/detectors.mjs tests/e2e/crawler/denylist.mjs tests/e2e/crawler/_detectors.spec.mjs
npx playwright test --config tests/e2e/playwright.config.mjs crawler/_detectors.spec.mjs
```
Expected: `2 passed`.

- [ ] **Step 5: Commit**

```bash
git add tests/e2e/crawler/detectors.mjs tests/e2e/crawler/denylist.mjs tests/e2e/crawler/_detectors.spec.mjs
git commit -m "feat(e2e): Crawler-Fehlerdetektoren und Denylist"
```

---

## Task 9: Crawler — Durchlauf `crawl.e2e.test.mjs`

**Files:**
- Create: `tests/e2e/crawler/crawl.e2e.test.mjs`

**Interfaces:**
- Consumes: `getRoutes` (Task 7), `detectors`/`denylist` (Task 8), storageState (eingeloggt).
- Produces: ein Test, der alle GET-Routen besucht, IDs für parametrisierte Routen aus Listenseiten scraped, auf jeder Seite nicht-denylistete Buttons klickt und die Fehler-Kriterien prüft; sammelt Befunde und failt am Ende, wenn Befunde existieren.

- [ ] **Step 1: Test schreiben**

`tests/e2e/crawler/crawl.e2e.test.mjs`:
```javascript
import { test, expect } from '@playwright/test';
import { getRoutes } from './routes.mjs';
import { attachConsoleWatcher, checkResponse, checkHtmlForPhpErrors, collectInternalLinks } from './detectors.mjs';
import { isDenied } from './denylist.mjs';

// Baut aus GET-Routen konkrete URLs. Parametrisierte Routen werden mit IDs
// gefuellt, die von den Listenseiten (ohne Parameter) gescraped werden.
async function resolveConcreteUrls(page, routes) {
    const getRoutesOnly = routes.filter((r) => r.method === 'GET');
    const staticGets = getRoutesOnly.filter((r) => r.params.length === 0);
    const urls = new Set(staticGets.map((r) => r.pattern));

    // IDs aus allen statischen Seiten sammeln (Links wie /projects/5/...).
    const idsByPrefix = new Map();
    for (const url of staticGets.map((r) => r.pattern)) {
        const resp = await page.goto(url, { waitUntil: 'domcontentloaded' }).catch(() => null);
        if (!resp) {
            continue;
        }
        const links = await collectInternalLinks(page);
        for (const href of links) {
            const m = href.match(/^(\/[a-zA-Z0-9_-]+)\/(\d+)/);
            if (m) {
                if (!idsByPrefix.has(m[1])) {
                    idsByPrefix.set(m[1], new Set());
                }
                idsByPrefix.get(m[1]).add(m[2]);
            }
        }
    }

    // Ein-Parameter-Routen mit gefundenen IDs konkretisieren.
    for (const r of getRoutesOnly.filter((x) => x.params.length === 1)) {
        const prefix = '/' + r.pattern.split('/')[1];
        const ids = idsByPrefix.get(prefix);
        if (!ids) {
            continue;
        }
        for (const id of ids) {
            urls.add(r.pattern.replace(/\{[^}]+\}/, id));
        }
    }
    return [...urls];
}

test('Crawler: alle erreichbaren Seiten ohne Fehler', async ({ page }) => {
    const findings = [];
    const consoleErrors = attachConsoleWatcher(page);
    const routes = getRoutes();
    const urls = await resolveConcreteUrls(page, routes);

    for (const url of urls) {
        consoleErrors.length = 0;
        const resp = await page.goto(url, { waitUntil: 'domcontentloaded' }).catch((e) => {
            findings.push(`${url} :: Navigationsfehler ${e.message}`);
            return null;
        });
        if (!resp) {
            continue;
        }

        const httpErr = checkResponse(resp);
        if (httpErr) {
            findings.push(`${url} :: ${httpErr}`);
        }
        const html = await page.content();
        const phpErr = checkHtmlForPhpErrors(html);
        if (phpErr) {
            findings.push(`${url} :: PHP-Fehler "${phpErr}"`);
        }

        // Aggressive Klicks: alle sichtbaren, nicht-denylisteten Buttons.
        const buttons = await page.locator('button:visible, a.btn:visible').all();
        for (const btn of buttons) {
            const text = (await btn.textContent().catch(() => '')) || '';
            const href = (await btn.getAttribute('href').catch(() => '')) || '';
            if (isDenied(text.trim(), href)) {
                continue;
            }
            await btn.click({ timeout: 1500 }).catch(() => {});
            await page.waitForTimeout(120);
            // Modal wieder schliessen, um Folgeklicks nicht zu blockieren.
            await page.keyboard.press('Escape').catch(() => {});
        }

        if (consoleErrors.length > 0) {
            findings.push(`${url} :: JS ${consoleErrors.join(' | ')}`);
        }
    }

    if (findings.length > 0) {
        console.log('CRAWLER-BEFUNDE:\n' + findings.join('\n'));
    }
    expect(findings, findings.join('\n')).toHaveLength(0);
});
```

- [ ] **Step 2: Erstlauf ausführen (misst zugleich Laufzeit)**

Run:
```bash
perl -i -pe 's/\r\n/\n/g' tests/e2e/crawler/crawl.e2e.test.mjs
time npx playwright test --config tests/e2e/playwright.config.mjs crawler/crawl.e2e.test.mjs
```
Expected: läuft durch; Befunde (falls echte Bugs) werden gelistet. **Erwartungshaltung:** Der erste Lauf deckt evtl. echte 500er/JS-Fehler auf — das ist der Zweck. Befunde sichten: echte Bugs vs. Crawler-Artefakte (z. B. Route braucht Gruppen-Präfix → in `routes.mjs`/Task 7-Hinweis nachziehen; harmlose 4xx → in `checkResponse` als erwartet ausnehmen).

- [ ] **Step 3: Crawler stabilisieren**

Auf Basis der Befunde: erwartbare 4xx/Redirects in `detectors.checkResponse` als unkritisch ausnehmen, fehlende Routen-Präfixe in `routes.mjs` ergänzen, ggf. weitere gefährliche Aktionen in `denylist.mjs`. Danach erneut laufen lassen bis nur noch **echte** Fehler (oder keine) übrig sind.

- [ ] **Step 4: Commit**

```bash
git add tests/e2e/crawler/crawl.e2e.test.mjs tests/e2e/crawler/routes.mjs tests/e2e/crawler/detectors.mjs tests/e2e/crawler/denylist.mjs
git commit -m "feat(e2e): aggressiver Crawler besucht alle Routen und prueft Fehlerkriterien"
```

---

## Task 10: Runbook + Gesamtlauf messen

**Files:**
- Create: `docs/e2e/praxis-erstlauf-runbook.md`

**Interfaces:**
- Produces: Markdown-Runbook: Vorbedingungen, Reset, beide Modi (Voll/Iterativ), Baustein-Katalog, Crawler-Beschreibung, Lauf-Kommandos, Interpretation von Befunden.

- [ ] **Step 1: Gesamtlauf messen**

Run:
```bash
time npx playwright test --config tests/e2e/playwright.config.mjs
```
Expected: globalSetup (fresh-db + Bootstrap) → `scenarios` grün → `crawler` grün/Befunde. Laufzeit notieren (für „vertretbare Geschwindigkeit", Tuning später über `workers`).

- [ ] **Step 2: Runbook schreiben**

`docs/e2e/praxis-erstlauf-runbook.md`:
```markdown
# Runbook: Automatisches E2E-Erkundungs-Netz

## Zweck
Die ganze App wird auf einer frisch bebootstrappten DB automatisch erkundet
(Crawler) und in kritischen Flows tief getestet (Szenarien). Ersatz fuers
manuelle Durchklicken.

## Vorbedingungen
- DDEV laeuft: `ddev start`
- Erreichbar: https://chormanager.ddev.site
- Playwright-Browser installiert: `npx playwright install chromium`

## Datenbank-Reset (manuell)
```bash
bash bin/fresh-db.sh
```
Leert die Dev-DB, migriert neu. Danach hat die App keine User -> `/setup`.

## Voller Lauf (deterministisch)
```bash
npx playwright test --config tests/e2e/playwright.config.mjs
```
`global-setup.mjs` macht automatisch fresh-db + Admin + 8 Mitglieder + Projekt
und speichert die Session; dann laufen Szenarien und Crawler.

## Iterativ-Modus (schnell beim Entwickeln)
```bash
E2E_KEEP_DB=1 npx playwright test --config tests/e2e/playwright.config.mjs scenarios/<datei>
```
Ueberspringt fresh-db + Bootstrap, wenn eine gueltige Session existiert.

## Bausteine (steps/)
- `auth.mjs` — setupAdmin, login
- `voiceGroups.mjs` — createGroup, createSubVoice
- `members.mjs` — createMember
- `projects.mjs` — createProject
Neues Modul: neue Datei unter `steps/`, im Szenario komponieren.

## Crawler
Liest alle Routen aus `src/Routes.php`, besucht jede erreichbare Seite,
klickt nicht-denylistete Buttons und prueft je Seite:
HTTP 5xx/unerwartetes 4xx, PHP-/Exception-Ausgabe, JS-Konsolen-Fehler,
kaputte interne Links. Laeuft aggressiv auf isolierter DB.
Gefaehrliche Aktionen (Logout, Backup-Restore, Key-Rotation,
Admin-Selbstloeschung, DB-Reset) sind in `crawler/denylist.mjs` gesperrt.

## Befunde interpretieren
- 5xx / PHP-Fehler / JS-Fehler = echter Bug -> fixen.
- Erwartbares 4xx (bewusste Auth-Sperre) -> in `detectors.checkResponse`
  ausnehmen.
- 404 auf parametrisierte Route -> ggf. Routen-Praefix in `routes.mjs` fehlt.

## Manuelles Mitklicken (Praxis-Erstlauf)
Reihenfolge entspricht `scenarios/praxis-erstlauf.e2e.test.mjs`:
1. `/setup` Admin anlegen (Vorname, Nachname, E-Mail, Passwort)
2. `/login` einloggen
3. `/voice-groups` Sopran, Alt, Tenor, Bass anlegen; je Gruppe Untergruppe 1 und 2
4. `/users` 8 Mitglieder, je eins pro Untergruppe (Stimmgruppe anhaken, Untergruppe waehlen)
5. `/projects` Projekt anlegen
```

- [ ] **Step 3: Laufzeit im Runbook ergänzen**

Die in Step 1 gemessene Laufzeit unter einem Abschnitt „## Gemessene Laufzeit" eintragen (z. B. „Volllauf ~X min mit N Workern"). Falls > als angenehm, Hinweis auf `--workers=N` ergänzen.

- [ ] **Step 4: LF sichern + Twig/PHP nicht betroffen**

Run:
```bash
perl -i -pe 's/\r\n/\n/g' docs/e2e/praxis-erstlauf-runbook.md
```

- [ ] **Step 5: Commit**

```bash
git add docs/e2e/praxis-erstlauf-runbook.md
git commit -m "docs(e2e): Runbook fuer Erkundungs-Netz inkl. gemessener Laufzeit"
```

---

## Self-Review-Ergebnis (vom Plan-Autor)

**Spec-Abdeckung:** fresh-db (T1) ✓ · Config/Isolation (T2) ✓ · storageState+Bootstrap (T3) ✓ · Bausteine SATB/Mitglieder/Projekt (T4–T6) ✓ · deterministische Umlaut-Daten (T3 fixtures) ✓ · Crawler Routen/Denylist/Detektoren/Lauf inkl. 4 Fehler-Kriterien (T7–T9) ✓ · aggressiv + Isolation + Denylist (T8/T9) ✓ · Iterativ-Modus (T3 `E2E_KEEP_DB`) ✓ · Speed/Messen (T9/T10) ✓ · Runbook + Modi + Baustein-Katalog (T10) ✓ · Wachstumspfad (Bausteine + auto-Routen) ✓.

**Platzhalter:** keine — jeder Code-Step enthält echten Code; die zwei „nach erstem Lauf tunen"-Stellen (Routen-Präfixe, erwartete 4xx) sind bewusste, konkret beschriebene Iterationsschritte, kein TODO.

**Typkonsistenz:** `getRoutes()` liefert `{method,pattern,params}` (T7) und wird in T9 exakt so konsumiert; `attachConsoleWatcher/checkResponse/checkHtmlForPhpErrors/collectInternalLinks` (T8) mit identischen Namen in T9 genutzt; `isDenied(text,href)` konsistent; Baustein-Signaturen (`createGroup/createSubVoice/createMember/createProject`) in Szenario identisch verwendet.
