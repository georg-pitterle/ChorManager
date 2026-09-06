'use strict';

/**
 * Erstellt Screenshots der Mitgliederverwaltung
 * für die How-To-Dokumentation (help/members/docs/).
 *
 * Nutzung:
 *   node help/members/scripts/screenshot.js
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

async function shot(page, name) {
    const filePath = path.join(IMAGES_DIR, `${name}.png`);
    await page.screenshot({ path: filePath, fullPage: true });
    console.log(`gespeichert: ${path.relative(process.cwd(), filePath)}`);
}

/**
 * Die Mitgliederliste hat im Seed über 100 Zeilen. Ein fullPage-Bild waere
 * mehrere tausend Pixel hoch und fuer die Doku unbrauchbar; Kopfbereich,
 * Filterleiste und einige Zeilen zeigen den Aufbau bereits vollstaendig.
 */
async function shotCapped(page, name, maxHeight = 1400) {
    const fullHeight = await page.evaluate(() => Math.ceil(document.documentElement.scrollHeight));
    const height = Math.min(fullHeight, maxHeight);
    const filePath = path.join(IMAGES_DIR, `${name}.png`);
    await page.screenshot({ path: filePath, clip: { x: 0, y: 0, width: VIEWPORT.width, height } });
    console.log(`gespeichert: ${path.relative(process.cwd(), filePath)} (${VIEWPORT.width}x${height})`);
}

/**
 * page.screenshot({ fullPage: true }) stitcht mehrere Scroll-Positionen
 * zusammen. Position:fixed-Elemente (Navbar, Modal-Backdrop, das Modal selbst)
 * werden dabei bei jeder Scroll-Position neu eingezeichnet und erscheinen im
 * Ergebnisbild mehrfach uebereinander. Bei offenem Modal daher immer ein
 * einfaches Viewport-Screenshot verwenden.
 */
async function shotModal(page, name) {
    const filePath = path.join(IMAGES_DIR, `${name}.png`);
    await page.screenshot({ path: filePath, fullPage: false });
    console.log(`gespeichert: ${path.relative(process.cwd(), filePath)}`);
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

/**
 * Bootstrap-Modals blenden per CSS-Transition ein. Der DOM-Zustand ist sofort
 * da, das Rendering erst nach Ende der Transition. Bootstrap feuert dafuer
 * eigene Events - darauf warten wir, statt auf eine feste Zeit zu setzen.
 */
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

async function openUsers(page, query = '') {
    await page.goto(`${BASE_URL}/users${query}`, { waitUntil: 'networkidle' });
    await page.locator('#usersTable').waitFor({ state: 'visible' });
}

/**
 * Setzt Suche, Filter und Gruppierung der Tabellenleiste zurueck.
 */
async function resetTable(page) {
    await page.locator('[data-table-reset]').click();
    await page.waitForFunction(
        () => Array.from(document.querySelectorAll('[data-table-plugin-slot] select'))
            .every((select) => select.value === '')
    );
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

        // 1. Mitgliederuebersicht mit Tabellenleiste
        await openUsers(page);
        await shotCapped(page, '01-list');

        // 2. Filter nach Stimme
        const voiceSelect = page.locator('[data-table-plugin-slot] select').nth(1);
        await voiceSelect.selectOption({ index: 1 });
        await page.waitForFunction(
            () => document.querySelectorAll('#usersTable tbody tr').length > 0
        );
        await shotCapped(page, '02-filter');

        // Der Tabellenzustand (Filter, Gruppierung) ueberlebt einen Seitenwechsel.
        // Vor jedem weiteren Bild deshalb zuruecksetzen, sonst zeigen die
        // folgenden Screenshots eine gefilterte Liste.
        await resetTable(page);

        // 3. Gruppierung nach Stimme, erste Gruppe aufgeklappt
        await page.getByRole('button', { name: 'Nach Stimme gruppieren' }).click();
        await page.getByRole('button', { name: 'Listenansicht' }).waitFor({ state: 'visible' });
        const firstGroup = page.locator('.accordion-button').first();
        await firstGroup.waitFor({ state: 'visible' });
        await firstGroup.click();
        await page.waitForFunction(
            () => document.querySelector('.accordion-collapse.show') !== null
        );
        await shotCapped(page, '03-grouped');
        await resetTable(page);

        // 4. Neues Mitglied anlegen
        await openUsers(page);
        await clickAndWaitForEvent(
            page,
            page.locator('[data-bs-target="#addUserModal"]'),
            '#addUserModal',
            'shown.bs.modal'
        );
        await shotModal(page, '04-new-member');
        await clickAndWaitForEvent(
            page,
            page.locator('#addUserModal [data-bs-dismiss="modal"]').first(),
            '#addUserModal',
            'hidden.bs.modal'
        );

        // 5. Mitglied bearbeiten (Formular wird nachgeladen)
        await clickAndWaitForEvent(
            page,
            page.locator('.js-edit-user').first(),
            '#editUserModal',
            'shown.bs.modal'
        );
        await page.locator('#editUserModal .modal-dialog form').waitFor({ state: 'visible' });
        await shotModal(page, '05-edit-member');
        await clickAndWaitForEvent(
            page,
            page.locator('#editUserModal [data-bs-dismiss="modal"]').first(),
            '#editUserModal',
            'hidden.bs.modal'
        );

        // 6. Projektteilnahmen eines Mitglieds
        const projectsButton = page.locator('[data-bs-target^="#userProjectsModal"]').first();
        const projectsTarget = await projectsButton.getAttribute('data-bs-target');
        await clickAndWaitForEvent(page, projectsButton, projectsTarget, 'shown.bs.modal');
        await shotModal(page, '06-projects');
        await clickAndWaitForEvent(
            page,
            page.locator(`${projectsTarget} [data-bs-dismiss="modal"]`).first(),
            projectsTarget,
            'hidden.bs.modal'
        );

        // 7. Archiv
        await openUsers(page, '?archived=1');
        await shotCapped(page, '07-archive');

        console.log('Fertig: alle Mitglieder-Screenshots erstellt.');
    } finally {
        await browser.close();
    }
}

main().catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
