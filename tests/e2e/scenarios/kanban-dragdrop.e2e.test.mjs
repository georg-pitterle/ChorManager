import { test, expect } from '@playwright/test';
import { createProject } from '../steps/projects.mjs';
import {
    isTaskModuleEnabled,
    taskBoardPath,
    createTask,
    openKanbanBoard,
    dragCardToZone,
} from '../steps/tasks.mjs';

// Ziehen und Ablegen auf dem Kanban-Board: Statuswechsel und Leere-Hinweis.
//
// Der Test lag vorher unter tests/js/ und war damit von keinem Läufer erfasst:
// tests/js wird mit `node --test` gefahren, und dort brach er beim ersten
// `test.describe.configure()` ab ("Playwright Test did not expect ... to be called
// here"); die Playwright-Konfiguration wiederum sieht nur tests/e2e. Er lief also
// nirgends und bewachte nichts.
//
// Beim Umzug sind die festen Kennungen aus dem Dev-Seed gefallen (Anmeldung als
// seed.001@chor.local, Projekt 5). Die Suite startet auf einer frischen Datenbank -
// das Szenario legt seine Aufgaben deshalb selbst an. Der Projektname trägt einen
// Zeitstempel, weil die Szenarien parallel gegen dieselbe Datenbank laufen.

const STATUSES = ['Offen', 'In Bearbeitung', 'Blockiert', 'Abgeschlossen'];

function zone(page, status) {
    return page.locator(`.kanban-cards-container[data-drop-zone="${status}"]`);
}

async function projectWithTasks(page, label, titles) {
    const name = `${label} ${Date.now()}`;

    await createProject(page, {
        name,
        description: 'Angelegt vom Kanban-Szenario.',
        startDate: '2026-01-01',
        endDate: '2026-12-31',
    });

    const boardPath = await taskBoardPath(page, name);

    for (const title of titles) {
        await createTask(page, boardPath, { title, status: 'Offen' });
    }

    return boardPath;
}

test('Kanban: Statuswechsel per Ziehen bleibt nach dem Neuladen erhalten', async ({ page }) => {
    test.setTimeout(120_000);
    test.skip(!(await isTaskModuleEnabled(page)), 'Aufgaben-Modul ist in dieser Umgebung aus.');

    const boardPath = await projectWithTasks(page, 'Kanban-Zug', ['Noten kopieren', 'Saal reservieren']);
    await openKanbanBoard(page, boardPath);

    const card = zone(page, 'Offen').locator('.kanban-card').first();
    await expect(card).toBeVisible();

    const movedTaskId = await dragCardToZone(page, card, zone(page, 'In Bearbeitung'));

    const moved = zone(page, 'In Bearbeitung').locator(`.kanban-card[data-task-id="${movedTaskId}"]`);
    await expect(moved.first()).toBeVisible({ timeout: 10000 });

    // Der eigentliche Punkt: der Zug muss gespeichert sein, nicht nur im DOM stehen.
    await page.reload();
    await openKanbanBoard(page);

    await expect(
        zone(page, 'In Bearbeitung').locator(`.kanban-card[data-task-id="${movedTaskId}"]`).first()
    ).toBeVisible({ timeout: 10000 });
});

test('Kanban: Leere-Hinweis erscheint und verschwindet beim Verschieben', async ({ page }) => {
    test.setTimeout(120_000);
    test.skip(!(await isTaskModuleEnabled(page)), 'Aufgaben-Modul ist in dieser Umgebung aus.');

    const boardPath = await projectWithTasks(page, 'Kanban-Hinweis', ['Programmheft setzen']);
    await openKanbanBoard(page, boardPath);

    const sourceStatus = 'Offen';
    const targetStatus = STATUSES.find((status) => status !== sourceStatus);

    const sourceZone = zone(page, sourceStatus);
    const targetZone = zone(page, targetStatus);

    const sourceCount = await sourceZone.locator('.kanban-card').count();
    expect(sourceCount).toBeGreaterThan(0);

    // Quelle komplett leeren, damit der Platzhalter erscheinen muss.
    for (let i = 0; i < sourceCount; i += 1) {
        await dragCardToZone(page, sourceZone.locator('.kanban-card').first(), targetZone);
    }

    await expect(sourceZone.locator('.kanban-card')).toHaveCount(0);
    await expect(sourceZone.locator('.kanban-empty-placeholder')).toBeVisible();

    // Eine Karte zurück in die leere Spalte: der Platzhalter muss wieder weichen.
    await dragCardToZone(page, targetZone.locator('.kanban-card').first(), sourceZone);

    await expect(sourceZone.locator('.kanban-card')).toHaveCount(1);
    await expect(sourceZone.locator('.kanban-empty-placeholder')).toBeHidden();
});
