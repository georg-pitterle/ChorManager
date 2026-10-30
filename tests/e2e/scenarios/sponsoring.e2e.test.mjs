import { test, expect } from '@playwright/test';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import {
    ASSIGNEE_FLOW,
    CATEGORY_SPONSOR,
    DEADLINE_FLOW,
    LOGO_FLOW,
    RESPONSIBILITY_FLOW,
    TICK_FLOW,
    displayDate,
    isoDate,
} from '../data/sponsoring.mjs';
import {
    assignFromDashboard,
    createPackage,
    createSponsor,
    createSponsorship,
    filterSponsorsByAssignee,
    missingLogoRows,
    openSponsor,
    setLogoDeadlines,
    sponsoringModuleEnabled,
    submitNewSponsor,
    tickLine,
    unassignedRow,
    uploadThroughDropzone,
} from '../steps/sponsoring.mjs';
import { ADMIN } from '../data/fixtures.mjs';
import { createProject } from '../steps/projects.mjs';
import { createMember } from '../steps/members.mjs';
import { createRole } from '../steps/finances.mjs';
import { login } from '../steps/auth.mjs';
import { setMemberPassword } from '../steps/authz.mjs';
import { newBrowserContext } from '../steps/browser.mjs';

// Sponsoring entlang dessen, was die bisherige Excel-Liste leisten musste: Sponsoren
// nach Branche ordnen, keine Firma doppelt anlegen, und vor dem Druck wissen, von wem
// das Logo bis wann noch fehlt.
//
// Nacheinander statt parallel, wie im Finanz-Szenario: Alle Tests teilen über den
// storageState dieselbe PHP-Session und damit die einmaligen Meldungen und den
// gemerkten Zustand des Anlegen-Dialogs.
test.describe.configure({ mode: 'default' });

// Eigene Sitzung statt des geteilten Admin-storageState: Dieses Szenario legt viel an
// und lädt viele Seiten. In der geteilten Sitzung griff es dabei die einmaligen
// Erfolgsmeldungen parallel laufender Szenarien ab - der Finanz-Import fand seine
// Meldung "3 Buchungen importiert" nicht mehr. Jeder Test meldet sich deshalb frisch an.
test.use({ storageState: { cookies: [], origins: [] } });

test.beforeEach(async ({ page }) => {
    await login(page, ADMIN);
    test.skip(!(await sponsoringModuleEnabled(page)), 'FEATURE_SPONSORING ist in dieser Umgebung aus.');
});

/** Kleine echte Dateien für die Logo-Ablegefläche; der Server erkennt den Typ am Inhalt. */
function logoFiles() {
    const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'sponsor-logos-'));
    const png = path.join(dir, 'logo-plakat.png');
    fs.writeFileSync(png, Buffer.from(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
        'base64'
    ));
    // Leeres ZIP-Archiv (End-of-central-directory), wie es Grafikbüros als Sammelpaket schicken.
    const zip = path.join(dir, 'logos-grafikbuero.zip');
    fs.writeFileSync(zip, Buffer.concat([Buffer.from('504b0506', 'hex'), Buffer.alloc(18)]));

    return { png, zip };
}

test('Kategorie direkt anlegen, ähnlich benannten Sponsor erkennen', async ({ page }) => {
    await submitNewSponsor(page, { name: CATEGORY_SPONSOR.name, category: CATEGORY_SPONSOR.newCategory });

    // Die neue Kategorie steht in der Unterzeile und im Filter der Liste.
    const row = page.locator('#sponsorsTable tbody tr', { hasText: CATEGORY_SPONSOR.name }).first();
    await expect(row).toContainText(CATEGORY_SPONSOR.newCategory);
    // Geprüft am Filterfeld, das das Tabellen-Plugin baut - das Attribut selbst ist
    // JSON und schreibt Umlaute als \u-Folge.
    await expect(
        page.locator('select[aria-label="Kategorie filtern"] option', { hasText: CATEGORY_SPONSOR.newCategory })
    ).toHaveCount(1);

    // Andere Schreibweise und Rechtsform: Hinweis statt neuem Sponsor.
    await submitNewSponsor(page, { name: CATEGORY_SPONSOR.similarName });
    const modal = page.locator('#createSponsorModal');
    const warning = modal.locator('.sponsor-duplicate-warning');
    await expect(warning).toBeVisible();
    await expect(warning.locator('a', { hasText: CATEGORY_SPONSOR.name })).toBeVisible();
    await expect(modal.locator('#sponsors-index-name')).toHaveValue(CATEGORY_SPONSOR.similarName);
    await expect(page.locator('#sponsorsTable td[data-label="Name"] a', { hasText: CATEGORY_SPONSOR.similarName })).toHaveCount(0);

    // Bewusst bestätigt - etwa eine zweite Filiale - wird er doch angelegt.
    await warning.locator('button[name="confirm_duplicate"]').click();
    await page.waitForURL('**/sponsoring/sponsors');
    await expect(page.locator('#sponsorsTable td[data-label="Name"] a', { hasText: CATEGORY_SPONSOR.similarName })).toHaveCount(1);
});

test('Logo fehlt: Frist aus dem Projekt, erledigt mit hochgeladenen Logo-Dateien', async ({ page }) => {
    const printDeadline = isoDate(7);
    await createProject(page, { ...LOGO_FLOW.project, logoDeadlinePrint: printDeadline });
    await createPackage(page, LOGO_FLOW.package);
    await createSponsor(page, LOGO_FLOW.sponsor);

    // Das Paket schlägt Gegenleistungen, Leistungen des Sponsors und den Logo-Bedarf vor.
    const suggested = await createSponsorship(page, {
        packageName: LOGO_FLOW.package.name,
        projectName: LOGO_FLOW.project.name,
        amount: LOGO_FLOW.amount,
    });
    expect(suggested.benefits).toBe(LOGO_FLOW.package.benefits.join('\n'));
    expect(suggested.deliverables).toBe(LOGO_FLOW.package.deliverables.join('\n'));
    expect(suggested.logoPrint).toBe(true);
    expect(suggested.logoOnline).toBe(false);

    // Auf der Übersicht: Druck-Frist des Projekts, in einer Woche -> "bald".
    let rows = await missingLogoRows(page);
    const row = rows.find((text) => text.includes(LOGO_FLOW.sponsor.name));
    expect(row, 'Sponsor fehlt in "Logo fehlt"').toBeTruthy();
    expect(row).toContain('Druck');
    expect(row).toContain(displayDate(printDeadline));
    expect(row).toContain('bald');
    await expect(
        page.locator('section[aria-labelledby="sponsoring-deliverables-title"]')
    ).toContainText(LOGO_FLOW.package.deliverables[0]);

    // Logo-Dateien in zwei Formaten über die Ablegefläche.
    await openSponsor(page, LOGO_FLOW.sponsor.name);
    const files = logoFiles();
    const statuses = await uploadThroughDropzone(page, 'sponsor-logos', [files.png, files.zip]);
    expect(statuses).toEqual([200, 200]);

    const logos = page.locator('.sponsor-logos');
    await expect(logos).toContainText('logo-plakat.png');
    await expect(logos).toContainText('logos-grafikbuero.zip');
    // Vorschau nur für das Bild; das ZIP gibt es nur zum Herunterladen.
    await expect(logos.locator('img.sponsor-logo-preview')).toHaveCount(1);

    rows = await missingLogoRows(page);
    expect(rows.some((text) => text.includes(LOGO_FLOW.sponsor.name))).toBe(false);

    // Leistung des Sponsors als erhalten abhaken -> verschwindet aus den offenen.
    await openSponsor(page, LOGO_FLOW.sponsor.name);
    await tickLine(page, LOGO_FLOW.package.deliverables[0]);
    await expect(page.locator('.agreement-card').first()).toContainText('1 von 1 erhalten');
    await page.goto('/sponsoring');
    await expect(
        page.locator('section[aria-labelledby="sponsoring-deliverables-title"]')
    ).not.toContainText(LOGO_FLOW.sponsor.name);
});

test('Logo-Fristen im Dashboard nachtragen', async ({ page }) => {
    await createProject(page, DEADLINE_FLOW.project);
    await createPackage(page, DEADLINE_FLOW.package);
    await createSponsor(page, DEADLINE_FLOW.sponsor);
    await createSponsorship(page, {
        packageName: DEADLINE_FLOW.package.name,
        projectName: DEADLINE_FLOW.project.name,
        amount: DEADLINE_FLOW.amount,
    });

    // Ohne eingetragene Frist steht der Sponsor unter "ohne Frist".
    let row = (await missingLogoRows(page)).find((text) => text.includes(DEADLINE_FLOW.sponsor.name));
    expect(row).toContain('ohne Frist');
    expect(row).toContain('online');

    // Online-Frist in der Vergangenheit -> "überfällig".
    const onlineDeadline = isoDate(-3);
    await setLogoDeadlines(page, DEADLINE_FLOW.project.name, { online: onlineDeadline });
    row = (await missingLogoRows(page)).find((text) => text.includes(DEADLINE_FLOW.sponsor.name));
    expect(row).toContain(displayDate(onlineDeadline));
    expect(row).toContain('überfällig');
});

test('Zuständige einer Zusage pflegen die Logos, sehen aber keine anderen Anhänge', async ({ page, browser }) => {
    await createRole(page, { name: ASSIGNEE_FLOW.role, permissions: ['can_create_own_sponsorships'] });
    await createMember(page, ASSIGNEE_FLOW.member);
    await setMemberPassword(ASSIGNEE_FLOW.member.email, ASSIGNEE_FLOW.password);

    await createSponsor(page, ASSIGNEE_FLOW.sponsor);
    // Ohne Paket: Der Test legt seine Daten selbst an und braucht keins.
    await createSponsorship(page, {
        amount: ASSIGNEE_FLOW.amount,
        assigneeLastName: ASSIGNEE_FLOW.member.lastName,
    });
    // Ein gewöhnlicher Anhang am Sponsor, den die zuständige Person nicht sehen darf.
    const mediaDir = fs.mkdtempSync(path.join(os.tmpdir(), 'sponsor-media-'));
    const media = path.join(mediaDir, ASSIGNEE_FLOW.otherAttachment);
    fs.writeFileSync(media, '%PDF-1.4\n%%EOF\n');
    expect(await uploadThroughDropzone(page, 'sponsor-attachments', [media])).toEqual([200]);
    const sponsorUrl = new URL(page.url()).pathname;

    const context = await newBrowserContext(browser);
    await context.clearCookies();
    const assigneePage = await context.newPage();
    try {
        await login(assigneePage, { email: ASSIGNEE_FLOW.member.email, password: ASSIGNEE_FLOW.password });
        await assigneePage.goto(sponsorUrl);
        await assigneePage.locator('.sponsor-profile').waitFor({ state: 'visible' });

        await expect(assigneePage.locator('#sponsor-logos')).toHaveCount(1);
        await expect(assigneePage.locator('body')).not.toContainText(ASSIGNEE_FLOW.otherAttachment);
        await expect(assigneePage.locator('#sponsor-attachments')).toHaveCount(0);

        const statuses = await uploadThroughDropzone(assigneePage, 'sponsor-logos', [logoFiles().png]);
        expect(statuses).toEqual([200]);
        await expect(assigneePage.locator('.sponsor-logos')).toContainText('logo-plakat.png');
    } finally {
        await context.close();
    }
});

test('Ohne Zuständigkeit: Lücke finden, direkt zuweisen, in der Liste wiederfinden', async ({ page }) => {
    const { member, outsider, sponsor } = RESPONSIBILITY_FLOW;
    await createRole(page, { name: RESPONSIBILITY_FLOW.role, permissions: ['can_create_own_sponsorships'] });
    await createMember(page, member);
    await createMember(page, outsider);
    await createSponsor(page, sponsor);
    await createSponsorship(page, { amount: RESPONSIBILITY_FLOW.amount });

    const listRow = page.locator('#sponsorsTable tbody tr', { hasText: sponsor.name });

    // Die Lücke steht in der Liste, und der Filter "Niemand" findet sie.
    await filterSponsorsByAssignee(page, 'Niemand');
    await expect(listRow).toBeVisible();
    await expect(listRow.locator('td[data-label="Zuständig"]')).toContainText('niemand');

    // Auf der Übersicht stehen nur Personen zur Wahl, die den Vorgang auch sähen.
    await page.goto('/sponsoring');
    const options = unassignedRow(page, sponsor.name).locator('select[name="assigned_user_id"] option');
    await expect(options.filter({ hasText: member.lastName })).toHaveCount(1);
    await expect(options.filter({ hasText: outsider.lastName })).toHaveCount(0);

    await assignFromDashboard(page, sponsor.name, member.lastName);
    await expect(page.locator('.alert-success')).toContainText(`für ${sponsor.name} zuständig`);
    await expect(unassignedRow(page, sponsor.name)).toHaveCount(0);

    // Danach zählt der Sponsor nicht mehr als Lücke, sondern steht bei der Person.
    await filterSponsorsByAssignee(page, 'Niemand');
    await expect(listRow).toBeHidden();
    await filterSponsorsByAssignee(page, member.lastName);
    await expect(listRow).toBeVisible();
    await expect(listRow.locator('td[data-label="Zuständig"]')).toContainText(member.lastName);
});

test('Gegenleistungen hakt das Team ab, das Material des Sponsors auch die zuständige Person', async ({ page, browser }) => {
    const { member, sponsor } = TICK_FLOW;
    const [benefit] = TICK_FLOW.package.benefits;
    const [deliverable] = TICK_FLOW.package.deliverables;

    await createRole(page, { name: TICK_FLOW.role, permissions: ['can_create_own_sponsorships'] });
    await createMember(page, member);
    await setMemberPassword(member.email, TICK_FLOW.password);
    await createPackage(page, TICK_FLOW.package);
    await createSponsor(page, sponsor);
    await createSponsorship(page, {
        packageName: TICK_FLOW.package.name,
        amount: TICK_FLOW.amount,
        assigneeLastName: member.lastName,
    });

    const sponsorUrl = new URL(page.url()).pathname;
    const benefitAction = await page.locator('form.benefit-form', { hasText: benefit }).getAttribute('action');

    const context = await newBrowserContext(browser);
    await context.clearCookies();
    const assigneePage = await context.newPage();
    try {
        await login(assigneePage, { email: member.email, password: TICK_FLOW.password });
        await assigneePage.goto(sponsorUrl);
        await assigneePage.locator('.sponsor-profile').waitFor({ state: 'visible' });

        // Die Gegenleistung ist zu sehen, aber nicht abzuhaken.
        await expect(assigneePage.locator('.benefit-static', { hasText: benefit })).toBeVisible();
        await expect(assigneePage.locator('button.benefit-toggle', { hasText: benefit })).toHaveCount(0);

        // Auch nicht am Knopf vorbei. Das Token gehört dazu: Ohne wäre die Antwort
        // ebenfalls 403, aber von der CSRF-Prüfung statt von der Rechteprüfung.
        const token = await assigneePage.getAttribute('meta[name="csrf-token"]', 'content');
        const refused = await assigneePage.request.post(benefitAction, { form: { _csrf: token, delivered: '1' } });
        expect(refused.status()).toBe(403);
        expect(await refused.text()).not.toContain('CSRF');

        await tickLine(assigneePage, deliverable);
        await expect(assigneePage.locator('.agreement-card').first()).toContainText('1 von 1 erhalten');
    } finally {
        await context.close();
    }

    await page.goto(sponsorUrl);
    await expect(page.locator('.agreement-card').first()).toContainText('0 von 1 erbracht');
    await tickLine(page, benefit);
    await expect(page.locator('.agreement-card').first()).toContainText('1 von 1 erbracht');
});
