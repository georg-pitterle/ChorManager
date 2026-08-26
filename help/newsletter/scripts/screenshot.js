'use strict';

/**
 * Erstellt Screenshots aller relevanten Ansichten des Newsletter-Moduls
 * für die How-To-Dokumentation (help/newsletter/docs/).
 *
 * Nutzung:
 *   node help/newsletter/scripts/screenshot.js
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

/** Normale Seiten: volle Seite inklusive Scroll-Inhalt. */
async function shot(page, name) {
    const filePath = path.join(IMAGES_DIR, `${name}.png`);
    await page.screenshot({ path: filePath, fullPage: true });
    console.log(`gespeichert: ${path.relative(process.cwd(), filePath)}`);
}

/**
 * Bei offenem Modal oder offenem Dropdown: EIN einzelnes Viewport-Bild.
 * fullPage würde die position:fixed-Elemente je Scroll-Position erneut
 * einzeichnen und damit z. B. die Navbar mehrfach ins Bild stitchen.
 */
async function shotViewport(page, name) {
    const filePath = path.join(IMAGES_DIR, `${name}.png`);
    await page.screenshot({ path: filePath, fullPage: false });
    console.log(`gespeichert: ${path.relative(process.cwd(), filePath)}`);
}

/** Lange Listen: Kopf + Toolbar + einige Zeilen, auf maxHeight beschnitten. */
async function shotCapped(page, name, maxHeight = 1400) {
    const fullHeight = await page.evaluate(() => Math.ceil(document.documentElement.scrollHeight));
    const height = Math.min(fullHeight, maxHeight);
    const filePath = path.join(IMAGES_DIR, `${name}.png`);
    // clip wirkt bei fullPage:false nur innerhalb des Sichtfensters. Erst mit
    // fullPage:true rendert Playwright die Seite vollständig und schneidet
    // danach anhand von clip auf die gewünschte Höhe zu.
    await page.screenshot({
        path: filePath,
        fullPage: true,
        clip: { x: 0, y: 0, width: VIEWPORT.width, height },
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

/**
 * Bootstrap-Modals blenden per CSS-Transition ein. Der DOM-Zustand ("show"-Klasse)
 * ist sofort da, das Rendering erst nach Ende der Transition. Bootstrap feuert dafür
 * eigene Events (shown.bs.modal, hidden.bs.modal) - darauf warten wir, statt auf
 * eine feste Zeit zu setzen.
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
 * Die Newsletter-Aktionen laufen über ein gemeinsames Modal (#newsletterActionModal),
 * dessen Inhalt erst nach dem Öffnen per fetch nachgeladen wird. Daher zuerst auf
 * shown.bs.modal warten und danach auf einen Selektor aus dem geladenen Inhalt.
 */
async function openActionModal(page, triggerLocator, contentSelector) {
    await clickAndWaitForEvent(page, triggerLocator, '#newsletterActionModal', 'shown.bs.modal');
    await page.locator(`#newsletterActionContent ${contentSelector}`).waitFor({ state: 'visible' });
}

async function closeActionModal(page) {
    await clickAndWaitForEvent(
        page,
        page.locator('#newsletterActionModal .modal-header .btn-close'),
        '#newsletterActionModal',
        'hidden.bs.modal'
    );
}

/** TinyMCE wird erst nach dem Modal-Load initialisiert; auf das Editor-Iframe warten. */
async function waitForEditor(page) {
    await page.locator('#newsletterActionContent .tox-edit-area iframe').waitFor({ state: 'visible' });
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

        // 1. Übersicht: Entwürfe über alle Projekte
        await page.goto(`${BASE_URL}/newsletters?status=draft`, { waitUntil: 'networkidle' });
        await page.locator('#newslettersTable').waitFor({ state: 'visible' });
        await shotCapped(page, '01-overview-drafts');

        // 2. Aktionen-Menü eines Entwurfs (Versenden / Vorschau / Löschen)
        await page.locator('#newslettersTable tbody tr').first().locator('[data-bs-toggle="dropdown"]').click();
        await page.locator('#newslettersTable .dropdown-menu.show').waitFor({ state: 'visible' });
        await shotViewport(page, '02-draft-actions-menu');
        await page.keyboard.press('Escape');

        // 3. Modal: Neuer Newsletter
        await openActionModal(
            page,
            page.locator('[data-newsletter-modal-url*="/newsletters/create"]'),
            '#create-newsletter-form'
        );
        await waitForEditor(page);
        await shotViewport(page, '03-create-modal-settings');

        // 4. Gleiches Modal, nach unten gescrollt: Vorlagenauswahl und Inhaltseditor
        await page.locator('#newsletterActionContent .tox-tinymce').scrollIntoViewIfNeeded();
        await shotViewport(page, '04-create-modal-content');
        await closeActionModal(page);

        // 5. Modal: Entwurf bearbeiten
        await openActionModal(
            page,
            page.locator('#newslettersTable tbody tr').first().locator('[data-newsletter-modal-url*="/edit"]'),
            '#edit-newsletter-form'
        );
        await waitForEditor(page);
        await page.locator('#newsletterActionContent #send-newsletter-btn').scrollIntoViewIfNeeded();
        await shotViewport(page, '05-edit-modal-actions');

        // 6. Übersicht: versendete Newsletter
        // Das Bearbeiten-Modal wird nicht per Button geschlossen, sondern direkt verlassen:
        // Der Editor hält eine Bearbeitungssperre und pollt sie im Hintergrund; ein Wechsel
        // per goto ist hier robuster als das Schließen des Modals.
        await page.goto(`${BASE_URL}/newsletters?status=sent`, { waitUntil: 'networkidle' });
        await page.locator('#newslettersTable').waitFor({ state: 'visible' });
        await shotCapped(page, '06-overview-sent');

        // 7. Modal: Vorschau eines versendeten Newsletters
        // Der Vorschau-Inhalt steckt seit der Umstellung auf den eingebetteten Mail-Rahmen in
        // einem streng sandboxten iframe (.newsletter-preview-frame) statt in einem einfachen
        // div. openActionModal() wartet nur, bis das iframe-Element selbst sichtbar ist - das
        // Laden seines eigenen Dokuments (eigener Netzwerk-Request) ist ein separater Schritt,
        // deshalb zusätzlich auf den body innerhalb des Rahmens warten.
        await openActionModal(
            page,
            page.locator('#newslettersTable tbody tr').first().locator('[data-newsletter-modal-url*="/preview"]'),
            '.newsletter-preview-frame'
        );
        await page.frameLocator('.newsletter-preview-frame').locator('body').waitFor({ state: 'visible' });
        await shotViewport(page, '07-preview-modal');

        // 8. Vorlagenübersicht
        await page.goto(`${BASE_URL}/newsletters/templates`, { waitUntil: 'networkidle' });
        await page.locator('#newsletterTemplatesTable').waitFor({ state: 'visible' });
        await shotCapped(page, '08-templates');

        // 9. Modal: Neue Vorlage erstellen
        await clickAndWaitForEvent(
            page,
            page.locator('[data-bs-target="#createTemplateModal"]'),
            '#createTemplateModal',
            'shown.bs.modal'
        );
        await page.locator('#createTemplateModal .tox-edit-area iframe').waitFor({ state: 'visible' });
        await shotViewport(page, '09-template-create-modal');
        await clickAndWaitForEvent(
            page,
            page.locator('#createTemplateModal .btn-outline-secondary[data-bs-dismiss="modal"]'),
            '#createTemplateModal',
            'hidden.bs.modal'
        );

        // 10. Modal: Vorlage bearbeiten
        await openActionModal(
            page,
            page.locator('#newsletterTemplatesTable tbody tr').first()
                .locator('[data-newsletter-modal-url*="/edit"]'),
            'form[action^="/newsletters/templates/"]'
        );
        await waitForEditor(page);
        await shotViewport(page, '10-template-edit-modal');

        // 11. Meine Newsletter (persönliches Archiv)
        await page.goto(`${BASE_URL}/newsletters/archive`, { waitUntil: 'networkidle' });
        await page.locator('#newsletterArchiveTable').waitFor({ state: 'visible' });
        await shotCapped(page, '11-my-newsletters');

        console.log('Fertig: alle Newsletter-Screenshots erstellt.');
    } finally {
        await browser.close();
    }
}

main().catch((err) => {
    console.error(err);
    process.exitCode = 1;
});
