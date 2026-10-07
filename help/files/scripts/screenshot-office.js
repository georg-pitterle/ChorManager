'use strict';

/**
 * Erstellt Screenshots zum Bearbeiten von Office-Dokumenten im Browser für die
 * How-To-Dokumentation (help/files/docs/files-office.md).
 *
 * Voraussetzung: Collabora läuft (ddev start --profiles=collabora) und der Seed
 * hat im Teamordner "Vorstand" die Datei "Probenplan Herbst.docx" angelegt.
 *
 * Nutzung:
 *   node help/files/scripts/screenshot-office.js
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
const SEED_DOCUMENT = 'Probenplan Herbst.docx';

async function shot(page, name) {
    const filePath = path.join(IMAGES_DIR, `${name}.png`);
    await page.screenshot({ path: filePath, fullPage: true });
    console.log(`gespeichert: ${path.relative(process.cwd(), filePath)}`);
}

// Bei offenem Modal und im Editor: genau ein Viewport-Frame, kein Scroll-Stitching.
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
        ignoreHTTPSErrors: true,
        locale: 'de-AT',
        // Collabora verbietet Inline-Stile; der Aufnahme-Stil unten braucht einen.
        bypassCSP: true,
    });
    await prepareSidebarForScreenshots(context);

    // Collabora meldet das fertig geladene Dokument per PostMessage an die Editor-Seite.
    await context.addInitScript(() => {
        window.addEventListener('message', (event) => {
            let data = event.data;
            try {
                data = typeof data === 'string' ? JSON.parse(data) : data;
            } catch (error) {
                return;
            }
            if (data && data.MessageId === 'App_LoadingStatus' && data.Values && data.Values.Status === 'Document_Loaded') {
                window.__officeDocumentLoaded = true;
            }
        });
    });
    const page = await context.newPage();

    try {
        await login(page);

        // 01 Ordner "Vorstand": Knopf "Neues Dokument" und "Bearbeiten" in der Liste
        await page.goto(`${BASE_URL}/files`, { waitUntil: 'networkidle' });
        await page.locator('.files-root-card', { hasText: 'Vorstand' }).first().click();
        await page.waitForLoadState('networkidle');
        await page.locator('tr', { hasText: SEED_DOCUMENT }).locator('a', { hasText: 'Bearbeiten' }).waitFor();
        await shot(page, '08-office-folder');
        const folderUrl = page.url();

        // 02 Neues Dokument anlegen
        await clickAndWaitForEvent(
            page,
            page.locator('[data-bs-target="#filesNewDocumentModal"]'),
            '#filesNewDocumentModal',
            'shown.bs.modal'
        );
        await page.locator('#filesNewDocumentName').fill('Sitzungsprotokoll Oktober');
        await shotModal(page, '09-office-new-document');
        await clickAndWaitForEvent(
            page,
            page.locator('#filesNewDocumentModal [data-bs-dismiss="modal"]').first(),
            '#filesNewDocumentModal',
            'hidden.bs.modal'
        );

        // 03 Detailseite mit "Im Browser bearbeiten"
        const editHref = await page.locator('tr', { hasText: SEED_DOCUMENT })
            .locator('a', { hasText: 'Bearbeiten' }).getAttribute('href');
        const fileId = editHref.match(/\/files\/(\d+)\/edit/)[1];
        await page.goto(`${BASE_URL}/files/${fileId}`, { waitUntil: 'networkidle' });
        await page.locator('a', { hasText: 'Im Browser bearbeiten' }).waitFor();
        await shot(page, '10-office-detail');

        // 04 Editor auf der ganzen Seite
        await page.goto(folderUrl, { waitUntil: 'networkidle' });
        await page.locator('tr', { hasText: SEED_DOCUMENT }).locator('a', { hasText: 'Bearbeiten' }).click();
        await page.waitForFunction(() => window.__officeDocumentLoaded === true, null, { timeout: 60000 });
        // Nach dem Laden zeichnet Collabora die Seite noch in einigen Frames fertig.
        const editor = page.frameLocator('#officeEditorFrame');
        await editor.locator('#document-container').waitFor();
        // Collabora zeigt beim ersten Start einen Willkommensdialog (eigenes iframe) über
        // dem Dokument und fügt ihn nach dem Laden auch neu ein. Ein Stil blendet ihn
        // für die Aufnahme aus, egal wann er erscheint.
        const editorFrame = page.frames().find((frame) => frame.name() === 'officeEditorFrame');
        await editorFrame.addStyleTag({ content: '.iframe-welcome-wrap { display: none !important; }' });
        await editor.locator('.iframe-welcome-wrap').waitFor({ state: 'attached', timeout: 20000 }).catch(() => {});
        await page.evaluate(() => new Promise((resolve) => {
            let frames = 30;
            const tick = () => (--frames > 0 ? requestAnimationFrame(tick) : resolve());
            requestAnimationFrame(tick);
        }));
        await shotModal(page, '11-office-editor');
    } finally {
        await browser.close();
    }
}

main().catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
