import { test, expect } from '@playwright/test';
import {
    EDITOR_SECOND,
    NEWSLETTER_PASSWORD,
    RESILIENCE_EDITOR,
    RESILIENCE_RECIPIENT,
    NEWSLETTER_SENT_FROM_EDITOR,
    NEWSLETTER_SENT_FROM_LIST,
    NEWSLETTER_FORCE_UNLOCKED,
    NEWSLETTER_SAVED_FIRST,
} from '../data/newsletters.mjs';
import { createMember } from '../steps/members.mjs';
import { login, loseSessionKeepingRememberMe, renewSessionThroughRememberMe } from '../steps/auth.mjs';
import { setMemberPassword, readRolePermissions } from '../steps/authz.mjs';
import {
    isNewsletterModuleEnabled,
    createNewsletterDraft,
    openEditPage,
    sendFromEditPage,
    sendFromList,
    sendOpenNewsletter,
    forceUnlockInModal,
} from '../steps/newsletters.mjs';
import { deliverQueuedMails, mailpitRecipientsForSubject } from '../steps/mail.mjs';
import { newBrowserContext } from '../steps/browser.mjs';
import { AUTH_FILE } from '../playwright.config.mjs';

// Das höchste Hierarchie-Level, wie es Role::MAX_HIERARCHY_LEVEL festlegt. Nur wer es hält,
// darf eine fremde Newsletter-Sperre aufheben (NewsletterController::forceUnlock).
const MAX_HIERARCHY_LEVEL = 100;

const SECOND_EDITOR = {
    ...EDITOR_SECOND,
    firstName: 'Mira',
    lastName: 'Zuschauend',
    email: 'nl.mira.zuschauend@chor.local',
};

async function asUser(browser, email, run, { remember = false } = {}) {
    const context = await newBrowserContext(browser);
    // Ein frischer Kontext erbt den Admin-storageState aus der Config - Cookies leeren, sonst
    // leitet /login sofort nach /dashboard weiter, und wir wären weiter als Admin unterwegs.
    await context.clearCookies();
    const page = await context.newPage();
    try {
        await login(page, { email, password: NEWSLETTER_PASSWORD, remember });
        await run(page);
    } finally {
        await context.close();
    }
}

async function createPeople(page, people) {
    for (const person of people) {
        await createMember(page, person);
        setMemberPassword(person.email, NEWSLETTER_PASSWORD);
    }
}

// Der Entwurf entsteht im Modal der Übersicht. Danach wird die Übersicht verlassen: Das noch
// offene Modal gibt seine Sperre beim Entladen per Beacon frei - bliebe es stehen, könnte diese
// Freigabe eine später gesetzte Sperre wieder aufheben.
async function createDraftFor(page, newsletter) {
    const id = await createNewsletterDraft(page, {
        title: newsletter.title,
        project: null,
        content: newsletter.marker,
        sources: { user: [RESILIENCE_RECIPIENT.lastName] },
    });
    expect(id, 'Der Entwurf muss eine ID bekommen haben').toBeGreaterThan(0);
    await page.goto('/dashboard');

    return id;
}

async function expectDeliveredOnlyToRecipient(request, title) {
    deliverQueuedMails(title);
    expect(
        await mailpitRecipientsForSubject(request, title),
        'Zugestellt werden muss genau an die gewählte Person'
    ).toEqual([RESILIENCE_RECIPIENT.email]);
}

test.describe.configure({ mode: 'serial' });

// Die Personen werden einmal für alle drei Tests angelegt, als Admin aus der gespeicherten
// Sitzung der Ersteinrichtung (global-setup.mjs).
test.beforeAll(async ({ browser }) => {
    test.setTimeout(120_000);
    const context = await newBrowserContext(browser, { storageState: AUTH_FILE });
    const page = await context.newPage();
    try {
        if (await isNewsletterModuleEnabled(page)) {
            await createPeople(page, [RESILIENCE_EDITOR, RESILIENCE_RECIPIENT, SECOND_EDITOR]);
        }
    } finally {
        await context.close();
    }
});

test('Versand von der Bearbeiten-Seite, nachdem die Sitzung still neu aufgebaut wurde', async ({ page, browser }) => {
    test.setTimeout(180_000);
    test.skip(!(await isNewsletterModuleEnabled(page)), 'Newsletter-Modul ist in dieser Umgebung aus.');

    await asUser(browser, RESILIENCE_EDITOR.email, async (editorPage) => {
        const newsletterId = await createDraftFor(editorPage, NEWSLETTER_SENT_FROM_EDITOR);
        await openEditPage(editorPage, newsletterId);

        // Die Seite liegt lange offen, die Sitzung verfällt; beim Zurückkehren entsteht sie
        // per Remember-Me neu, mit neuem Token.
        const tokenBefore = await renewSessionThroughRememberMe(editorPage);
        await expect(
            editorPage.locator('meta[name="csrf-token"]'),
            'Die offene Seite muss den neuen Token übernommen haben'
        ).not.toHaveAttribute('content', tokenBefore);

        // Der Versenden-Knopf schickt das versteckte Formular ab (form.submit(), ohne
        // submit-Ereignis) - sein _csrf-Feld muss also selbst nachgezogen worden sein.
        await expect(editorPage.locator('#send-form input[name="_csrf"]')).not.toHaveValue(tokenBefore);

        await sendFromEditPage(editorPage);
        await expectDeliveredOnlyToRecipient(editorPage.request, NEWSLETTER_SENT_FROM_EDITOR.title);
    }, { remember: true });
});

test('Speichern als erste Anfrage nach dem Verfallen der Sitzung', async ({ page, browser }) => {
    test.setTimeout(180_000);
    test.skip(!(await isNewsletterModuleEnabled(page)), 'Newsletter-Modul ist in dieser Umgebung aus.');

    await asUser(browser, RESILIENCE_EDITOR.email, async (editorPage) => {
        const newsletterId = await createDraftFor(editorPage, NEWSLETTER_SAVED_FIRST);
        await openEditPage(editorPage, newsletterId);

        // Diesmal fragt vorher nichts nach: Der Speichern-POST trifft als erste Anfrage auf die
        // verlorene Sitzung. Der Server baut sie per Remember-Me auf, lehnt den POST mit dem
        // alten Token ab, und die Seite wiederholt ihn einmal mit dem neuen.
        await loseSessionKeepingRememberMe(editorPage);
        await editorPage.locator('#title').fill(NEWSLETTER_SAVED_FIRST.renamedTitle);

        const saved = editorPage.waitForResponse(
            (response) => new URL(response.url()).pathname === `/newsletters/${newsletterId}`
                && response.request().method() === 'POST'
                && response.status() === 200
        );
        await editorPage.locator('#save-draft-btn').click();
        await saved;

        await expect(editorPage.locator('.newsletter-edit-alert')).toContainText('Newsletter gespeichert');
        await expect(editorPage, 'Die Seite darf nicht zur Anmeldung wechseln').toHaveURL(
            new RegExp(`/newsletters/${newsletterId}/edit$`)
        );

        await sendFromEditPage(editorPage);
        await expectDeliveredOnlyToRecipient(editorPage.request, NEWSLETTER_SAVED_FIRST.renamedTitle);
    }, { remember: true });
});

test('Versand aus der Übersicht, die lange offen lag', async ({ page, browser }) => {
    test.setTimeout(180_000);
    test.skip(!(await isNewsletterModuleEnabled(page)), 'Newsletter-Modul ist in dieser Umgebung aus.');

    await asUser(browser, RESILIENCE_EDITOR.email, async (editorPage) => {
        await createDraftFor(editorPage, NEWSLETTER_SENT_FROM_LIST);

        await editorPage.goto('/newsletters?status=draft');
        await editorPage.locator('#newslettersTable').waitFor({ state: 'visible' });
        await renewSessionThroughRememberMe(editorPage);

        await sendFromList(editorPage, NEWSLETTER_SENT_FROM_LIST.title);
        await expectDeliveredOnlyToRecipient(editorPage.request, NEWSLETTER_SENT_FROM_LIST.title);
    }, { remember: true });
});

test('Vergessene Sperre: nur das höchste Rollen-Level hebt sie auf und versendet', async ({ page, browser }) => {
    test.setTimeout(240_000);
    test.skip(!(await isNewsletterModuleEnabled(page)), 'Newsletter-Modul ist in dieser Umgebung aus.');

    // Erwartung aus den echten Rollen in der DB: Die Admin-Rolle der Ersteinrichtung hält das
    // höchste Level, die Redaktionsrolle nicht.
    const roles = readRolePermissions();
    expect(Number(roles.Admin?.hierarchy_level)).toBe(MAX_HIERARCHY_LEVEL);
    expect(Number(roles[SECOND_EDITOR.role]?.hierarchy_level)).toBeLessThan(MAX_HIERARCHY_LEVEL);

    await asUser(browser, RESILIENCE_EDITOR.email, async (holderPage) => {
        const newsletterId = await createDraftFor(holderPage, NEWSLETTER_FORCE_UNLOCKED);

        // Die Redakteurin öffnet den Editor und geht weg, ohne ihn zu schließen.
        await openEditPage(holderPage, newsletterId);
        holderPage.on('dialog', (dialog) => dialog.accept());

        // Wer nur Newsletter verwaltet, sieht die Sperre, aber keinen Weg, sie aufzuheben.
        await asUser(browser, SECOND_EDITOR.email, async (editorPage) => {
            await editorPage.goto('/newsletters?status=draft');
            const row = editorPage.locator('#newslettersTable tbody tr', { hasText: NEWSLETTER_FORCE_UNLOCKED.title });
            await row.locator('[data-newsletter-modal-url*="/edit"]').click();
            await editorPage.locator('#newsletterActionContent #reload-locked-newsletter-btn').waitFor({ state: 'visible' });
            await expect(editorPage.locator('#newsletterActionContent #force-unlock-form')).toHaveCount(0);

            const token = await editorPage.getAttribute('meta[name="csrf-token"]', 'content');
            const refused = await editorPage.request.post(`/newsletters/${newsletterId}/force-unlock`, {
                headers: { 'X-CSRF-Token': token, 'X-Requested-With': 'XMLHttpRequest' },
            });
            expect(refused.status(), 'Unterhalb des höchsten Levels muss der Server ablehnen').toBe(403);
        });

        // Der Admin hebt die Sperre im Dialog der Übersicht auf und landet im Editor.
        await page.goto('/newsletters?status=draft');
        const row = page.locator('#newslettersTable tbody tr', { hasText: NEWSLETTER_FORCE_UNLOCKED.title });
        await row.locator('[data-newsletter-modal-url*="/edit"]').click();
        await forceUnlockInModal(page);

        const lock = await (await page.request.get(`/newsletters/${newsletterId}/check-lock`)).json();
        expect(lock.is_me, 'Nach dem Aufheben muss die Sperre beim Admin liegen').toBe(true);

        await sendOpenNewsletter(page);
        await expectDeliveredOnlyToRecipient(page.request, NEWSLETTER_FORCE_UNLOCKED.title);
    });
});
