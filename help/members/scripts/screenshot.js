'use strict';

/**
 * Erstellt Screenshots der Mitgliederverwaltung
 * für die How-To-Dokumentation (help/members/docs/).
 *
 * Nutzung:
 *   node help/members/scripts/screenshot.js
 *
 * Optional per Umgebungsvariable überschreibbar:
 *   BASE_URL   Basis-URL der Dev-Umgebung (Default: https://chormanager.ddev.site)
 *   LOGIN_EMAIL, LOGIN_PASSWORD  Seed-Anmeldedaten
 *
 * Das Skript ändert keine Daten: Dialoge werden nur geöffnet und geschlossen,
 * Zeilen nur angekreuzt.
 */

const path = require('path');
const fs = require('fs');
const { chromium } = require('playwright');
const { prepareSidebarForScreenshots } = require('../../screenshot-support');

const BASE_URL = process.env.BASE_URL || 'https://chormanager.ddev.site';
const LOGIN_EMAIL = process.env.LOGIN_EMAIL || 'seed.001@chor.local';
const LOGIN_PASSWORD = process.env.LOGIN_PASSWORD || 'seed';
const IMAGES_DIR = path.join(__dirname, '..', 'screenshots');

const VIEWPORT = { width: 1440, height: 900 };

/**
 * Die Mitgliederliste hat im Seed über 100 Zeilen. Ein fullPage-Bild wäre
 * mehrere tausend Pixel hoch und für die Doku unbrauchbar; Kopfbereich,
 * Filterleiste und einige Zeilen zeigen den Aufbau bereits vollständig.
 */
async function shotCapped(page, name, maxHeight = 1400) {
    const fullHeight = await page.evaluate(() => Math.ceil(document.documentElement.scrollHeight));
    const height = Math.min(fullHeight, maxHeight);
    const filePath = path.join(IMAGES_DIR, `${name}.png`);
    await page.screenshot({ path: filePath, fullPage: true, clip: { x: 0, y: 0, width: VIEWPORT.width, height } });
    console.log(`gespeichert: ${path.relative(process.cwd(), filePath)} (${VIEWPORT.width}x${height})`);
}

/**
 * page.screenshot({ fullPage: true }) stitcht mehrere Scroll-Positionen
 * zusammen. Position:fixed-Elemente (Navbar, Modal-Backdrop, das Modal selbst)
 * werden dabei bei jeder Scroll-Position neu eingezeichnet und erscheinen im
 * Ergebnisbild mehrfach übereinander. Bei offenem Modal, offenem Menü und
 * mitlaufender Auswahlleiste daher immer ein einfaches Viewport-Screenshot.
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
 * da, das Rendering erst nach Ende der Transition. Bootstrap feuert dafür
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

/**
 * Öffnet ein Bootstrap-Dropdown und wartet auf dessen Event (shown.bs.dropdown
 * wird am Toggle-Element ausgelöst).
 */
async function openDropdown(page, toggleLocator) {
    const shown = toggleLocator.evaluate(
        (el) => new Promise((resolve) => {
            el.addEventListener('shown.bs.dropdown', () => resolve(), { once: true });
        })
    );
    await toggleLocator.click();
    await shown;
}

async function closeDropdowns(page) {
    await page.keyboard.press('Escape');
    await page.waitForFunction(() => document.querySelector('.dropdown-menu.show') === null);
}

async function openUsers(page, query = '') {
    await page.goto(`${BASE_URL}/users${query}`, { waitUntil: 'networkidle' });
    await page.locator('#usersTable').waitFor({ state: 'visible' });
}

/**
 * Das Browserprofil kann eine Gruppierung oder Filter gespeichert haben.
 * Vor den Aufnahmen daher localStorage und sessionStorage leeren.
 */
async function clearStoredState(page) {
    await page.goto(`${BASE_URL}/users`, { waitUntil: 'networkidle' });
    await page.evaluate(() => {
        try {
            window.localStorage.clear();
            window.sessionStorage.clear();
        } catch (e) { /* ignorieren */ }
    });
}

async function main() {
    fs.mkdirSync(IMAGES_DIR, { recursive: true });
    // Alte Bilder weg, damit keine verwaisten Dateien liegen bleiben.
    for (const file of fs.readdirSync(IMAGES_DIR)) {
        if (file.endsWith('.png')) {
            fs.unlinkSync(path.join(IMAGES_DIR, file));
        }
    }

    const browser = await chromium.launch();
    const context = await browser.newContext({
        viewport: VIEWPORT,
        isMobile: false,
        hasTouch: false,
        deviceScaleFactor: 1,
        ignoreHTTPSErrors: true,
    });
    await prepareSidebarForScreenshots(context);
    const page = await context.newPage();

    try {
        await login(page);
        await clearStoredState(page);

        // 1. Mitgliederübersicht mit Seitenkopf, Tabellenleiste und Filtern
        await openUsers(page);
        await shotCapped(page, '01-list', 1000);

        // 2. Filter nach Stimme: der Zähler nennt "N von M Mitgliedern"
        const voiceSelect = page.locator('[data-table-plugin-slot] select[aria-label="Stimme filtern"]');
        await voiceSelect.selectOption({ index: 1 });
        await page.waitForFunction(
            () => /von/.test(document.querySelector('[data-table-result-count]').textContent)
        );
        await shotCapped(page, '02-filter', 1000);
        await voiceSelect.selectOption('');

        // 3. Menü "Ansicht"
        const viewToggle = page.locator('[data-table-view-toggle] > button');
        await openDropdown(page, viewToggle);
        await shotModal(page, '03-view-menu');
        await closeDropdowns(page);

        // 4. Gruppierung nach Stimme (Umschalter neben "Ansicht"). Die Gruppen
        // stehen von selbst offen; ein Klick würde sie zuklappen.
        await page.getByRole('button', { name: 'Nach Stimme', exact: true }).click();
        await page.waitForFunction(
            () => document.querySelectorAll('.users-group-accordion .accordion-collapse.show').length >= 2
                && document.querySelector('.users-group-accordion .collapsing') === null
        );
        await shotCapped(page, '04-grouped', 1100);
        await clearStoredState(page);

        // 5. Auswahl: drei Zeilen ankreuzen, die Leiste läuft beim Scrollen mit
        await openUsers(page);
        const checkboxes = page.locator('.user-row-select:not([disabled])');
        await checkboxes.nth(1).check();
        await checkboxes.nth(2).check();
        await checkboxes.nth(3).check();
        await page.locator('#userSelectionBar:not(.d-none)').waitFor({ state: 'visible' });
        await page.evaluate(() => window.scrollTo(0, 350));
        await page.waitForFunction(() => window.scrollY >= 300);
        await shotModal(page, '05-selection');
        await page.evaluate(() => window.scrollTo(0, 0));
        await page.locator('#clearUserSelection').click();

        // 6. Zeilenmenü mit gesperrtem "Archivieren" und Grund
        const blockedRow = page
            .locator('#usersTable tbody tr', { has: page.locator('.dropdown-menu .user-archive-hint') })
            .first();
        await blockedRow.scrollIntoViewIfNeeded();
        await openDropdown(page, blockedRow.locator('.dropdown-toggle-split'));
        await shotModal(page, '06-row-menu');
        await closeDropdowns(page);
        await page.evaluate(() => window.scrollTo(0, 0));

        // 7. Neues Mitglied anlegen
        await clickAndWaitForEvent(
            page,
            page.locator('[data-bs-target="#addUserModal"]'),
            '#addUserModal',
            'shown.bs.modal'
        );
        await shotModal(page, '07-new-member');
        await clickAndWaitForEvent(
            page,
            page.locator('#addUserModal [data-bs-dismiss="modal"]').first(),
            '#addUserModal',
            'hidden.bs.modal'
        );

        // 8. Mitglied bearbeiten, "Kontoaktivität" aufgeklappt. Höheres Fenster,
        // damit Projekte und Kontoaktivität mit ins Bild passen.
        await page.setViewportSize({ width: VIEWPORT.width, height: 1300 });
        await clickAndWaitForEvent(
            page,
            page.locator('.js-edit-user').first(),
            '#editUserModal',
            'shown.bs.modal'
        );
        await page.locator('#editUserModal .modal-dialog form').waitFor({ state: 'visible' });
        await page.locator('#editUserModal details', { hasText: 'Kontoaktivität' })
            .evaluate((el) => { el.open = true; });
        await page.locator('#editUserModal details.past-projects').evaluate((el) => { el.open = true; });
        await shotModal(page, '08-edit-member');
        await clickAndWaitForEvent(
            page,
            page.locator('#editUserModal [data-bs-dismiss="modal"]').first(),
            '#editUserModal',
            'hidden.bs.modal'
        );
        await page.setViewportSize(VIEWPORT);

        // 10. Archiv
        await openUsers(page, '?archived=1');
        await shotCapped(page, '10-archive', 900);

        // 11. Telefon: Filter hinter dem Knopf "Filter"
        const phone = await browser.newContext({
            viewport: { width: 390, height: 844 },
            isMobile: true,
            hasTouch: true,
            deviceScaleFactor: 2,
            ignoreHTTPSErrors: true,
        });
        const phonePage = await phone.newPage();
        await login(phonePage);
        await phonePage.goto(`${BASE_URL}/users`, { waitUntil: 'networkidle' });
        await phonePage.locator('[data-table-filter-toggle]').waitFor({ state: 'visible' });
        await phonePage.locator('[data-table-filter-toggle]').click();
        await phonePage.waitForFunction(
            () => document.querySelector('[data-table-filter-toggle]').getAttribute('aria-expanded') === 'true'
        );
        await shotModal(phonePage, '11-mobile-filters');
        await phone.close();

        console.log('Fertig: alle Mitglieder-Screenshots erstellt.');
    } finally {
        await browser.close();
    }
}

main().catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
