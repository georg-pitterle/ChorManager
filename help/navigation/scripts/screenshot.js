'use strict';

/**
 * Erstellt Screenshots zu Startseite, Seitenleiste und Schnellsuche für die
 * How-To-Dokumentation (help/navigation/docs/).
 *
 * Nutzung:
 *   node help/navigation/scripts/screenshot.js
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
const MOBILE_VIEWPORT = { width: 390, height: 844 };

// Lange Seiten: Kopf und die ersten Abschnitte, auf maxHeight beschnitten.
async function shotCapped(page, name, maxHeight = 1400) {
    const fullHeight = await page.evaluate(() => Math.ceil(document.documentElement.scrollHeight));
    const height = Math.min(fullHeight, maxHeight);
    const filePath = path.join(IMAGES_DIR, `${name}.png`);
    await page.screenshot({ path: filePath, fullPage: true, clip: { x: 0, y: 0, width: VIEWPORT.width, height } });
    console.log(`gespeichert: ${path.relative(process.cwd(), filePath)} (${VIEWPORT.width}x${height})`);
}

// Bei offenem Modal oder Offcanvas: genau ein Viewport-Frame, kein Scroll-Stitching.
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

async function openSearch(page) {
    await clickAndWaitForEvent(page, page.locator('[data-nav-search-open]'), '#nav-search-modal', 'shown.bs.modal');
    await page.locator('#nav-search-results li').first().waitFor();
}

async function closeSearch(page) {
    const hidden = page.evaluate(() => new Promise((resolve) => {
        document.querySelector('#nav-search-modal').addEventListener('hidden.bs.modal', () => resolve(), { once: true });
    }));
    await page.keyboard.press('Escape');
    await hidden;
}

async function desktop(browser) {
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

        // Ein paar Seiten besuchen, damit die Schnellsuche "Zuletzt besucht" zeigt.
        for (const url of ['/events', '/finances', '/files']) {
            await page.goto(`${BASE_URL}${url}`, { waitUntil: 'networkidle' });
        }

        // 1. Startseite
        await page.goto(`${BASE_URL}/dashboard`, { waitUntil: 'networkidle' });
        await shotCapped(page, '01-dashboard');

        // 2. Schnellsuche, leer: zuletzt besuchte Seiten und alle Seiten
        await openSearch(page);
        await shotModal(page, '02-search-empty');

        // 3. Schnellsuche mit Stichwort - "kassabuch" findet die Kassa
        await page.locator('#nav-search-input').fill('kassabuch');
        await page.locator('#nav-search-results li', { hasText: 'Kassa' }).first().waitFor();
        await shotModal(page, '03-search-keyword');
        await closeSearch(page);

        // 4. Eingeklappte Seitenleiste: nur Symbole
        await page.locator('[data-nav-toggle]').click();
        await page.waitForFunction(() => document.documentElement.classList.contains('nav-collapsed'));
        await shotModal(page, '04-sidebar-collapsed');
        // Zustand für spätere Läufe und andere Skripte zurücksetzen.
        await page.locator('[data-nav-toggle]').click();
        await page.waitForFunction(() => !document.documentElement.classList.contains('nav-collapsed'));
    } finally {
        await context.close();
    }
}

async function mobile(browser) {
    const context = await browser.newContext({
        viewport: MOBILE_VIEWPORT,
        isMobile: true,
        hasTouch: true,
        deviceScaleFactor: 1,
        ignoreHTTPSErrors: true,
    });
    const page = await context.newPage();

    try {
        await login(page);
        await page.goto(`${BASE_URL}/dashboard`, { waitUntil: 'networkidle' });

        // 5. Am Handy öffnet ☰ die Seitenleiste von links
        await clickAndWaitForEvent(page, page.locator('[data-nav-toggle]'), '#app-sidebar', 'shown.bs.offcanvas');
        await shotModal(page, '05-mobile-sidebar');
    } finally {
        await context.close();
    }
}

async function main() {
    fs.mkdirSync(IMAGES_DIR, { recursive: true });

    const browser = await chromium.launch();
    try {
        await desktop(browser);
        await mobile(browser);
        console.log('Fertig: alle Screenshots zu Start und Navigation erstellt.');
    } finally {
        await browser.close();
    }
}

main().catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
