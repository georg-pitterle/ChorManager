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
const { prepareSidebarForScreenshots } = require('../../screenshot-support');

const BASE_URL = process.env.BASE_URL || 'https://chormanager.ddev.site';
const LOGIN_EMAIL = process.env.LOGIN_EMAIL || 'seed.001@chor.local';
const LOGIN_PASSWORD = process.env.LOGIN_PASSWORD || 'seed';
const IMAGES_DIR = path.join(__dirname, '..', 'screenshots');

const VIEWPORT = { width: 1440, height: 900 };

// Beispiel-Kontoauszug für die Import-Vorschau (dieselbe Datei wie im Test-Fixture).
const SAMPLE_STATEMENT = path.join(__dirname, '..', '..', '..', 'tests', 'Fixtures', 'bank_statement_sample.csv');

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
    // clip wirkt ohne fullPage nur innerhalb des Sichtfensters; erst fullPage rendert die ganze Seite.
    await page.screenshot({
        path: filePath,
        fullPage: true,
        clip: { x: 0, y: 0, width: VIEWPORT.width, height },
    });
    console.log(`gespeichert: ${path.relative(process.cwd(), filePath)} (${VIEWPORT.width}x${height})`);
}

// Einzelner Seitenabschnitt: beginnt an dessen Oberkante statt am Seitenanfang,
// damit Bereiche weit unten auf der Seite ohne Riesenbild dokumentiert werden.
// Der Abschnitt wird bewusst unter die fixierte Topbar gescrollt - sonst
// verdeckt diese die Ueberschrift des Abschnitts.
const NAV_OFFSET = 100;

async function shotSection(page, name, locator, maxHeight = 900) {
    await locator.evaluate((el, offset) => {
        window.scrollTo({ top: el.getBoundingClientRect().top + window.scrollY - offset, behavior: 'instant' });
    }, NAV_OFFSET);

    const box = await locator.boundingBox();
    const height = Math.min(Math.ceil(box.height) + NAV_OFFSET + 24, maxHeight, VIEWPORT.height);
    const filePath = path.join(IMAGES_DIR, `${name}.png`);
    await page.screenshot({
        path: filePath,
        clip: { x: 0, y: Math.max(0, Math.floor(box.y) - NAV_OFFSET), width: VIEWPORT.width, height },
    });
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

// Tom-Select: Text eintippen, auf den Eintrag "Neu: ..." warten, mit Enter bestätigen.
// Danach den Fokus lösen, sonst schließt Escape später das Modal statt das Dropdown.
async function typeNewGroup(page, selectId, text) {
    // Mit vorhandenen Einträgen versteckt Tom-Select das Eingabefeld außerhalb des Bildes;
    // deshalb per API fokussieren und über die Tastatur tippen.
    await page.locator(`#${selectId}`).evaluate((el) => el.tomselect.focus());
    await page.keyboard.type(text, { delay: 30 });
    await page.locator('.ts-dropdown .create').filter({ hasText: text }).first().waitFor({ state: 'visible' });
    await page.keyboard.press('Enter');
    await page.locator(`#${selectId}`).evaluate((el) => {
        if (el.tomselect) {
            el.tomselect.close();
        }
        if (document.activeElement instanceof HTMLElement) {
            document.activeElement.blur();
        }
    });
}

// Vorhandene Gruppe über die Tom-Select-Schnittstelle wählen (bevorzugt `preferred`).
async function pickExistingGroup(page, selector, preferred) {
    return page.locator(selector).evaluate((el, pref) => {
        const names = Array.from(el.options).map((o) => o.value).filter((v) => v !== '');
        const name = names.includes(pref) ? pref : names[0];
        el.tomselect.addItem(name);
        el.tomselect.close();
        return name;
    }, preferred);
}

async function main() {
    fs.mkdirSync(IMAGES_DIR, { recursive: true });

    const browser = await chromium.launch({ env: { ...process.env, LANG: 'de_AT.UTF-8' } });
    const context = await browser.newContext({
        locale: 'de-AT',
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

        // === Kassabuch ===

        // 01. Kassabuch (Buchungsliste) - lange Liste, beschnitten.
        await page.goto(`${BASE_URL}/finances`, { waitUntil: 'networkidle' });
        await page.locator('#financesTable').waitFor({ state: 'visible' });
        await shotCapped(page, '01-kassabuch');

        // 02. Modal: Neue Buchung
        await clickAndWaitForEvent(
            page,
            page.locator('.page-actions [data-bs-target="#financeModal"]'),
            '#financeModal',
            'shown.bs.modal'
        );
        // Zwei Gruppen sichtbar machen: eine vorhandene Kostenart und ein neu getippter
        // Anlass. Es wird nichts gespeichert, der Dialog wird gleich wieder abgebrochen.
        // Höheres Fenster, damit das Formular samt Gruppenfeld komplett ins Bild passt.
        await page.setViewportSize({ width: VIEWPORT.width, height: 1250 });
        await page.locator('#amount').fill('48,00');
        await page.locator('#description').fill('Notenmappen für das Herbstkonzert');
        const existingGroup = await pickExistingGroup(page, '#group_select', 'Notenmaterial');
        console.log(`vorhandene Gruppe gewählt: ${existingGroup}`);
        await typeNewGroup(page, 'group_select', 'Herbstkonzert-Probe');
        await shotModal(page, '02-new-entry-modal');
        await page.setViewportSize(VIEWPORT);
        await clickAndWaitForEvent(
            page,
            page.locator('#financeModal .btn-outline-secondary[data-bs-dismiss="modal"]'),
            '#financeModal',
            'hidden.bs.modal'
        );

        // 03. Modal: Kassabuch-Einstellungen (Geschäftsjahr-Beginn, Abschluss)
        // Import und Einstellungen stehen im Menü "Mehr" der Kopfzeile.
        await page.locator('.page-actions [data-finance-more-menu]').click();
        await clickAndWaitForEvent(
            page,
            page.locator('.page-actions [data-bs-target="#settingsModal"]'),
            '#settingsModal',
            'shown.bs.modal'
        );
        await shotModal(page, '03-settings-modal');
        await clickAndWaitForEvent(
            page,
            page.locator('#settingsModal .btn-outline-secondary[data-bs-dismiss="modal"]'),
            '#settingsModal',
            'hidden.bs.modal'
        );

        // 04. Finanzauswertung (Kennzahlen, Zahlungsart, Gruppen). Oben rechts der
        //     Button "PDF herunterladen". Timeline darunter ist sehr lang -> beschnitten.
        await page.goto(`${BASE_URL}/finances/report`, { waitUntil: 'networkidle' });
        await page.locator('.finances-report').waitFor({ state: 'visible' });
        await shotCapped(page, '04-report', 1700);

        // 17. "Saldo nach Gruppe" samt Hinweis, dass Buchungen mit mehreren Gruppen
        //     in jeder Gruppe zählen (Fußzeile der Karte, weit unten auf der Seite).
        const overlapCard = page.locator('[data-groups-overlap]').locator('xpath=ancestor::*[contains(@class,"card")][1]');
        await overlapCard.waitFor({ state: 'visible' });
        await shotSection(page, '17-report-group-overlap', overlapCard, 900);

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
            page.locator(`${itemModalSelector} .btn-outline-secondary[data-bs-dismiss="modal"]`),
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
            page.locator('#modal-create-category .btn-outline-secondary[data-bs-dismiss="modal"]'),
            '#modal-create-category',
            'hidden.bs.modal'
        );

        // === Konten (Zahlungskreise) ===

        // 09. Kontenliste mit Anfangsbestand und aktuellem Bestand.
        await page.goto(`${BASE_URL}/finances/accounts`, { waitUntil: 'networkidle' });
        await page.locator('.finances-table').waitFor({ state: 'visible' });
        await shotCapped(page, '09-accounts', 900);

        // 10. Modal: Konto anlegen bzw. bearbeiten.
        await clickAndWaitForEvent(
            page,
            page.locator('.page-actions [data-bs-target="#financeAccountModal"]'),
            '#financeAccountModal',
            'shown.bs.modal'
        );
        await shotModal(page, '10-account-modal');
        await clickAndWaitForEvent(
            page,
            page.locator('#financeAccountModal .btn-outline-secondary[data-bs-dismiss="modal"]'),
            '#financeAccountModal',
            'hidden.bs.modal'
        );

        // === Offene Posten ===

        // 11. Der Abschnitt liegt unterhalb der Buchungsliste, daher gezielt
        //     dorthin scrollen und nur diesen Bereich aufnehmen.
        await page.goto(`${BASE_URL}/finances`, { waitUntil: 'networkidle' });
        const openSection = page.locator('section[aria-labelledby="finances-open-title"]');
        await openSection.waitFor({ state: 'visible' });
        await shotSection(page, '11-open-items', openSection, 900);

        // === Kontoauszug-Import ===

        // 12. Modal: CSV auswählen.
        await page.locator('.page-actions [data-finance-more-menu]').click();
        await clickAndWaitForEvent(
            page,
            page.locator('.page-actions [data-bs-target="#financeImportModal"]'),
            '#financeImportModal',
            'shown.bs.modal'
        );
        await shotModal(page, '12-import-modal');

        // 13. Vorschau nach dem Einlesen. Es wird nur geparst, nichts verbucht -
        //     der Import wird danach bewusst abgebrochen.
        await page.locator('#financeImportModal input[name="statement"]').setInputFiles(SAMPLE_STATEMENT);
        await Promise.all([
            page.waitForURL(`${BASE_URL}/finances/import/preview`, { waitUntil: 'networkidle' }),
            page.locator('#financeImportModal button[type="submit"]').click(),
        ]);
        await page.locator('.finance-import-table').waitFor({ state: 'visible' });
        // Erste Zeile mit zwei Gruppen, damit die Mehrfachauswahl erkennbar ist.
        const firstRowGroups = page.locator('.finance-import-table select[name^="group_names["]').first();
        await firstRowGroups.evaluate((el) => {
            const names = Array.from(el.options).map((o) => o.value).filter((v) => v !== '');
            names.slice(0, 2).forEach((n) => el.tomselect.addItem(n));
            el.tomselect.close();
        });
        await shotCapped(page, '13-import-preview', 1100);

        await Promise.all([
            page.waitForURL(`${BASE_URL}/finances`, { waitUntil: 'networkidle' }),
            page.locator('form[action="/finances/import/cancel"] button[type="submit"]').click(),
        ]);

        // === Kassabericht und Journal ===

        // 14. Kassabericht je Konto in der Auswertung.
        await page.goto(`${BASE_URL}/finances/report`, { waitUntil: 'networkidle' });
        const statement = page.locator('.finances-report .card').first();
        await statement.waitFor({ state: 'visible' });
        await shotCapped(page, '14-account-statement', 1000);

        // 15. Änderungsjournal.
        await page.goto(`${BASE_URL}/finances/journal`, { waitUntil: 'networkidle' });
        await page.locator('.finances-table').waitFor({ state: 'visible' });
        await shotCapped(page, '15-journal', 1100);

        // === Stapelbearbeitung ===

        // 16. Ein paar Zeilen angehakt, Gruppe in der Leiste gewählt - nicht abgesendet.
        await page.goto(`${BASE_URL}/finances`, { waitUntil: 'networkidle' });
        const boxes = page.locator('#financesTable input[data-finance-select]');
        for (let i = 0; i < 3; i++) {
            await boxes.nth(i).check();
        }
        await page.locator('#financeSelectionBar').waitFor({ state: 'visible' });
        await pickExistingGroup(page, '#financeBulkGroup', 'Herbstkonzert');
        // Die Leiste klebt oben; so weit scrollen, dass Leiste und Zeilen zugleich im Bild sind.
        await page.locator('#financesTable').evaluate((el) => {
            window.scrollTo({ top: el.getBoundingClientRect().top + window.scrollY - 220, behavior: 'instant' });
        });
        await shotModal(page, '16-bulk-selection');

        console.log('Fertig: alle Finanz-Screenshots erstellt.');
    } finally {
        await browser.close();
    }
}

main().catch((err) => {
    console.error(err);
    process.exitCode = 1;
});
