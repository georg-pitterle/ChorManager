'use strict';

/**
 * Erstellt Screenshots des Repertoire-Moduls (Liederverwaltung, Notenarchiv,
 * Projektzuordnungen) und der Downloads-Seite
 * für die How-To-Dokumentation (help/repertoire/docs/).
 *
 * Nutzung:
 *   node help/repertoire/scripts/screenshot.js
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
 * Lange Listen und Detailseiten auf eine brauchbare Hoehe beschneiden:
 * Kopfbereich, Toolbar und einige Zeilen zeigen den Aufbau vollstaendig.
 */
async function shotCapped(page, name, maxHeight = 1400) {
    const fullHeight = await page.evaluate(() => Math.ceil(document.documentElement.scrollHeight));
    const height = Math.min(fullHeight, maxHeight);
    const filePath = path.join(IMAGES_DIR, `${name}.png`);
    await page.screenshot({ path: filePath, clip: { x: 0, y: 0, width: VIEWPORT.width, height } });
    console.log(`gespeichert: ${path.relative(process.cwd(), filePath)} (${VIEWPORT.width}x${height})`);
}

/**
 * Bei offenem Modal nur ein einzelnes Viewport-Bild: fullPage stitcht mehrere
 * Scroll-Positionen zusammen und zeichnet position:fixed-Elemente (Navbar,
 * Backdrop, Modal) dabei mehrfach uebereinander.
 */
async function shotModal(page, name) {
    const filePath = path.join(IMAGES_DIR, `${name}.png`);
    await page.screenshot({ path: filePath, fullPage: false });
    console.log(`gespeichert: ${path.relative(process.cwd(), filePath)}`);
}

/**
 * Einzelner Seitenabschnitt (z. B. "Kategorien und Anhänge"): der Abschnitt
 * wird in den sichtbaren Bereich gescrollt und als Element aufgenommen, damit
 * das Bild nur den erklaerten Teil zeigt.
 */
async function shotSection(page, name, selector) {
    const section = page.locator(selector);
    await section.scrollIntoViewIfNeeded();

    // Ist der Abschnitt hoeher als der Viewport, stitcht Playwright mehrere
    // Scroll-Positionen zusammen und zeichnet die fixierte Navbar dabei mitten
    // ins Bild. Fuer die Aufnahme deshalb ausblenden.
    const styleHandle = await page.addStyleTag({
        content: '.app-topbar { display: none !important; }',
    });

    const filePath = path.join(IMAGES_DIR, `${name}.png`);
    await section.screenshot({ path: filePath });
    await styleHandle.evaluate((node) => node.remove());
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
 * Bootstrap-Modals blenden per CSS-Transition ein. Auf das Bootstrap-Event
 * warten statt auf eine feste Zeit.
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
 * Aufklapp-Bereiche (Notenarchiv, Downloads-Projekte) sind Bootstrap-Collapses.
 * Nach dem Klick auf das vollstaendig ausgeklappte Element warten.
 */
async function expandCollapse(page, triggerLocator, collapseSelector) {
    await triggerLocator.click();
    await page.waitForFunction((selector) => {
        const el = document.querySelector(selector);
        return el !== null && el.classList.contains('show') && !el.classList.contains('collapsing');
    }, collapseSelector);
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

        // 1. Repertoireuebersicht
        await page.goto(`${BASE_URL}/song-library`, { waitUntil: 'networkidle' });
        await page.locator('#songsTable').waitFor({ state: 'visible' });
        await shotCapped(page, '01-list');

        // 2. Kategorien verwalten
        await clickAndWaitForEvent(
            page,
            page.locator('[data-bs-target="#manageCategoriesModal"]'),
            '#manageCategoriesModal',
            'shown.bs.modal'
        );
        await shotModal(page, '02-categories');
        await clickAndWaitForEvent(
            page,
            page.locator('#manageCategoriesModal [data-bs-dismiss="modal"]').first(),
            '#manageCategoriesModal',
            'hidden.bs.modal'
        );

        // 3. Neues Lied anlegen
        await page.goto(`${BASE_URL}/song-library/create`, { waitUntil: 'networkidle' });
        await page.locator('#title').waitFor({ state: 'visible' });
        await shotCapped(page, '03-create');

        // Detailseite: das erste Lied nehmen, zu dem es auch Archivdaten gibt -
        // sonst zeigt der Notenarchiv-Screenshot ein leeres Formular.
        await page.goto(`${BASE_URL}/song-library`, { waitUntil: 'networkidle' });
        const songHrefs = await page
            .locator('#songsTable a[href^="/song-library/"]')
            .evaluateAll((links) => links.map((link) => link.getAttribute('href')));

        let songHref = songHrefs[0];
        for (const href of songHrefs) {
            await page.goto(`${BASE_URL}${href}`, { waitUntil: 'networkidle' });
            const location = await page.locator('#sheetArchiveHeaderLocation').textContent();
            if ((location || '').trim() !== 'kein Standort') {
                songHref = href;
                break;
            }
        }

        await page.goto(`${BASE_URL}${songHref}`, { waitUntil: 'networkidle' });
        await page.locator('#title').waitFor({ state: 'visible' });

        // 4. Stammdaten
        await shotCapped(page, '04-detail', 1000);

        // 5. Notenarchiv (Recht "Notenarchiv verwalten")
        await expandCollapse(
            page,
            page.locator('#sheetArchiveHeading button'),
            '#sheetArchiveCollapse'
        );
        await shotSection(page, '05-sheet-archive', 'section[aria-labelledby="song-archive-title"]');

        // 6. Kategorien und Anhaenge
        await shotSection(page, '06-files', 'section[aria-labelledby="song-meta-title"]');

        // 7. Projektzuordnungen
        await shotSection(page, '07-assignments', 'section[aria-labelledby="song-assignment-title"]');

        // 8. Downloads mit aufgeklapptem Projekt
        await page.goto(`${BASE_URL}/downloads`, { waitUntil: 'networkidle' });
        const firstProject = page.locator('#downloadsProjectsAccordion .accordion-button').first();
        const collapseId = await firstProject.getAttribute('data-bs-target');
        await expandCollapse(page, firstProject, collapseId);
        await shotCapped(page, '08-downloads');

        console.log('Fertig: alle Repertoire-Screenshots erstellt.');
    } finally {
        await browser.close();
    }
}

main().catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
