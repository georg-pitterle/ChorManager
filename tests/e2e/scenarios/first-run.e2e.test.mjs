import { test, expect } from '@playwright/test';
import { VOICE_GROUPS, MEMBERS, PROJECT } from '../data/fixtures.mjs';
import { createMember } from '../steps/members.mjs';
import { createProject } from '../steps/projects.mjs';

// Das Produkt seedet SATB + je 2 Untergruppen ("Sopran 1"..."Bass 2") per
// Migration. Bootstrap legt nichts an - hier nur verifizieren, dass die
// geseedete Struktur nach fresh-db vorhanden ist.
test('Bootstrap: geseedete SATB-Struktur vorhanden', async ({ page }) => {
    await page.goto('/voice-groups');
    for (const name of VOICE_GROUPS) {
        await expect(page.getByText(name, { exact: true }).first()).toBeVisible();
    }
    // 8 geseedete Untergruppen erwartet (je Gruppe zwei).
    const subCount = await page.locator('[data-bs-target^="#deleteSubVoiceModal"]').count();
    expect(subCount).toBe(8);
});

test('Bootstrap: 8 Mitglieder je Untergruppe', async ({ page }) => {
    for (const member of MEMBERS) {
        await createMember(page, member);
    }
    await page.goto('/users');
    for (const member of MEMBERS) {
        await expect(page.getByText(`${member.lastName}`, { exact: false }).first()).toBeVisible();
    }
});

test('Bootstrap: Projekt anlegen', async ({ page }) => {
    await createProject(page, PROJECT);
    await page.goto('/projects');
    await expect(page.getByText(PROJECT.name, { exact: false }).first()).toBeVisible();
});
