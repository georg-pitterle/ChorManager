import { test, expect } from '@playwright/test';

test.describe.configure({ timeout: 120000 });

async function loginAndOpenKanban(page) {
    page.on('dialog', async (dialog) => {
        await dialog.dismiss();
    });

    await page.goto('https://chormanager.ddev.site/login');
    await page.fill('input[name="email"]', 'seed.001@chor.local');
    await page.fill('input[name="password"]', 'seed');
    await page.click('button[type="submit"]');

    await page.goto('https://chormanager.ddev.site/projects/5/tasks');
    await page.waitForSelector('#kanban-view', { state: 'attached' });
    await expect(page.locator('#btn-view-kanban')).toBeVisible();
    await page.click('#btn-view-kanban');
    await page.waitForSelector('#kanban-view:not([hidden])', { timeout: 10000 });
    await page.waitForSelector('.kanban-cards-container', { state: 'visible' });
}

async function dragCardToZone(page, card, targetZone) {
    const taskId = await card.getAttribute('data-task-id');
    expect(taskId).toBeTruthy();

    const sourceBox = await card.boundingBox();
    const targetBox = await targetZone.boundingBox();
    expect(sourceBox).toBeTruthy();
    expect(targetBox).toBeTruthy();

    const statusUpdatePromise = page.waitForResponse(function (response) {
        return response.url().includes('/tasks/')
            && response.url().includes('/status')
            && response.request().method() === 'POST';
    }, { timeout: 5000 }).catch(function () {
        return null;
    });

    await page.mouse.move(sourceBox.x + sourceBox.width / 2, sourceBox.y + sourceBox.height / 2);
    await page.mouse.down();
    await page.mouse.move(targetBox.x + targetBox.width / 2, targetBox.y + targetBox.height / 2, { steps: 16 });
    await page.mouse.up();

    const movedCard = targetZone.locator('.kanban-card[data-task-id="' + taskId + '"]');
    await expect(movedCard.first()).toBeVisible({ timeout: 10000 });

    const statusUpdateResponse = await statusUpdatePromise;
    if (statusUpdateResponse) {
        expect(statusUpdateResponse.ok()).toBeTruthy();
    }

    return taskId;
}

// Kanban Drag & Drop Test (Desktop, direkt auf Aufgabenboard)
test.describe('Kanban Drag & Drop', () => {
    test('Statuswechsel per Drag & Drop funktioniert', async ({ page }) => {
        await loginAndOpenKanban(page);

        // Finde eine Karte in "Offen" und ziehe sie nach "In Bearbeitung"
        const offenZone = page.locator('.kanban-cards-container[data-drop-zone="Offen"]');
        const bearbeitungZone = page.locator('.kanban-cards-container[data-drop-zone="In Bearbeitung"]');
        const card = offenZone.locator('.kanban-card').first();
        await expect(card).toBeVisible();
        const movedTaskId = await dragCardToZone(page, card, bearbeitungZone);

        // Erwartung: Karte ist jetzt in "In Bearbeitung"
        const movedCard = bearbeitungZone.locator('.kanban-card[data-task-id="' + movedTaskId + '"]');
        await expect(movedCard.first()).toBeVisible({ timeout: 10000 });

        // Persistenz prüfen: nach Reload muss Karte weiterhin in Zielspalte sein
        await page.reload();
        await page.waitForSelector('#kanban-view', { state: 'attached' });
        await page.click('#btn-view-kanban');
        await page.waitForSelector('#kanban-view:not([hidden])', { timeout: 10000 });
        const movedCardAfterReload = page.locator('.kanban-cards-container[data-drop-zone="In Bearbeitung"] .kanban-card[data-task-id="' + movedTaskId + '"]');
        await expect(movedCardAfterReload.first()).toBeVisible({ timeout: 10000 });
    });

    test('Leere-Hinweis erscheint und verschwindet korrekt beim Verschieben', async ({ page }) => {
        await loginAndOpenKanban(page);

        const statuses = ['Offen', 'In Bearbeitung', 'Blockiert', 'Abgeschlossen'];
        let sourceStatus = null;
        let sourceCount = 0;

        for (const status of statuses) {
            const count = await page.locator('.kanban-cards-container[data-drop-zone="' + status + '"] .kanban-card').count();
            if (count > 0) {
                sourceStatus = status;
                sourceCount = count;
                if (count === 1) {
                    break;
                }
            }
        }

        expect(sourceStatus).toBeTruthy();

        const targetStatus = statuses.find(function (status) {
            return status !== sourceStatus;
        });

        const sourceZone = page.locator('.kanban-cards-container[data-drop-zone="' + sourceStatus + '"]');
        const targetZone = page.locator('.kanban-cards-container[data-drop-zone="' + targetStatus + '"]');

        // Quelle komplett leeren, damit der Platzhalter erscheinen muss
        for (let i = 0; i < sourceCount; i += 1) {
            const card = sourceZone.locator('.kanban-card').first();
            await dragCardToZone(page, card, targetZone);
        }

        await expect(sourceZone.locator('.kanban-card')).toHaveCount(0);
        await expect(sourceZone.locator('.kanban-empty-placeholder')).toBeVisible();

        // Eine Karte zurück in die leere Quelle ziehen: Platzhalter muss verschwinden
        const backCard = targetZone.locator('.kanban-card').first();
        await dragCardToZone(page, backCard, sourceZone);

        await expect(sourceZone.locator('.kanban-card')).toHaveCount(1);
        await expect(sourceZone.locator('.kanban-empty-placeholder')).toBeHidden();
    });
});
