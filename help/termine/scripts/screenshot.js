'use strict';

/**
 * Erstellt Screenshots aller relevanten Ansichten des Termine-Moduls
 * (Events, Anwesenheit, Anmeldungen, Termin-Typen) fuer die Hilfe-Dokumentation
 * (help/termine/docs/).
 *
 * Nutzung:
 *   node help/termine/scripts/screenshot.js
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

async function shot(page, name, { fullPage = true } = {}) {
    const filePath = path.join(IMAGES_DIR, `${name}.png`);
    await page.screenshot({ path: filePath, fullPage });
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
 * Bootstrap-Modals und -Tabs blenden per CSS-Transition ein. Der DOM-Zustand
 * ("show"-Klasse) ist sofort da, das Rendering ist es aber erst nach Ende der
 * Transition. Bootstrap feuert dafuer eigene Events (shown.bs.modal, shown.bs.tab,
 * hidden.bs.modal) - darauf warten wir, statt auf eine feste Zeit zu setzen.
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

        // 1. Terminliste
        await page.goto(`${BASE_URL}/events?view=list`, { waitUntil: 'networkidle' });
        await page.locator('#eventsTable').waitFor({ state: 'visible' });
        await shot(page, '01-liste');

        // 2. Kalenderansicht
        await page.goto(`${BASE_URL}/events?view=calendar`, { waitUntil: 'networkidle' });
        await page.locator('#event-calendar .fc-toolbar').waitFor({ state: 'visible' });
        await shot(page, '02-kalender');

        // 3. Modal: Neuen Termin anlegen (inkl. Wiederholung aufgeklappt)
        await page.goto(`${BASE_URL}/events?view=list`, { waitUntil: 'networkidle' });
        await clickAndWaitForEvent(
            page,
            page.locator('[data-bs-target="#addEventModal"]'),
            '#addEventModal',
            'shown.bs.modal'
        );
        await page.locator('#repeat_event').check();
        await page.locator('#recurrence_options').waitFor({ state: 'visible' });
        await shot(page, '03-neuer-termin-modal', { fullPage: false });
        await clickAndWaitForEvent(
            page,
            page.locator('#addEventModal .btn-secondary[data-bs-dismiss="modal"]'),
            '#addEventModal',
            'hidden.bs.modal'
        );

        // 4. Termin bearbeiten (Serientermin, zeigt Serien-Checkbox)
        const seriesRow = page.locator('#eventsTable tbody tr').filter({ has: page.locator('i.bi-repeat') }).first();
        const editHref = await seriesRow.locator('a', { hasText: 'Termin bearbeiten' }).first();
        await seriesRow.locator('button.dropdown-toggle').click();
        await editHref.click();
        await page.waitForLoadState('networkidle');
        await page.locator('#update_series').waitFor({ state: 'visible' });
        await shot(page, '04-termin-bearbeiten');

        // 5. Termin-Detail mit Bemerkungen
        await page.goto(`${BASE_URL}/events?view=list`, { waitUntil: 'networkidle' });
        await page.locator('#eventsTable tbody tr').first().locator('a.btn-outline-secondary').first().click();
        await page.waitForLoadState('networkidle');
        await shot(page, '05-termin-detail');

        // 6. Kalender abonnieren (iCal-Link)
        await clickAndWaitForEvent(
            page,
            page.locator('[data-bs-target="#calendarSubscriptionModal"]'),
            '#calendarSubscriptionModal',
            'shown.bs.modal'
        );
        await shot(page, '06-kalender-abo-modal', { fullPage: false });
        await clickAndWaitForEvent(
            page,
            page.locator('#calendarSubscriptionModal .btn-secondary[data-bs-dismiss="modal"]'),
            '#calendarSubscriptionModal',
            'hidden.bs.modal'
        );

        // 7. Termin-Typen verwalten
        await page.goto(`${BASE_URL}/event-types`, { waitUntil: 'networkidle' });
        await page.locator('.dashboard-shell').waitFor({ state: 'visible' });
        await shot(page, '07-termin-typen');

        // 8. Anwesenheitsliste
        await page.goto(`${BASE_URL}/events?view=list&show_old_events=1`, { waitUntil: 'networkidle' });
        const attendanceLink = page.locator('#eventsTable tbody tr a', { hasText: 'Anwesenheit' }).first();
        await attendanceLink.click();
        await page.waitForLoadState('networkidle');
        await page.locator('.attendance-status-group').first().waitFor({ state: 'visible' });
        await shot(page, '08-anwesenheit-liste');

        // 9. Anmeldungen-Übersicht
        await page.goto(`${BASE_URL}/registrations`, { waitUntil: 'networkidle' });
        await shot(page, '09-anmeldungen-liste');

        // 10. Anmeldung-Detail (Vertretung/Proxy-Ansicht als Admin)
        const detailLink = page.locator('.registration-card a.btn-outline-secondary', { hasText: 'Details' }).first();
        await detailLink.click();
        await page.waitForLoadState('networkidle');
        await shot(page, '10-anmeldung-detail');

        console.log('Fertig: alle Termine-Screenshots erstellt.');
    } finally {
        await browser.close();
    }
}

main().catch((err) => {
    console.error(err);
    process.exitCode = 1;
});
