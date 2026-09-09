'use strict';

/**
 * Erstellt Screenshots der Auswertungen (Anwesenheitsquoten, Anmelde-Auswertung,
 * Projektmitglieder) für die How-To-Dokumentation (help/evaluations/docs/).
 *
 * Nutzung:
 *   node help/evaluations/scripts/screenshot.js
 *
 * Optional per Umgebungsvariable ueberschreibbar:
 *   BASE_URL   Basis-URL der Dev-Umgebung (Default: https://chormanager.ddev.site)
 *   LOGIN_EMAIL, LOGIN_PASSWORD  Seed-Anmeldedaten
 */

const path = require('path');
const fs = require('fs');
const { chromium } = require('playwright');

const BASE_URL = process.env.BASE_URL || 'https://chormanager.ddev.site';
const LOGIN_EMAIL = process.env.LOGIN_EMAIL || 'seed.001@chor.local';
const LOGIN_PASSWORD = process.env.LOGIN_PASSWORD || 'seed';
const IMAGES_DIR = path.join(__dirname, '..', 'screenshots');

const VIEWPORT = { width: 1440, height: 900 };

/**
 * Die Auswertungen listen im Seed über hundert Zeilen. Ein fullPage-Bild waere
 * mehrere tausend Pixel hoch; Kopfbereich, Projektauswahl und einige Zeilen
 * zeigen den Aufbau vollstaendig.
 */
async function shotCapped(page, name, maxHeight = 1400) {
    const fullHeight = await page.evaluate(() => Math.ceil(document.documentElement.scrollHeight));
    const height = Math.min(fullHeight, maxHeight);
    const filePath = path.join(IMAGES_DIR, `${name}.png`);
    await page.screenshot({ path: filePath, clip: { x: 0, y: 0, width: VIEWPORT.width, height } });
    console.log(`gespeichert: ${path.relative(process.cwd(), filePath)} (${VIEWPORT.width}x${height})`);
}

async function login(page) {
    await page.goto(`${BASE_URL}/login`, { waitUntil: 'networkidle' });
    await page.locator('#emailInput').fill(LOGIN_EMAIL);
    await page.locator('#passwordInput').fill(LOGIN_PASSWORD);
    await Promise.all([
        page.waitForURL((url) => !url.pathname.startsWith('/login'), { waitUntil: 'networkidle' }),
        page.locator('form[action="/login"] button[type="submit"]').click(),
    ]);
}

async function main() {
    fs.mkdirSync(IMAGES_DIR, { recursive: true });

    const browser = await chromium.launch({ args: ['--ignore-certificate-errors'] });
    const context = await browser.newContext({
        viewport: VIEWPORT,
        isMobile: false,
        hasTouch: false,
        deviceScaleFactor: 1,
        ignoreHTTPSErrors: true,
    });
    const page = await context.newPage();

    try {
        await login(page);

        // 1. Anwesenheitsquoten
        await page.goto(`${BASE_URL}/evaluations`, { waitUntil: 'networkidle' });
        await page.locator('#evaluationsTable').waitFor({ state: 'visible' });
        await shotCapped(page, '01-attendance-rates');

        // 2. Anmelde-Auswertung, nur kommende Termine
        await page.goto(`${BASE_URL}/evaluations/registrations`, { waitUntil: 'networkidle' });
        await page.locator('table').first().waitFor({ state: 'visible' });
        await shotCapped(page, '02-registrations');

        // 3. Dieselbe Auswertung mit vergangenen Terminen: nur dort steht in der
        // Spalte "Anwesend (Ist)" ueberhaupt ein Wert.
        await page.goto(`${BASE_URL}/evaluations/registrations?include_past=1`, { waitUntil: 'networkidle' });
        await page.locator('table').first().waitFor({ state: 'visible' });
        await shotCapped(page, '03-registrations-past');

        // 4. Projektmitglieder nach Stimmgruppen
        await page.goto(`${BASE_URL}/evaluations/project-members`, { waitUntil: 'networkidle' });
        await page.locator('.table-shell').first().waitFor({ state: 'visible' });
        await shotCapped(page, '04-project-members');

        console.log('Fertig: alle Auswertungs-Screenshots erstellt.');
    } finally {
        await browser.close();
    }
}

main().catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
