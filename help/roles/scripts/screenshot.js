'use strict';

/**
 * Erstellt Screenshots des Rollen- und Rechte-Moduls
 * für die How-To-Dokumentation (help/roles/docs/).
 *
 * Nutzung:
 *   node help/roles/scripts/screenshot.js
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
const EXAMPLE_ROLE_NAME = 'Beispielrolle';

async function shot(page, name) {
    const filePath = path.join(IMAGES_DIR, `${name}.png`);
    await page.screenshot({ path: filePath, fullPage: true });
    console.log(`gespeichert: ${path.relative(process.cwd(), filePath)}`);
}

/**
 * page.screenshot({ fullPage: true }) stitcht mehrere Scroll-Positionen
 * zusammen. Position:fixed-Elemente (Navbar, Modal-Backdrop, das Modal
 * selbst) werden dabei bei jeder Scroll-Position neu eingezeichnet und
 * erscheinen im Ergebnisbild mehrfach uebereinander. Ein Element-Screenshot
 * auf .modal-content hilft ebenfalls nicht: Bootstrap scrollt bei langen
 * Formularen den .modal-Container selbst, wodurch das Element zwar die
 * volle Inhaltshoehe hat, aber nur der aktuell sichtbare Ausschnitt
 * tatsaechlich gerendert ist - der Rest bleibt weiss.
 * Bei offenem Modal daher immer ein einfaches Viewport-Screenshot
 * (kein fullPage) verwenden: genau ein Frame, keine Scroll-Stitching,
 * zeigt exakt das, was auch die Nutzerin sieht.
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

async function createExampleRole(page, name) {
    await clickAndWaitForEvent(
        page,
        page.locator('[data-bs-target="#addRoleModal"]'),
        '#addRoleModal',
        'shown.bs.modal'
    );
    await page.locator('#addRoleModal #name').fill(name);
    await page.locator('#addRoleModal #hierarchy_level').fill('10');
    await Promise.all([
        page.waitForURL(`${BASE_URL}/roles`, { waitUntil: 'networkidle' }),
        page.locator('#addRoleModal button[type="submit"]').click(),
    ]);
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

        // 1. Berechtigungsmatrix
        await page.goto(`${BASE_URL}/roles`, { waitUntil: 'networkidle' });
        await page.locator('#rolesTable').waitFor({ state: 'visible' });
        await shot(page, '01-permission-matrix');

        // 2. Modal: Neue Rolle anlegen
        await clickAndWaitForEvent(
            page,
            page.locator('[data-bs-target="#addRoleModal"]'),
            '#addRoleModal',
            'shown.bs.modal'
        );
        await shotModal(page, '02-new-role-modal');
        await clickAndWaitForEvent(
            page,
            page.locator('#addRoleModal .btn-secondary[data-bs-dismiss="modal"]'),
            '#addRoleModal',
            'hidden.bs.modal'
        );

        // 3. Modal: Rolle bearbeiten (erste Zeile)
        await clickAndWaitForEvent(
            page,
            page.locator('.edit-role-btn').first(),
            '#editRoleModal',
            'shown.bs.modal'
        );
        await shotModal(page, '03-edit-role-modal');
        await clickAndWaitForEvent(
            page,
            page.locator('#editRoleModal .btn-secondary[data-bs-dismiss="modal"]'),
            '#editRoleModal',
            'hidden.bs.modal'
        );

        // 4. Modal: Rolle loeschen. Alle Seed-Rollen sind Mitgliedern zugewiesen und
        //    haben deshalb keinen Loeschen-Button - fuer den Screenshot legen wir eine
        //    unbesetzte Beispielrolle an und loeschen sie im selben Zug wieder.
        await createExampleRole(page, EXAMPLE_ROLE_NAME);
        const exampleRow = page.locator('#rolesTable thead th', { hasText: EXAMPLE_ROLE_NAME });
        await exampleRow.waitFor({ state: 'visible' });

        const deleteTrigger = page.locator('[data-bs-target^="#deleteRoleModal"]').last();
        const deleteModalId = await deleteTrigger.getAttribute('data-bs-target');
        await clickAndWaitForEvent(page, deleteTrigger, deleteModalId, 'shown.bs.modal');
        await shotModal(page, '04-delete-role-modal');

        await Promise.all([
            page.waitForURL(`${BASE_URL}/roles`, { waitUntil: 'networkidle' }),
            page.locator(`${deleteModalId} button[type="submit"]`).click(),
        ]);

        console.log('Fertig: alle Rollen-Screenshots erstellt.');
    } finally {
        await browser.close();
    }
}

main().catch((err) => {
    console.error(err);
    process.exitCode = 1;
});
