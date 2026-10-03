import { test, expect } from '@playwright/test';
import { createMember } from '../steps/members.mjs';
import { login } from '../steps/auth.mjs';
import { setMemberPassword } from '../steps/authz.mjs';
import { newBrowserContext, MOBILE } from '../steps/browser.mjs';
import { openMainNavigation } from '../steps/navigation.mjs';
import { PLAIN_MEMBER, PLAIN_MEMBER_PASSWORD } from '../data/navigation.mjs';

const isMac = process.platform === 'darwin';
const SHORTCUT = isMac ? 'Meta+k' : 'Control+k';

test.describe('Seitenleiste und Schnellsuche', () => {
    test('Admin: Strg+K findet Stimmgruppen über das Stichwort "sopran"', async ({ page }) => {
        await page.goto('/dashboard');
        await page.keyboard.press(SHORTCUT);
        const input = page.locator('#nav-search-input');
        await expect(input).toBeFocused();

        await input.fill('sopran');
        const first = page.locator('#nav-search-results [role="option"]').first();
        await expect(first).toContainText('Stimmgruppen');
        await expect(first).toContainText('Stichwort: sopran');

        await input.press('Enter');
        await expect(page).toHaveURL(/\/voice-groups$/);
    });

    test('Admin: leeres Suchfeld zeigt zuletzt besuchte Seiten', async ({ page }) => {
        await page.goto('/events');
        await page.goto('/dashboard');
        await page.locator('[data-nav-search-open]').click();

        const options = page.locator('#nav-search-results');
        await expect(options).toContainText('Zuletzt besucht');
        await expect(page.locator('#nav-search-results [role="option"]').first()).toContainText('Start');
        await page.keyboard.press('Escape');
        await expect(page.locator('#nav-search-modal')).toBeHidden();
    });

    test('Mitglied findet keine Seite, für die das Recht fehlt', async ({ page, browser }) => {
        await createMember(page, PLAIN_MEMBER);
        setMemberPassword(PLAIN_MEMBER.email, PLAIN_MEMBER_PASSWORD);

        const context = await newBrowserContext(browser);
        await context.clearCookies();
        const memberPage = await context.newPage();
        try {
            await login(memberPage, { email: PLAIN_MEMBER.email, password: PLAIN_MEMBER_PASSWORD });
            await memberPage.goto('/dashboard');
            await memberPage.locator('[data-nav-search-open]').click();
            await memberPage.locator('#nav-search-input').fill('sopran');
            await expect(memberPage.locator('[data-nav-search-empty]')).toBeVisible();
            await expect(memberPage.locator('#nav-search-results [role="option"]')).toHaveCount(0);
        } finally {
            await context.close();
        }
    });

    test('Strg+K bei offenem anderen Modal öffnet keine Suche', async ({ page }) => {
        await page.goto('/users');
        await page.click('[data-bs-target="#addUserModal"]');
        await expect(page.locator('#addUserModal')).toBeVisible();

        await page.keyboard.press(SHORTCUT);
        await expect(page.locator('#nav-search-modal')).toBeHidden();
    });

    test('Schließen der Suche gibt den Fokus an den Auslöser zurück', async ({ page }) => {
        await page.goto('/dashboard');
        const trigger = page.locator('[data-nav-search-open]');
        await trigger.focus();
        await page.keyboard.press('Enter');
        await expect(page.locator('#nav-search-input')).toBeFocused();

        await page.keyboard.press('Escape');
        await expect(page.locator('#nav-search-modal')).toBeHidden();
        await expect(trigger).toBeFocused();
    });

    test('Desktop: Symbolleiste hat keinen unsichtbaren Faltknopf im Tab-Weg', async ({ page }) => {
        test.skip(MOBILE, 'Einklappen zur Symbolleiste gibt es nur am Desktop.');

        await page.goto('/dashboard');
        await page.locator('button[data-nav-toggle]').click();
        await expect(page.locator('html')).toHaveClass(/\bnav-collapsed\b/);

        const fold = page.locator('[data-nav-fold="administration"]');
        const focusable = await fold.evaluate((button) => {
            button.focus();
            return document.activeElement === button;
        });
        expect(focusable, 'Der Faltknopf darf in der Symbolleiste keinen Fokus bekommen').toBe(false);

        // Zustand für die anderen Tests zurücksetzen.
        await page.locator('button[data-nav-toggle]').click();
        await expect(page.locator('html')).not.toHaveClass(/\bnav-collapsed\b/);
    });

    test('Desktop: Seite läuft neben der Leiste nicht seitlich über', async ({ page }) => {
        test.skip(MOBILE, 'Die feste Leiste gibt es nur am Desktop.');

        const overflow = () => page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);

        await page.goto('/users');
        expect(await overflow(), 'breite Leiste: kein horizontaler Überlauf').toBeLessThanOrEqual(0);

        await page.locator('button[data-nav-toggle]').click();
        await expect(page.locator('html')).toHaveClass(/\bnav-collapsed\b/);
        expect(await overflow(), 'Symbolleiste: kein horizontaler Überlauf').toBeLessThanOrEqual(0);

        await page.locator('button[data-nav-toggle]').click();
        await expect(page.locator('html')).not.toHaveClass(/\bnav-collapsed\b/);
    });

    test('Desktop: Einklappen und Administration überleben ein Neuladen', async ({ page }) => {
        test.skip(MOBILE, 'Einklappen zur Symbolleiste gibt es nur am Desktop.');

        await page.goto('/dashboard');
        const administration = page.locator('[data-nav-section="administration"] .app-sidebar__items');
        await expect(administration).toBeHidden();

        await page.locator('[data-nav-fold="administration"]').click();
        await expect(administration).toBeVisible();

        await page.locator('button[data-nav-toggle]').click();
        await expect(page.locator('html')).toHaveClass(/\bnav-collapsed\b/);
        await expect(page.locator('#app-sidebar .app-sidebar__label').first()).toBeHidden();

        await page.reload();
        await expect(page.locator('html')).toHaveClass(/\bnav-collapsed\b/);
        // In der Symbolleiste gibt es keine Überschrift zum Aufklappen - Backups muss sichtbar sein.
        await expect(page.locator('#app-sidebar a[href="/backups"]')).toBeVisible();

        await page.locator('button[data-nav-toggle]').click();
        await page.reload();
        await expect(page.locator('html')).not.toHaveClass(/\bnav-collapsed\b/);
        await expect(administration).toBeVisible();

        // Zustand für die anderen Szenarien zurücksetzen.
        await page.locator('[data-nav-fold="administration"]').click();
        await expect(administration).toBeHidden();
    });

    test('Handy: Menüknopf öffnet die Leiste, ein Link navigiert', async ({ page }) => {
        test.skip(!MOBILE, 'Nur im mobilen Lauf.');

        await page.goto('/dashboard');
        await expect(page.locator('#app-sidebar')).toBeHidden();
        await openMainNavigation(page);

        // Schließen per ✕ gibt den Fokus an den Menüknopf zurück.
        await page.locator('#app-sidebar [data-bs-dismiss="offcanvas"]').click();
        await expect(page.locator('#app-sidebar')).toBeHidden();
        await expect(page.locator('button[data-nav-toggle]')).toBeFocused();

        await openMainNavigation(page);
        await page.locator('#app-sidebar a[href="/events"]').click();
        await expect(page).toHaveURL(/\/events$/);
        await expect(page.locator('#app-sidebar')).toBeHidden();
    });

    test('Handy: Vergrößern bei offener Leiste hinterlässt keinen Backdrop', async ({ page }) => {
        test.skip(!MOBILE, 'Nur im mobilen Lauf.');

        await page.goto('/dashboard');
        await openMainNavigation(page);
        await page.setViewportSize({ width: 1280, height: 900 });

        await expect(page.locator('.offcanvas-backdrop')).toHaveCount(0);
        await expect(page.locator('#app-sidebar a[href="/events"]')).toBeVisible();
    });
});
