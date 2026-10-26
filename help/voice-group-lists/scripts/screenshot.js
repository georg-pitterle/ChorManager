'use strict';

/**
 * Erstellt die Screenshots für das Hilfethema "Stimmgruppen-Listen"
 * (help/voice-group-lists/docs/).
 *
 * Nutzung:
 *   node help/voice-group-lists/scripts/screenshot.js
 *
 * Optional per Umgebungsvariable überschreibbar:
 *   BASE_URL   Basis-URL der Dev-Umgebung (Default: https://chormanager.ddev.site)
 *   LOGIN_EMAIL, LOGIN_PASSWORD  Seed-Anmeldedaten
 *
 * Der Seed legt die Listen für Sopran und Alt an; seed.001 gehört zum Sopran und hält
 * jedes Recht. Das Skript legt nichts an und speichert nichts: Dialoge werden nur
 * geöffnet, die Formulare nie abgesendet.
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

async function shot(page, name, { fullPage = true } = {}) {
    const filePath = path.join(IMAGES_DIR, `${name}.png`);
    await page.screenshot({ path: filePath, fullPage });
    console.log(`gespeichert: ${path.relative(process.cwd(), filePath)}`);
}

/** Lange Seiten: Kopf und erste Zeilen, auf maxHeight beschnitten. */
async function shotCapped(page, name, maxHeight = 1400) {
    const fullHeight = await page.evaluate(() => Math.ceil(document.documentElement.scrollHeight));
    const height = Math.min(fullHeight, maxHeight);
    const filePath = path.join(IMAGES_DIR, `${name}.png`);
    await page.screenshot({ path: filePath, fullPage: true, clip: { x: 0, y: 0, width: VIEWPORT.width, height } });
    console.log(`gespeichert: ${path.relative(process.cwd(), filePath)} (${VIEWPORT.width}x${height})`);
}

/** Ein einzelner Abschnitt der Seite, ohne Kopf und Seitenleiste. */
async function shotElement(locator, name) {
    const filePath = path.join(IMAGES_DIR, `${name}.png`);
    await locator.scrollIntoViewIfNeeded();
    await locator.screenshot({ path: filePath });
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
 * Bootstrap blendet Modals und Dropdowns per Transition ein. Auf die eigenen Events
 * (shown.bs.modal, shown.bs.dropdown, hidden.bs.modal) warten statt auf eine feste Zeit.
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
    await prepareSidebarForScreenshots(context);
    const page = await context.newPage();

    try {
        await login(page);

        // 1. Übersicht: aktive Listen und das eingeklappte Archiv (aufgeklappt aufgenommen)
        await page.goto(`${BASE_URL}/voice-group-lists`, { waitUntil: 'networkidle' });
        await page.locator('.voice-group-list-overview').first().waitFor({ state: 'visible' });
        await page.locator('details.voice-group-list-archive').evaluate((el) => { el.open = true; });
        await shotCapped(page, '01-overview', 1000);

        // 2. Dialog "Neue Liste" mit vorausgewähltem laufendem Projekt (wird nicht abgesendet)
        await clickAndWaitForEvent(
            page,
            page.locator('.page-actions [data-bs-target="#newListModal"]'),
            '#newListModal',
            'shown.bs.modal'
        );
        await shot(page, '02-new-list-modal', { fullPage: false });
        await clickAndWaitForEvent(
            page,
            page.locator('#newListModal [data-bs-dismiss="modal"].btn-outline-secondary'),
            '#newListModal',
            'hidden.bs.modal'
        );

        // 3. Listenseite der Projektliste (mit Häkchen- und Notiz-Spalte)
        await page
            .locator('.voice-group-list-overview .list-group-item', { hasText: 'Beitrag bezahlt' })
            .locator('a.voice-group-list-overview__title')
            .first()
            .click();
        await page.waitForLoadState('networkidle');
        await page.locator('.voice-group-list-table').waitFor({ state: 'visible' });
        const listUrl = page.url();
        await shotCapped(page, '03-list', 1100);

        // 4. Menü "Liste verwalten"
        await clickAndWaitForEvent(
            page,
            page.locator('.page-actions .dropdown-toggle'),
            '.page-actions .dropdown',
            'shown.bs.dropdown'
        );
        await shot(page, '04-manage-menu', { fullPage: false });

        // 5. Dialog "Titel und Projekt ändern" (wird nicht abgesendet)
        await clickAndWaitForEvent(
            page,
            page.locator('.page-actions [data-bs-target="#editListModal"]'),
            '#editListModal',
            'shown.bs.modal'
        );
        await shot(page, '05-edit-list-modal', { fullPage: false });
        await clickAndWaitForEvent(
            page,
            page.locator('#editListModal [data-bs-dismiss="modal"].btn-outline-secondary'),
            '#editListModal',
            'hidden.bs.modal'
        );

        // 6. Fortschrittsleiste mit "Nur offene zeigen"
        await shotElement(page.locator('.voice-group-list-progress'), '06-progress');

        // 7. Gefiltert auf offene Mitglieder
        const openLink = page.locator('.voice-group-list-progress a', { hasText: 'Nur offene zeigen' }).first();
        await Promise.all([page.waitForURL(/[?&]open=\d+/, { waitUntil: 'networkidle' }), openLink.click()]);
        await page.locator('.voice-group-list-table').waitFor({ state: 'visible' });
        await shotCapped(page, '07-open-only', 900);

        // 8. Spalten verwalten: aufgeklappt, mit einer geöffneten Spalte
        await page.goto(listUrl, { waitUntil: 'networkidle' });
        const manage = page.locator('details.voice-group-list-columns');
        await manage.evaluate((el) => { el.open = true; });
        await manage.locator('details.voice-group-list-column-tools').first().evaluate((el) => { el.open = true; });
        await shotElement(manage, '08-manage-columns');

        // 9. Archivierte Liste: nur lesbar, "Zurückholen" im Seitenkopf
        await page.goto(`${BASE_URL}/voice-group-lists`, { waitUntil: 'networkidle' });
        await page.locator('details.voice-group-list-archive').evaluate((el) => { el.open = true; });
        await page
            .locator('details.voice-group-list-archive a.voice-group-list-overview__title')
            .first()
            .click();
        await page.waitForLoadState('networkidle');
        await page.locator('.voice-group-list-table').waitFor({ state: 'visible' });
        await shotCapped(page, '09-archived', 900);

        console.log('Fertig: alle Screenshots der Stimmgruppen-Listen erstellt.');
    } finally {
        await browser.close();
    }
}

main().catch((err) => {
    console.error(err);
    process.exitCode = 1;
});
