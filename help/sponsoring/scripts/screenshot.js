'use strict';

/**
 * Erstellt Screenshots aller relevanten Ansichten des Sponsoring-Moduls
 * für die How-To-Dokumentation (help/sponsoring/docs/).
 *
 * Nutzung:
 *   node help/sponsoring/scripts/screenshot.js
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
 * Transition. Bootstrap feuert dafür eigene Events (shown.bs.modal, shown.bs.tab,
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

/**
 * Das gebündelte bootstrap.bundle.min.js feuert `shown.bs.tab` bereits, bevor
 * die "show"-Klasse gesetzt und die Opacity-Transition der Tab-Pane
 * abgeschlossen ist. Daher hier direkt auf den tatsächlichen Render-Zustand
 * pollen (waitForFunction), statt auf das Event zu vertrauen.
 */
async function clickAndWaitForTabPane(page, triggerLocator, paneSelector) {
    await triggerLocator.click();
    await page.waitForFunction((selector) => {
        const el = document.querySelector(selector);
        if (!el) {
            return false;
        }
        return el.classList.contains('show')
            && el.classList.contains('active')
            && parseFloat(getComputedStyle(el).opacity) >= 0.99;
    }, paneSelector);
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

        // 1. Sponsoring-Dashboard
        await page.goto(`${BASE_URL}/sponsoring`, { waitUntil: 'networkidle' });
        await page.locator('.dashboard-shell').waitFor({ state: 'visible' });
        await shot(page, '01-dashboard');

        // 2. Sponsorenübersicht
        await page.goto(`${BASE_URL}/sponsoring/sponsors`, { waitUntil: 'networkidle' });
        await page.locator('#sponsorsTable').waitFor({ state: 'visible' });
        await shot(page, '02-sponsors-list');

        // 3. Modal: Neuer Sponsor
        await clickAndWaitForEvent(
            page,
            page.locator('[data-bs-target="#createSponsorModal"]'),
            '#createSponsorModal',
            'shown.bs.modal'
        );
        await shot(page, '03-new-sponsor-modal', { fullPage: false });
        await clickAndWaitForEvent(
            page,
            page.locator('#createSponsorModal .btn-secondary[data-bs-dismiss="modal"]'),
            '#createSponsorModal',
            'hidden.bs.modal'
        );

        // 4. Sponsor-Detail: Stammdaten
        //
        // Bewusst nicht der erste Eintrag: bei einem Sponsor mit Generalabsage
        // ("Keine Anfragen erwünscht") blendet die Oberfläche die Knöpfe zum
        // Anlegen aus, und die folgenden Schritte fänden ihre Dialoge nicht.
        await page
            .locator('#sponsorsTable tbody tr:not([data-state="blocked"])')
            .first()
            .locator('a.fw-semibold')
            .click();
        await page.waitForLoadState('networkidle');
        await page.locator('#pane-stammdaten').waitFor({ state: 'visible' });
        await shot(page, '04-sponsor-detail-master-data');

        // 5. Sponsor-Detail: Vereinbarungen
        await clickAndWaitForTabPane(page, page.locator('#tab-vereinbarungen'), '#pane-vereinbarungen');
        await shot(page, '05-sponsor-detail-agreements');

        // 6. Modal: Neue Vereinbarung
        await clickAndWaitForEvent(
            page,
            page.locator('#pane-vereinbarungen [data-bs-target="#newSponsorshipModal"]'),
            '#newSponsorshipModal',
            'shown.bs.modal'
        );
        await shot(page, '06-new-agreement-modal', { fullPage: false });
        await clickAndWaitForEvent(
            page,
            page.locator('#newSponsorshipModal .btn-secondary[data-bs-dismiss="modal"]'),
            '#newSponsorshipModal',
            'hidden.bs.modal'
        );

        // 7. Sponsor-Detail: Kontakthistorie
        await clickAndWaitForTabPane(page, page.locator('#tab-kontakte'), '#pane-kontakte');
        await shot(page, '07-sponsor-detail-contacts');

        // 8. Modal: Kontakt protokollieren
        await clickAndWaitForEvent(
            page,
            page.locator('#pane-kontakte [data-bs-target="#newContactModal"]'),
            '#newContactModal',
            'shown.bs.modal'
        );
        await shot(page, '08-new-contact-modal', { fullPage: false });
        await clickAndWaitForEvent(
            page,
            page.locator('#newContactModal .btn-secondary[data-bs-dismiss="modal"]'),
            '#newContactModal',
            'hidden.bs.modal'
        );

        // 9. Paketverwaltung
        await page.goto(`${BASE_URL}/sponsoring/packages`, { waitUntil: 'networkidle' });
        await page.locator('#sponsorPackagesTable').waitFor({ state: 'visible' });
        await shot(page, '09-packages');

        // 10. Zentrale Anhangsammlung
        await page.goto(`${BASE_URL}/sponsoring/attachments`, { waitUntil: 'networkidle' });
        await page.locator('#sponsoringAttachmentsTable').waitFor({ state: 'visible' });
        await shot(page, '10-attachments');

        console.log('Fertig: alle Sponsoring-Screenshots erstellt.');
    } finally {
        await browser.close();
    }
}

main().catch((err) => {
    console.error(err);
    process.exitCode = 1;
});
