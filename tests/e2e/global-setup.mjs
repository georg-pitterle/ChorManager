import { chromium } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { ADMIN } from './data/fixtures.mjs';
import { setupAdmin, login } from './steps/auth.mjs';
import { resolveBash } from './steps/shell.mjs';
import { AUTH_FILE } from './playwright.config.mjs';

const dir = path.dirname(fileURLToPath(import.meta.url));
const BASE_URL = 'https://chormanager.ddev.site';

export default async function globalSetup() {
    const keepDb = process.env.E2E_KEEP_DB === '1';

    if (keepDb && fs.existsSync(AUTH_FILE)) {
        console.log('[e2e] E2E_KEEP_DB=1 und Session vorhanden -> überspringe Bootstrap.');
        return;
    }

    // Wir bootstrappen -> immer fresh-db, damit /setup gegen eine leere DB läuft
    // (auch bei E2E_KEEP_DB=1 ohne vorhandene Session).
    console.log('[e2e] fresh-db ...');
    const repoRoot = path.join(dir, '..', '..');
    const freshDbScript = path.join(repoRoot, 'bin', 'fresh-db.sh');
    try {
        // cwd explizit auf den Projekt-Root setzen: im --ui-Modus startet Playwright den
        // globalSetup u. U. mit einem anderen Arbeitsverzeichnis, wodurch `ddev` sein Projekt
        // nicht findet. Ausgabe erfassen statt 'inherit', damit die echte Fehlerursache
        // (z. B. eine ddev-Meldung) im Fehlerfall sichtbar ist - 'inherit' verschluckt sie.
        const out = execFileSync(resolveBash(), [freshDbScript], {
            cwd: repoRoot,
            encoding: 'utf8',
            stdio: ['ignore', 'pipe', 'pipe'],
        });
        process.stdout.write(out);
    } catch (e) {
        process.stdout.write((e.stdout || '').toString());
        process.stderr.write((e.stderr || '').toString());
        throw new Error(
            `[e2e] fresh-db.sh fehlgeschlagen (cwd=${repoRoot}).\n`
            + `Meldung: ${e.message}\n`
            + `Exit-Code: ${e.status ?? '(keiner)'}  Spawn-Fehler-Code: ${e.code ?? '(keiner)'}\n`
            + `stderr:\n${((e.stderr || '').toString().trim()) || '(leer)'}\n`
            + `stdout:\n${((e.stdout || '').toString().trim()) || '(leer)'}`
        );
    }

    fs.mkdirSync(path.dirname(AUTH_FILE), { recursive: true });

    const browser = await chromium.launch();
    try {
        const context = await browser.newContext({ baseURL: BASE_URL, ignoreHTTPSErrors: true });
        const page = await context.newPage();

        await setupAdmin(page, ADMIN);
        // Setup loggt bereits ein; Cookies leeren, damit login() den echten /login-Formularpfad
        // durchläuft (AuthController::showLogin leitet eine bereits authentifizierte Session
        // sofort nach /dashboard weiter, ohne das Formular zu rendern).
        await context.clearCookies();
        await login(page, { email: ADMIN.email, password: ADMIN.password });

        await context.storageState({ path: AUTH_FILE });
    } finally {
        await browser.close();
    }
    console.log('[e2e] Bootstrap fertig, Session gespeichert.');
}
