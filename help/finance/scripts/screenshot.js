'use strict';

/**
 * Erstellt Screenshots des Finanzbereichs (Kassabuch, Finanzauswertung, Budget)
 * für die How-To-Dokumentation (help/finance/docs/).
 *
 * Nutzung:
 *   ddev exec node help/finance/scripts/screenshot.js
 *
 * Optional per Umgebungsvariable überschreibbar:
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

// Normale Seiten: volle Seite inkl. Scroll-Inhalt.
async function shot(page, name) {
    const filePath = path.join(IMAGES_DIR, `${name}.png`);
    await page.screenshot({ path: filePath, fullPage: true });
    console.log(`gespeichert: ${path.relative(process.cwd(), filePath)}`);
}

// Bei offenem Modal: EIN Viewport-Bild (kein fullPage, kein Scroll-Stitching).
async function shotModal(page, name) {
    const filePath = path.join(IMAGES_DIR, `${name}.png`);
    await page.screenshot({ path: filePath, fullPage: false });
    console.log(`gespeichert: ${path.relative(process.cwd(), filePath)}`);
}

// Lange Listen/Seiten: Kopf + einige Zeilen, auf maxHeight beschnitten.
async function shotCapped(page, name, maxHeight = 1500) {
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

// Warten auf das Ende der Bootstrap-Transition eines Modals/Collapse per Event.
async function clickAndWaitForEvent(page, triggerLocator, eventTargetSelector, eventName) {
    const eventPromise = page.evaluate(
        ({ selector, name }) => new Promise((resolve) => {
            document.querySelector(selector).addEventListener(name, () => resolve(), { once: true });
        }),
        { selector: eventTargetSelector, name: eventName }
    );
    await triggerLocator.click();
    await eventPromise;
}

async function main() {
    fs.mkdirSync(IMAGES_DIR, { recursive: true });

    const browser = await chromium.launch();
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

        // === Kassabuch ===

        // 01. Kassabuch (Buchungsliste) - lange Liste, beschnitten.
        await page.goto(`${BASE_URL}/finances`, { waitUntil: 'networkidle' });
        await page.locator('#financesTable').waitFor({ state: 'visible' });
        await shotCapped(page, '01-kassabuch');

        // 02. Modal: Neuer Eintrag
        await clickAndWaitForEvent(
            page,
            page.locator('[data-bs-target="#financeModal"]'),
            '#financeModal',
            'shown.bs.modal'
        );
        await shotModal(page, '02-new-entry-modal');
        await clickAndWaitForEvent(
            page,
            page.locator('#financeModal .btn-secondary[data-bs-dismiss="modal"]'),
            '#financeModal',
            'hidden.bs.modal'
        );

        // 03. Modal: Kassabuch-Konfiguration (Geschäftsjahr-Beginn)
        await clickAndWaitForEvent(
            page,
            page.locator('[data-bs-target="#settingsModal"]'),
            '#settingsModal',
            'shown.bs.modal'
        );
        await shotModal(page, '03-settings-modal');
        await clickAndWaitForEvent(
            page,
            page.locator('#settingsModal .btn-secondary[data-bs-dismiss="modal"]'),
            '#settingsModal',
            'hidden.bs.modal'
        );

        // 04. Finanzauswertung (Kennzahlen, Zahlungsart, Gruppen). Oben rechts der
        //     Button "PDF herunterladen". Timeline darunter ist sehr lang -> beschnitten.
        await page.goto(`${BASE_URL}/finances/report`, { waitUntil: 'networkidle' });
        await page.locator('.finances-report').waitFor({ state: 'visible' });
        await shotCapped(page, '04-report', 1700);

        // === Budget ===

        // 05. Budget-Übersicht (Einnahmen/Ausgaben, Kategorien eingeklappt).
        await page.goto(`${BASE_URL}/budget`, { waitUntil: 'networkidle' });
        await page.locator('.dashboard-shell').waitFor({ state: 'visible' });
        await shotCapped(page, '05-budget-overview', 1600);

        // 06. Kategorie aufklappen -> Posten-Tabelle sichtbar.
        const firstHeader = page.locator('.budget-category-header').first();
        const collapseSelector = await firstHeader.getAttribute('data-bs-target');
        await clickAndWaitForEvent(page, firstHeader, collapseSelector, 'shown.bs.collapse');
        await shotCapped(page, '06-budget-category-expanded', 1600);

        // 07. Modal: Posten hinzufügen (innerhalb der aufgeklappten Kategorie).
        const addItemBtn = page.locator(`${collapseSelector} button[data-bs-target^="#modal-create-item-"]`);
        const itemModalSelector = await addItemBtn.getAttribute('data-bs-target');
        await clickAndWaitForEvent(page, addItemBtn, itemModalSelector, 'shown.bs.modal');
        await shotModal(page, '07-budget-new-item-modal');
        await clickAndWaitForEvent(
            page,
            page.locator(`${itemModalSelector} .btn-secondary[data-bs-dismiss="modal"]`),
            itemModalSelector,
            'hidden.bs.modal'
        );

        // 08. Modal: Neue Budgetkategorie
        await clickAndWaitForEvent(
            page,
            page.locator('[data-bs-target="#modal-create-category"]'),
            '#modal-create-category',
            'shown.bs.modal'
        );
        await shotModal(page, '08-budget-new-category-modal');
        await clickAndWaitForEvent(
            page,
            page.locator('#modal-create-category .btn-secondary[data-bs-dismiss="modal"]'),
            '#modal-create-category',
            'hidden.bs.modal'
        );

        console.log('Fertig: alle Finanz-Screenshots erstellt.');
    } finally {
        await browser.close();
    }
}

main().catch((err) => {
    console.error(err);
    process.exitCode = 1;
});
