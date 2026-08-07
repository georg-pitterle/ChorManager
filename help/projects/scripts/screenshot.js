'use strict';

/**
 * Erstellt Screenshots des Projekt-Moduls (Anlegen, Planung/Aufgaben,
 * Mitgliederverwaltung) für die How-To-Dokumentation (help/projects/docs/).
 *
 * Nutzung:
 *   node help/projects/scripts/screenshot.js
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

// Normale Seiten/Listen: volle Seite inkl. Scroll-Inhalt.
async function shot(page, name) {
    const filePath = path.join(IMAGES_DIR, `${name}.png`);
    await page.screenshot({ path: filePath, fullPage: true });
    console.log(`gespeichert: ${path.relative(process.cwd(), filePath)}`);
}

/**
 * Bei offenem Modal oder offenem Dropdown: genau ein Viewport-Frame, kein
 * fullPage-Scroll-Stitching. fullPage zeichnet position:fixed-Elemente
 * (Navbar, Modal-Backdrop) sonst mehrfach übereinander.
 */
async function shotModal(page, name) {
    const filePath = path.join(IMAGES_DIR, `${name}.png`);
    await page.screenshot({ path: filePath, fullPage: false });
    console.log(`gespeichert: ${path.relative(process.cwd(), filePath)}`);
}

/**
 * Lange Listen (z. B. alle Projektmitglieder) ergeben mit fullPage ein riesiges,
 * unbrauchbar hohes Bild. Für die Doku reicht Kopfbereich + Toolbar + einige
 * Zeilen, damit Aufbau und Bedienung klar werden. Deshalb hier auf maxHeight
 * beschneiden (Default 1400px) - kürzere Seiten werden nicht künstlich gestreckt.
 */
async function shotCapped(page, name, maxHeight = 1400) {
    const fullHeight = await page.evaluate(() => Math.ceil(document.documentElement.scrollHeight));
    const height = Math.min(fullHeight, maxHeight);
    const filePath = path.join(IMAGES_DIR, `${name}.png`);
    await page.screenshot({ path: filePath, clip: { x: 0, y: 0, width: VIEWPORT.width, height } });
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
 * Bootstrap-Modals blenden per CSS-Transition ein; der DOM-Zustand ist sofort
 * da, das Rendering erst nach Transition-Ende. Wir warten daher auf die
 * Bootstrap-Events (shown.bs.modal / hidden.bs.modal) statt auf eine feste Zeit.
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

// Liest die Projekt-IDs aus den Bearbeiten-Modal-Triggern der Projektliste.
async function readProjectIds(page) {
    return page.evaluate(() =>
        Array.from(document.querySelectorAll('[data-bs-target^="#editProjectModal"]'))
            .map((el) => el.getAttribute('data-bs-target').replace('#editProjectModal', ''))
            .filter((id) => /^[0-9]+$/.test(id))
    );
}

// Findet das erste Projekt mit mindestens einer Aufgabe und dessen erste
// Task-ID, damit Planungs- und Detail-Screenshots nie leer sind.
async function findProjectWithTasks(page, projectIds) {
    for (const projectId of projectIds) {
        await page.goto(`${BASE_URL}/projects/${projectId}/tasks`, { waitUntil: 'networkidle' });
        const detailHref = await page
            .locator('#tasksTable tbody tr a[href^="/tasks/"]')
            .first()
            .getAttribute('href')
            .catch(() => null);
        if (detailHref) {
            return { projectId, taskId: detailHref.replace('/tasks/', '') };
        }
    }
    return { projectId: projectIds[0], taskId: null };
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

        // ── Oberthema: Projekte anlegen/verwalten ──

        // 1. Projektliste
        await page.goto(`${BASE_URL}/projects`, { waitUntil: 'networkidle' });
        await page.locator('#projectsTable').waitFor({ state: 'visible' });
        await shot(page, '01-list');

        const projectIds = await readProjectIds(page);
        const firstId = projectIds[0];

        // 2. Modal: Neues Projekt anlegen
        await clickAndWaitForEvent(
            page,
            page.locator('[data-bs-target="#addProjectModal"]'),
            '#addProjectModal',
            'shown.bs.modal'
        );
        await shotModal(page, '02-new-project-modal');
        await clickAndWaitForEvent(
            page,
            page.locator('#addProjectModal .btn-secondary[data-bs-dismiss="modal"]'),
            '#addProjectModal',
            'hidden.bs.modal'
        );

        // 3. Modal: Projekt bearbeiten (erstes Projekt)
        await clickAndWaitForEvent(
            page,
            page.locator(`[data-bs-target="#editProjectModal${firstId}"]`),
            `#editProjectModal${firstId}`,
            'shown.bs.modal'
        );
        await shotModal(page, '03-edit-project-modal');
        await clickAndWaitForEvent(
            page,
            page.locator(`#editProjectModal${firstId} .btn-secondary[data-bs-dismiss="modal"]`),
            `#editProjectModal${firstId}`,
            'hidden.bs.modal'
        );

        // 4. Aktionsmenü (Planung / Mitglieder) der ersten Projektzeile
        await page.locator('#projectsTable tbody tr').first().locator('.dropdown-toggle-split').click();
        await page.locator('#projectsTable tbody tr').first().locator('.dropdown-menu.show').waitFor({ state: 'visible' });
        await shotModal(page, '04-actions-menu');

        // ── Unterthema: Planung / Aufgaben ──

        const { projectId, taskId } = await findProjectWithTasks(page, projectIds);

        // 5. Aufgabenliste
        await page.goto(`${BASE_URL}/projects/${projectId}/tasks`, { waitUntil: 'networkidle' });
        await page.locator('#tasksTable').waitFor({ state: 'visible' });
        await shot(page, '05-tasks-list');

        // 6. Kanban-Ansicht
        await page.locator('#btn-view-kanban').click();
        await page.waitForFunction(() => {
            const kanban = document.querySelector('#kanban-view');
            return kanban && !kanban.hasAttribute('hidden');
        });
        await page.locator('#kanban-board').waitFor({ state: 'visible' });
        await shot(page, '06-tasks-kanban');

        // 7. Modal: Neue Aufgabe
        await page.locator('#btn-view-list').click();
        await clickAndWaitForEvent(
            page,
            page.locator('[data-bs-target="#addTaskModal"]'),
            '#addTaskModal',
            'shown.bs.modal'
        );
        await shotModal(page, '07-new-task-modal');
        await clickAndWaitForEvent(
            page,
            page.locator('#addTaskModal .btn-secondary[data-bs-dismiss="modal"]'),
            '#addTaskModal',
            'hidden.bs.modal'
        );

        // 8. Aufgaben-Detailseite
        if (taskId) {
            await page.goto(`${BASE_URL}/tasks/${taskId}`, { waitUntil: 'networkidle' });
            await page.locator('#task-detail-title').waitFor({ state: 'visible' });
            await shot(page, '08-task-detail');
        }

        // ── Unterthema: Mitglieder ──

        // 9. Projektmitglieder verwalten
        await page.goto(`${BASE_URL}/projects/${projectId}/members`, { waitUntil: 'networkidle' });
        await page.locator('#projectMembersTable').waitFor({ state: 'visible' });
        await shotCapped(page, '09-members');

        // 10. "Meine Projekte" (Einstieg für Stimmvertretung ohne Stammdaten-Recht)
        await page.goto(`${BASE_URL}/projects/members`, { waitUntil: 'networkidle' });
        await page.locator('.page-header').waitFor({ state: 'visible' });
        await shot(page, '10-my-projects');

        console.log('Fertig: alle Projekt-Screenshots erstellt.');
    } finally {
        await browser.close();
    }
}

main().catch((err) => {
    console.error(err);
    process.exitCode = 1;
});
