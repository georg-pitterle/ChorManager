'use strict';

/**
 * Erstellt Screenshots der Benachrichtigungs-Einstellungen für die
 * How-To-Dokumentation (help/notifications/docs/).
 *
 * Nutzung:
 *   node help/notifications/scripts/screenshot.js
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

// Normale Seiten: volle Seite inklusive Scroll-Inhalt.
async function shot(page, name) {
    const filePath = path.join(IMAGES_DIR, `${name}.png`);
    await page.screenshot({ path: filePath, fullPage: true });
    console.log(`gespeichert: ${path.relative(process.cwd(), filePath)}`);
}

/**
 * Bei offenem Modal: genau ein Viewport-Frame. fullPage zeichnet
 * position:fixed-Elemente sonst bei jeder Scroll-Position neu ein.
 */
async function shotModal(page, name) {
    const filePath = path.join(IMAGES_DIR, `${name}.png`);
    await page.screenshot({ path: filePath, fullPage: false });
    console.log(`gespeichert: ${path.relative(process.cwd(), filePath)}`);
}

/**
 * Lange Seiten auf eine lesbare Höhe beschneiden. Die Verwaltungsseite trägt
 * alle Einstellungen der Anwendung; für die Doku zählt der Abschnitt, um den
 * es geht, nicht die restlichen zweitausend Pixel.
 */
async function shotClipped(page, name, selector, padding = 24) {
    const box = await page.locator(selector).boundingBox();
    const filePath = path.join(IMAGES_DIR, `${name}.png`);
    const x = Math.max(0, box.x - padding);

    // fullPage, damit der Ausschnitt auch unterhalb des Viewports gerendert ist -
    // ein reiner clip auf eine ungescrollte Stelle bliebe sonst weiss.
    await page.screenshot({
        path: filePath,
        fullPage: true,
        clip: {
            x,
            y: Math.max(0, box.y - padding),
            width: Math.min(VIEWPORT.width - x, box.width + padding * 2),
            height: box.height + padding * 2,
        },
    });
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

/**
 * Das gebündelte bootstrap.bundle.min.js feuert `shown.bs.tab` schon vor dem
 * Ende der Opacity-Transition - deshalb auf den Render-Zustand pollen.
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

        // 1. Profil-Reiter mit den einzelnen Anlässen
        await page.goto(`${BASE_URL}/profile`, { waitUntil: 'networkidle' });
        await clickAndWaitForTabPane(page, page.locator('#tab-notifications'), '#pane-notifications');
        await shot(page, '01-profile-notifications');

        // 2. Verwaltung: die installationsweiten Schalter
        await page.goto(`${BASE_URL}/settings`, { waitUntil: 'networkidle' });
        await page.locator('#settings-notifications').waitFor({ state: 'visible' });
        await shotClipped(page, '02-settings-notifications', '#settings-notifications');

        // 3. Das Häkchen im Termin-Formular
        await page.goto(`${BASE_URL}/events`, { waitUntil: 'networkidle' });
        await clickAndWaitForEvent(
            page,
            page.locator('[data-bs-target="#addEventModal"]'),
            '#addEventModal',
            'shown.bs.modal'
        );
        await page.locator('#notify-members-create').scrollIntoViewIfNeeded();
        await shotModal(page, '03-event-notify-checkbox');

        console.log('Fertig: alle Screenshots zu den Benachrichtigungen erstellt.');
    } finally {
        await browser.close();
    }
}

main().catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
