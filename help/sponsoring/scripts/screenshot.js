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

/**
 * Lange Seiten (Dashboard, Sponsor-Detail): Kopf und erste Inhalte, auf maxHeight
 * beschnitten. Kürzere Seiten werden nicht gestreckt.
 */
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

        // 1. Sponsoring-Dashboard: Arbeitslisten
        await page.goto(`${BASE_URL}/sponsoring`, { waitUntil: 'networkidle' });
        await page.locator('.sponsoring-board').waitFor({ state: 'visible' });
        await shotCapped(page, '01-dashboard');

        // 18. Dashboard: "Ohne Zuständigkeit" mit direkter Zuweisung
        await shotElement(
            page.locator('section[aria-labelledby="sponsoring-unassigned-title"]'),
            '18-dashboard-unassigned'
        );

        // 13. Dashboard: "Logo fehlt" samt aufgeklappten Logo-Fristen der Projekte
        await page.locator('details.logo-deadline-settings').evaluate((el) => { el.open = true; });
        await shotElement(
            page.locator('section[aria-labelledby="sponsoring-logos-title"]'),
            '13-dashboard-missing-logos'
        );

        // 2. Sponsorenübersicht
        await page.goto(`${BASE_URL}/sponsoring/sponsors`, { waitUntil: 'networkidle' });
        await page.locator('#sponsorsTable').waitFor({ state: 'visible' });
        // Die Liste ist lang (Karten); Kopf, Filter und die ersten Einträge genügen.
        await shotCapped(page, '02-sponsors-list', 1500);

        // 3. Modal: Neuer Sponsor
        await clickAndWaitForEvent(
            page,
            page.locator('[data-bs-target="#createSponsorModal"]'),
            '#createSponsorModal',
            'shown.bs.modal'
        );
        await shot(page, '03-new-sponsor-modal', { fullPage: false });

        // 14. Kategorie: Liste beim Öffnen, "neu anlegen" beim Tippen
        await page.locator('#createSponsorModal .ts-control').first().click();
        await page.locator('#createSponsorModal .ts-dropdown').first().waitFor({ state: 'visible' });
        await page.keyboard.type('Ski');
        await page.locator('#createSponsorModal .ts-dropdown .create').first().waitFor({ state: 'visible' });
        await shot(page, '14-category-select', { fullPage: false });
        await page.keyboard.press('Escape');

        // 15. Dublettenwarnung: ähnlicher Name zu "Druckerei Klangfarbe" aus dem Seed.
        //     Nichts wird angelegt - die Seite kommt mit dem Hinweis zurück.
        await page.locator('#sponsors-index-name').fill('druckerei klangfarbe KG');
        await Promise.all([
            page.waitForURL((url) => url.pathname === '/sponsoring/sponsors', { waitUntil: 'networkidle' }),
            page.locator('#createSponsorModal button[type="submit"]:not([name])').click(),
        ]);
        await page.locator('#createSponsorModal.show .sponsor-duplicate-warning').waitFor({ state: 'visible' });
        // Der Dialog öffnet sich hier beim Laden von selbst, nicht per Klick: auf das
        // Ende der Einblendung warten (Dialog ohne Verschiebung, Hintergrund abgedunkelt).
        await page.waitForFunction(() => {
            const dialog = document.querySelector('#createSponsorModal .modal-dialog');
            const backdrop = document.querySelector('.modal-backdrop.show');
            return dialog && backdrop
                && getComputedStyle(dialog).transform === 'none'
                && parseFloat(getComputedStyle(backdrop).opacity) >= 0.5;
        });
        await shot(page, '15-duplicate-warning', { fullPage: false });
        await clickAndWaitForEvent(
            page,
            page.locator('#createSponsorModal .btn-outline-secondary[data-bs-dismiss="modal"]'),
            '#createSponsorModal',
            'hidden.bs.modal'
        );

        // 4. Sponsor-Detail: Steckbrief und erster Vorgang
        //
        // Bewusst "Musikhaus Weber": Der Seed gibt ihm eine bezahlte Gold-Zusage
        // mit teils erbrachten Gegenleistungen und keine Generalabsage - bei einer
        // Generalabsage blendet die Seite die Knöpfe zum Anlegen aus.
        await page
            .locator('#sponsorsTable td[data-label="Name"] a', { hasText: 'Musikhaus Weber' })
            .first()
            .click();
        await page.waitForLoadState('networkidle');
        await page.locator('.sponsor-profile').waitFor({ state: 'visible' });
        await shotCapped(page, '04-sponsor-detail', 1300);

        // 5. Ein Vorgang als Karte - mit Gegenleistungen und Leistungen des Sponsors
        await shotElement(page.locator('.agreement-card').first(), '05-sponsor-detail-agreement');

        // 16. Logo-Dateien des Sponsors mit Ablegefläche
        await shotElement(
            page.locator('section[aria-labelledby="sponsor-logo-title"]'),
            '16-sponsor-logos'
        );

        // 6. Modal: Neuer Vorgang - mit Paket, damit die vorgeschlagenen
        //    Gegenleistungen zu sehen sind
        await clickAndWaitForEvent(
            page,
            page.locator('.page-actions [data-bs-target="#newSponsorshipModal"]'),
            '#newSponsorshipModal',
            'shown.bs.modal'
        );
        const packageSelect = page.locator('#newSponsorshipModal [data-sponsorship-package]');
        const goldValue = await packageSelect.locator('option', { hasText: 'Gold' }).first().getAttribute('value');
        await packageSelect.selectOption(goldValue);
        await page.locator('#newSponsorshipModal [data-sponsorship-benefits]').scrollIntoViewIfNeeded();
        await shot(page, '06-new-agreement-modal', { fullPage: false });

        // 17. Weiter unten: Leistungen des Sponsors und wofür das Logo gebraucht wird
        await page.locator('#newSponsorshipModal [data-sponsorship-logo-online]').scrollIntoViewIfNeeded();
        await shot(page, '17-new-agreement-deliverables', { fullPage: false });
        await clickAndWaitForEvent(
            page,
            page.locator('#newSponsorshipModal .btn-outline-secondary[data-bs-dismiss="modal"]'),
            '#newSponsorshipModal',
            'hidden.bs.modal'
        );

        // 7. Kontakthistorie
        await shotElement(
            page.locator('section[aria-labelledby="sponsor-contacts-title"]'),
            '07-sponsor-detail-contacts'
        );

        // 8. Modal: Kontakt protokollieren
        await page.evaluate(() => window.scrollTo(0, 0));
        await clickAndWaitForEvent(
            page,
            page.locator('.page-actions [data-bs-target="#newContactModal"]'),
            '#newContactModal',
            'shown.bs.modal'
        );
        await shot(page, '08-new-contact-modal', { fullPage: false });
        await clickAndWaitForEvent(
            page,
            page.locator('#newContactModal .btn-outline-secondary[data-bs-dismiss="modal"]'),
            '#newContactModal',
            'hidden.bs.modal'
        );

        // 11. Modal: Stammdaten bearbeiten (über "Mehr")
        await page.locator('.page-actions .dropdown-toggle').click();
        await clickAndWaitForEvent(
            page,
            page.locator('.page-actions [data-bs-target="#editSponsorModal"]'),
            '#editSponsorModal',
            'shown.bs.modal'
        );
        await shot(page, '11-edit-master-data-modal', { fullPage: false });
        await clickAndWaitForEvent(
            page,
            page.locator('#editSponsorModal .btn-outline-secondary[data-bs-dismiss="modal"]'),
            '#editSponsorModal',
            'hidden.bs.modal'
        );

        // 9. Paketverwaltung
        await page.goto(`${BASE_URL}/sponsoring/packages`, { waitUntil: 'networkidle' });
        await page.locator('#sponsorPackagesTable').waitFor({ state: 'visible' });
        await shot(page, '09-packages');

        // 12. Modal: Paket bearbeiten mit Gegenleistungen
        await clickAndWaitForEvent(
            page,
            page.locator('#sponsorPackagesTable [data-bs-target^="#editPackageModal"]').last(),
            '.modal.fade[id^="editPackageModal"]:last-of-type',
            'shown.bs.modal'
        );
        await shot(page, '12-edit-package-modal', { fullPage: false });

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
