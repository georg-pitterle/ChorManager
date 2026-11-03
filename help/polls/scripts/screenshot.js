'use strict';

/**
 * Erstellt Screenshots des Umfragen-Moduls für die Dokumentation (help/polls/docs/).
 *
 * Nutzung:
 *   node help/polls/scripts/screenshot.js
 *
 * Voraussetzung: frischer Seed-Lauf (reset-and-seed) und FEATURE_POLLS aktiv.
 * Die Aufnahmen kommen aus drei Sichten: Verwaltung (seed.001), ein Mitglied, das noch
 * nicht abgestimmt hat (seed.040), und ein Mitglied mit gezählter anonymer Stimme (seed.017).
 *
 * Optional per Umgebungsvariable überschreibbar:
 *   BASE_URL   Basis-URL der Dev-Umgebung (Default: https://chormanager.ddev.site)
 */

const path = require('path');
const fs = require('fs');
const { chromium } = require('playwright');
const { prepareSidebarForScreenshots } = require('../../screenshot-support');

const BASE_URL = process.env.BASE_URL || 'https://chormanager.ddev.site';
const PASSWORD = process.env.LOGIN_PASSWORD || 'seed';
const IMAGES_DIR = path.join(__dirname, '..', 'screenshots');

const VIEWPORT = { width: 1440, height: 900 };

const ADMIN = process.env.LOGIN_EMAIL || 'seed.001@chor.local';
const MEMBER = 'seed.040@chor.local';
const MEMBER_VOTED = 'seed.017@chor.local';

/** Umfragen aus dem Seed (Titel statt Id, damit die Skripte robust bleiben). */
const POLL_NAMED_OPEN = 'Ausflug im Mai: wohin?';
const POLL_ANONYMOUS_OPEN = 'Welche Farbe sollen die neuen Chor-T-Shirts haben?';
const POLL_NAMED_CLOSED = 'Probenbeginn ab Herbst';
const POLL_TEXT_OPEN = 'Vorschläge für den nächsten Konzerttitel';

/** Lange Seiten: Kopf und erste Inhalte, auf maxHeight beschnitten. */
async function shotCapped(page, name, maxHeight = 1400) {
    // Die mitlaufende Aktionsleiste würde im Ganzseitenbild über dem Formular liegen.
    await page.addStyleTag({ content: '.form-action-bar { position: static !important; }' });
    const fullHeight = await page.evaluate(() => Math.ceil(document.documentElement.scrollHeight));
    const height = Math.min(fullHeight, maxHeight);
    const filePath = path.join(IMAGES_DIR, `${name}.png`);
    await page.screenshot({ path: filePath, fullPage: true, clip: { x: 0, y: 0, width: VIEWPORT.width, height } });
    console.log(`gespeichert: ${path.relative(process.cwd(), filePath)} (${VIEWPORT.width}x${height})`);
}

/** Einzelnes Viewport-Bild ohne Scroll-Stitching, etwa bei geöffnetem Dropdown. */
async function shotViewport(page, name) {
    const filePath = path.join(IMAGES_DIR, `${name}.png`);
    await page.screenshot({ path: filePath, fullPage: false });
    console.log(`gespeichert: ${path.relative(process.cwd(), filePath)}`);
}

async function login(page, email) {
    await page.goto(`${BASE_URL}/login`, { waitUntil: 'networkidle' });
    await page.locator('#emailInput').fill(email);
    await page.locator('#passwordInput').fill(PASSWORD);
    await Promise.all([
        page.waitForURL((url) => !url.pathname.startsWith('/login'), { waitUntil: 'networkidle' }),
        page.locator('form[action="/login"] button[type="submit"]').click(),
    ]);
}

/** Öffnet die Umfrage über ihren Titel in der Übersicht, damit keine Ids im Skript stehen. */
async function openPoll(page, title, suffix = '') {
    await page.goto(`${BASE_URL}/polls`, { waitUntil: 'networkidle' });
    const link = page.locator('a.poll-overview__title', { hasText: title }).first();
    const href = await link.getAttribute('href');
    await page.goto(`${BASE_URL}${href}${suffix}`, { waitUntil: 'networkidle' });
    await page.locator('.poll').waitFor({ state: 'visible' });
}

async function withSession(browser, email, fn) {
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
        await login(page, email);
        await fn(page);
    } finally {
        await context.close();
    }
}

async function main() {
    fs.mkdirSync(IMAGES_DIR, { recursive: true });
    const browser = await chromium.launch();

    try {
        // Verwaltung: Übersicht, Ergebnisse, Verwaltungsmenü, Formular
        await withSession(browser, ADMIN, async (page) => {
            await page.goto(`${BASE_URL}/polls`, { waitUntil: 'networkidle' });
            await page.locator('.poll-overview').first().waitFor({ state: 'visible' });
            await shotCapped(page, '01-overview');

            await openPoll(page, POLL_NAMED_OPEN, '?results=1');
            await page.locator('.poll-result__voters summary').first().click();
            await shotCapped(page, '05-result-named');

            await openPoll(page, POLL_NAMED_CLOSED);
            await shotCapped(page, '06-result-closed');

            await openPoll(page, POLL_TEXT_OPEN, '?results=1');
            await shotCapped(page, '08-result-text');

            await openPoll(page, POLL_NAMED_OPEN, '?results=1');
            await page.locator('button.dropdown-toggle', { hasText: 'Umfrage verwalten' }).click();
            await page.locator('.dropdown-menu.show').waitFor({ state: 'visible' });
            await shotViewport(page, '09-manage-menu');

            await page.goto(`${BASE_URL}/polls/create`, { waitUntil: 'networkidle' });
            await page.locator('.poll-form').waitFor({ state: 'visible' });
            await shotCapped(page, '10-create-form', 1700);
        });

        // Mitglied, das noch nicht abgestimmt hat: Startseite und Stimmzettel
        await withSession(browser, MEMBER, async (page) => {
            await page.goto(`${BASE_URL}/`, { waitUntil: 'networkidle' });
            const tile = page.locator('section[aria-labelledby="dashboard-polls-title"]');
            await tile.waitFor({ state: 'visible' });
            await tile.scrollIntoViewIfNeeded();
            const filePath = path.join(IMAGES_DIR, '02-dashboard-tile.png');
            await tile.screenshot({ path: filePath });
            console.log(`gespeichert: ${path.relative(process.cwd(), filePath)}`);

            await openPoll(page, POLL_NAMED_OPEN);
            await shotCapped(page, '03-ballot-choice');

            await openPoll(page, POLL_ANONYMOUS_OPEN);
            await shotCapped(page, '04-ballot-anonymous');

            await openPoll(page, POLL_TEXT_OPEN);
            await shotCapped(page, '11-ballot-text');
        });

        // Mitglied mit gezählter anonymer Stimme: Zahlen erst nach Fristende
        await withSession(browser, MEMBER_VOTED, async (page) => {
            await openPoll(page, POLL_ANONYMOUS_OPEN);
            await shotCapped(page, '12-anonymous-counted');
        });
    } finally {
        await browser.close();
    }
}

main().catch((error) => {
    console.error(error);
    process.exit(1);
});
