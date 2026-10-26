import { test, expect } from '@playwright/test';
import {
    KEEPER_ROLE, LIST_PASSWORD, LIST_PROJECT, LIST_TITLE, TICK_COLUMN, NOTE_COLUMN,
    SOPRANO_KEEPER, SOPRANO_IN_PROJECT, SOPRANO_OUTSIDE_PROJECT, ALTO_IN_PROJECT, ALTO_KEEPER,
} from '../data/voiceGroupLists.mjs';
import { createRole } from '../steps/roles.mjs';
import { createMember } from '../steps/members.mjs';
import { createProject, addProjectMember } from '../steps/projects.mjs';
import { setMemberPassword } from '../steps/authz.mjs';
import { login } from '../steps/auth.mjs';
import { newBrowserContext } from '../steps/browser.mjs';
import { openMainNavigation } from '../steps/navigation.mjs';
import { createVoiceGroupList, addVoiceGroupListColumn, tickButton, noteInput } from '../steps/voiceGroupLists.mjs';

// Eigene Session als ein anderes Mitglied. Der Kontext erbt den Admin-storageState der
// Config, deshalb vor dem Login die Cookies leeren.
async function openSessionAs(browser, member) {
    const context = await newBrowserContext(browser);
    await context.clearCookies();
    const page = await context.newPage();
    await login(page, { email: member.email, password: LIST_PASSWORD });
    return { context, page };
}

// Stimmgruppen-Listen: Eine Person mit dem Recht "Stimmgruppen-Listen führen" legt für ihre
// Stimmgruppe eine Projektliste an, hakt ab und notiert - ohne Neuladen. In der Liste stehen nur
// die Projektmitglieder der eigenen Stimmgruppe. Das Recht allein öffnet keine fremde
// Stimmgruppe (404), Mitglieder ohne das Recht sehen den Bereich gar nicht (403, kein Menüpunkt).
test('Stimmgruppen-Listen: anlegen, abhaken, notieren, nur für die eigene Stimmgruppe', async ({ page, browser }) => {
    test.setTimeout(180_000);

    // 1. Als Admin: Rolle mit dem Recht, Mitglieder, Projekt samt Besetzung.
    await createRole(page, KEEPER_ROLE);
    const members = [SOPRANO_KEEPER, SOPRANO_IN_PROJECT, SOPRANO_OUTSIDE_PROJECT, ALTO_IN_PROJECT, ALTO_KEEPER];
    for (const member of members) {
        await createMember(page, member);
        setMemberPassword(member.email, LIST_PASSWORD);
    }
    await createProject(page, LIST_PROJECT);
    for (const member of [SOPRANO_KEEPER, SOPRANO_IN_PROJECT, ALTO_IN_PROJECT]) {
        await addProjectMember(page, LIST_PROJECT.name, member.lastName);
    }

    // 2. Die Sopran-Listenführerin findet den Bereich im Menü und legt die Liste an.
    const keeper = await openSessionAs(browser, SOPRANO_KEEPER);
    let listId;
    try {
        const keeperPage = keeper.page;
        await keeperPage.goto('/dashboard');
        await openMainNavigation(keeperPage);
        await expect(keeperPage.locator('a[href="/voice-group-lists"]:visible').first()).toBeVisible();

        listId = await createVoiceGroupList(keeperPage, { title: LIST_TITLE, project: LIST_PROJECT.name });
        await expect(keeperPage.locator('h1')).toHaveText(LIST_TITLE);
        await expect(keeperPage.locator('.page-header')).toContainText(`Projekt: ${LIST_PROJECT.name}`);

        // Nur die Projektmitglieder aus Sopran: Alt im Projekt und Sopran außerhalb fehlen.
        // Nur Mitgliederzeilen: Die Table-Engine hängt eine eigene Zeile "Filter zurücksetzen" an.
        const rows = keeperPage.locator('.voice-group-list-table tbody tr[data-sort-member_name]');
        await expect(rows).toHaveCount(2);
        await expect(keeperPage.locator('.page-header')).toContainText('Sopran · 2 Mitglieder');
        await expect(rows.filter({ hasText: SOPRANO_KEEPER.lastName })).toHaveCount(1);
        await expect(rows.filter({ hasText: SOPRANO_IN_PROJECT.lastName })).toHaveCount(1);
        await expect(rows.filter({ hasText: SOPRANO_OUTSIDE_PROJECT.lastName })).toHaveCount(0);
        await expect(rows.filter({ hasText: ALTO_IN_PROJECT.lastName })).toHaveCount(0);

        // 3. Spalten: eine Häkchen- und eine Notiz-Spalte.
        await addVoiceGroupListColumn(keeperPage, TICK_COLUMN);
        await addVoiceGroupListColumn(keeperPage, NOTE_COLUMN);
        await expect(keeperPage.locator('.voice-group-list-table thead')).toContainText(TICK_COLUMN.label);
        await expect(keeperPage.locator('.voice-group-list-table thead')).toContainText(NOTE_COLUMN.label);

        const tickColumnId = await keeperPage
            .locator('[data-vgl-progress-item]', { hasText: TICK_COLUMN.label })
            .getAttribute('data-vgl-progress-item');
        const progress = keeperPage.locator(`[data-vgl-progress="${tickColumnId}"]`);
        await expect(progress).toHaveText('0 / 2');

        // 4. Abhaken speichert ohne Neuladen: Eine Markierung im window überlebt den Klick.
        await keeperPage.evaluate(() => {
            window.vglNoReload = true;
        });
        const gretesTick = tickButton(keeperPage, TICK_COLUMN.label, SOPRANO_IN_PROJECT.lastName);
        await gretesTick.click();
        await expect(gretesTick).toHaveAttribute('aria-pressed', 'true');
        await expect(progress).toHaveText('1 / 2');

        const gretesNote = noteInput(keeperPage, NOTE_COLUMN.label, SOPRANO_IN_PROJECT.lastName);
        await gretesNote.fill('M');
        await gretesNote.press('Enter');
        const noteCell = keeperPage.locator('.voice-group-list-cell', { has: gretesNote });
        await expect(noteCell.locator('[data-vgl-status]')).toHaveText('Gespeichert');
        expect(await keeperPage.evaluate(() => window.vglNoReload)).toBe(true);

        // 5. Nach dem Neuladen steht beides noch da.
        await keeperPage.reload();
        await expect(tickButton(keeperPage, TICK_COLUMN.label, SOPRANO_IN_PROJECT.lastName))
            .toHaveAttribute('aria-pressed', 'true');
        await expect(noteInput(keeperPage, NOTE_COLUMN.label, SOPRANO_IN_PROJECT.lastName)).toHaveValue('M');

        // 6. "Nur offene zeigen" lässt nur die noch nicht Abgehakte stehen.
        await keeperPage.locator('[data-vgl-progress-item]', { hasText: TICK_COLUMN.label })
            .getByRole('link', { name: 'Nur offene zeigen' }).click();
        await keeperPage.waitForURL(`**/voice-group-lists/${listId}?open=${tickColumnId}`);
        await expect(rows).toHaveCount(1);
        await expect(rows.first()).toContainText(SOPRANO_KEEPER.lastName);
        await expect(keeperPage.locator('.voice-group-list-progress')).toContainText('1 von 2 offen');

        await tickButton(keeperPage, TICK_COLUMN.label, SOPRANO_KEEPER.lastName).click();
        await keeperPage.getByRole('link', { name: 'Alle zeigen' }).click();
        await keeperPage.waitForURL(`**/voice-group-lists/${listId}`);
        await expect(progress).toHaveText('2 / 2');
        await expect(keeperPage.locator(`[data-vgl-done="${tickColumnId}"]`)).toBeVisible();

        // 7. Die Übersicht meldet die volle Häkchen-Spalte.
        await keeperPage.goto('/voice-group-lists');
        const overviewItem = keeperPage.locator('.voice-group-list-overview li', { hasText: LIST_TITLE });
        await expect(overviewItem).toContainText(`${TICK_COLUMN.label}: alle erledigt`);
        await expect(overviewItem).toContainText(`Projekt: ${LIST_PROJECT.name}`);
    } finally {
        await keeper.context.close();
    }

    // 8. Dasselbe Recht für Alt öffnet die Sopran-Liste nicht - nicht einmal ihr Dasein.
    const altoKeeper = await openSessionAs(browser, ALTO_KEEPER);
    try {
        const altoPage = altoKeeper.page;
        expect((await altoPage.request.get(`/voice-group-lists/${listId}`)).status()).toBe(404);
        await altoPage.goto('/voice-group-lists');
        await expect(altoPage.locator('h1')).toHaveText('Stimmgruppen-Listen');
        await expect(altoPage.locator(`a[href="/voice-group-lists/${listId}"]`)).toHaveCount(0);
    } finally {
        await altoKeeper.context.close();
    }

    // 9. Wer in der Liste steht, aber das Recht nicht hat, sieht den Bereich nicht.
    const plainMember = await openSessionAs(browser, SOPRANO_IN_PROJECT);
    try {
        const memberPage = plainMember.page;
        expect((await memberPage.request.get('/voice-group-lists')).status()).toBe(403);
        expect((await memberPage.request.get(`/voice-group-lists/${listId}`)).status()).toBe(403);
        await memberPage.goto('/dashboard');
        await openMainNavigation(memberPage);
        await expect(memberPage.locator('a[href^="/voice-group-lists"]:visible')).toHaveCount(0);
    } finally {
        await plainMember.context.close();
    }

    // 10. Archivieren und Löschen über "Liste verwalten".
    const cleanup = await openSessionAs(browser, SOPRANO_KEEPER);
    try {
        const keeperPage = cleanup.page;
        await keeperPage.goto(`/voice-group-lists/${listId}`);
        await keeperPage.getByRole('button', { name: 'Liste verwalten' }).click();
        await keeperPage.getByRole('button', { name: 'Archivieren' }).click();
        await keeperPage.waitForURL('**/voice-group-lists');
        const archive = keeperPage.locator('details.voice-group-list-archive');
        await expect(archive.locator('summary')).toContainText('Archiviert');
        await archive.locator('summary').click();
        await archive.locator(`a[href="/voice-group-lists/${listId}"]`).click();
        await keeperPage.waitForURL(`**/voice-group-lists/${listId}`);
        await expect(keeperPage.locator('.alert-info')).toContainText('Archiviert');
        await expect(tickButton(keeperPage, TICK_COLUMN.label, SOPRANO_IN_PROJECT.lastName)).toHaveCount(0);

        keeperPage.once('dialog', (dialog) => dialog.accept());
        await keeperPage.getByRole('button', { name: 'Liste verwalten' }).click();
        await keeperPage.getByRole('button', { name: 'Liste löschen' }).click();
        await keeperPage.waitForURL('**/voice-group-lists');
        expect((await keeperPage.request.get(`/voice-group-lists/${listId}`)).status()).toBe(404);
    } finally {
        await cleanup.context.close();
    }
});
