'use strict';

/**
 * Erstellt Screenshots der Dateiverwaltung für die Hilfe (help/files/docs/).
 *
 * Voraussetzung: frischer Seed-Lauf mit FEATURE_FILES=true, Anmeldung als
 * seed.001 (hat "Dateiverwaltung verwalten").
 *
 * Nutzung:
 *   node help/files/scripts/screenshot.js
 */

const path = require('path');
const fs = require('fs');
const os = require('os');
const { chromium } = require('playwright');
const { prepareSidebarForScreenshots } = require('../../screenshot-support');

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
    const context = await browser.newContext({ viewport: VIEWPORT, ignoreHTTPSErrors: true, locale: 'de-AT' });
    await prepareSidebarForScreenshots(context);
    const page = await context.newPage();

    try {
        await login(page);

        // 01 Übersicht
        await page.goto(`${BASE_URL}/files`, { waitUntil: 'networkidle' });
        await shot(page, '01-overview');

        // 04 Teamordner anlegen (Modal auf der Übersicht)
        await clickAndWaitForEvent(
            page,
            page.locator('[data-bs-target="#filesCreateRootModal"]'),
            '#filesCreateRootModal',
            'shown.bs.modal'
        );
        await page.locator('#filesRootName').fill('Konzertreise');
        await page.locator('#filesRootQuota').fill('250');
        await shotModal(page, '04-create-root');
        await clickAndWaitForEvent(
            page,
            page.locator('#filesCreateRootModal [data-bs-dismiss="modal"]').first(),
            '#filesCreateRootModal',
            'hidden.bs.modal'
        );

        // 02 Ordnerseite: der Noten-Teamordner
        await page.locator('.files-root-card', { hasText: 'Noten' }).first().click();
        await page.waitForLoadState('networkidle');
        await shot(page, '02-folder');

        // 07 Versionen der Partitur (drei Fassungen im Seed)
        const row = page.locator('tr', { hasText: 'Ave verum corpus - Partitur.pdf' });
        await row.locator('[data-bs-toggle="dropdown"]').click();
        await clickAndWaitForEvent(page, row.locator('[data-files-versions]'), '#filesVersionsModal', 'shown.bs.modal');
        await page.locator('[data-files-versions-body] .list-group-item').first().waitFor();
        await shotModal(page, '07-versions');
        await clickAndWaitForEvent(
            page,
            page.locator('#filesVersionsModal .btn-close'),
            '#filesVersionsModal',
            'hidden.bs.modal'
        );

        // 05 Freigaben
        await page.locator('.page-actions [data-bs-toggle="dropdown"]', { hasText: 'Ordner' }).click();
        await clickAndWaitForEvent(
            page,
            page.locator('[data-bs-target="#filesSharesModal"]'),
            '#filesSharesModal',
            'shown.bs.modal'
        );
        await shotModal(page, '05-shares');
        await clickAndWaitForEvent(
            page,
            page.locator('#filesSharesModal .btn-close'),
            '#filesSharesModal',
            'hidden.bs.modal'
        );

        // 03 Hochladen: Datei auswählen und den Fortschritt festhalten, bevor die
        // Seite nach dem Upload neu lädt.
        const sample = path.join(os.tmpdir(), 'Probenplan Herbst.txt');
        fs.writeFileSync(sample, 'Dienstag 19:00 Tutti\nDonnerstag 19:00 Register\n');
        await page.locator('[data-files-input]').setInputFiles(sample);
        await page.locator('[data-files-progress] li', { hasText: 'Hochgeladen' }).waitFor();
        // Der Balken wächst per CSS-Transition auf volle Breite - abwarten.
        await page.waitForFunction(() => {
            const bar = document.querySelector('[data-files-progress] .progress-bar');
            return bar && bar.getBoundingClientRect().width >= bar.parentElement.getBoundingClientRect().width - 1;
        });
        await page.locator('[data-files-dropzone]').screenshot({ path: path.join(IMAGES_DIR, '03-upload.png') });
        console.log('gespeichert: 03-upload.png');
        await page.waitForLoadState('networkidle');

        // 06 Papierkorb
        await page.goto(`${BASE_URL}/files/trash`, { waitUntil: 'networkidle' });
        await shot(page, '06-trash');
    } finally {
        await browser.close();
    }
}

main().catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
