// Bausteine für das Sponsoring-Modul.
//
// Verifizierte Selektoren:
//  - templates/sponsoring/sponsors/index.twig
//      Anlegen-Dialog #createSponsorModal, Formular POST /sponsoring/sponsors
//      Name #sponsors-index-name, Kategorie als Tom-Select (select[data-sponsor-category],
//      sponsor-category-select.js), Absenden button[type="submit"] ohne name,
//      Dublettenhinweis .sponsor-duplicate-warning mit button[name="confirm_duplicate"]
//      Liste #sponsorsTable, Name-Links in td[data-label="Name"] a
//  - templates/sponsoring/sponsors/detail.twig
//      Vorgang #newSponsorshipModal: package_id [data-sponsorship-package], status,
//      amount, project_id, assigned_user_id, benefits [data-sponsorship-benefits],
//      deliverables [data-sponsorship-deliverables], [data-sponsorship-logo-print|online],
//      Absenden button[type="submit"] "Anlegen"
//      Logo-Ablegefläche: Dateifeld #sponsor-logos (macros/upload.twig), Liste .sponsor-logos
//      Anhang-Ablegefläche: Dateifeld #sponsor-attachments
//      Abhaken: form mit input[name="received"|"delivered"], button.benefit-toggle
//  - templates/sponsoring/packages/index.twig
//      Dialog #newPackageModal, Formular POST /sponsoring/packages, Felder name, min_amount,
//      benefits, deliverables, needs_logo_print, needs_logo_online
//  - templates/sponsoring/dashboard.twig
//      "Logo fehlt": section[aria-labelledby="sponsoring-logos-title"] .work-item
//      Fristen: details.logo-deadline-settings, je Projekt
//      form[action="/sponsoring/projects/{id}/logo-deadlines"] mit logo_deadline_print/online
//      Offene Leistungen des Sponsors: section[aria-labelledby="sponsoring-deliverables-title"]
//      Ohne Zuständigkeit: section[aria-labelledby="sponsoring-unassigned-title"] .work-item
//      mit select[name="assigned_user_id"], Absenden button[type="submit"] "Zuweisen"
//  - public/js/table-plugins/sponsors-assignee-plugin.js
//      Filter select[aria-label="Zuständigkeit filtern"]; am Telefon hinter
//      [data-table-filter-toggle] (partials/table_toolbar.twig)

/** Sponsoring ist über FEATURE_SPONSORING gegatet - ohne das Modul liefert /sponsoring 404. */
export async function sponsoringModuleEnabled(page) {
    return (await page.request.get('/sponsoring')).status() !== 404;
}

export async function createPackage(page, pkg) {
    await page.goto('/sponsoring/packages');
    await page.click('[data-bs-target="#newPackageModal"]');
    const modal = page.locator('#newPackageModal');
    await modal.waitFor({ state: 'visible' });

    await modal.locator('input[name="name"]').fill(pkg.name);
    await modal.locator('input[name="min_amount"]').fill(pkg.minAmount);
    await modal.locator('textarea[name="benefits"]').fill(pkg.benefits.join('\n'));
    await modal.locator('textarea[name="deliverables"]').fill(pkg.deliverables.join('\n'));
    await modal.locator('input[name="needs_logo_print"]').setChecked(pkg.logoPrint);
    await modal.locator('input[name="needs_logo_online"]').setChecked(pkg.logoOnline);

    await modal.locator('button[type="submit"]').click();
    await page.waitForURL('**/sponsoring/packages');
}

/** Öffnet den Anlegen-Dialog, trägt Name und Kategorie ein und sendet ab. */
export async function submitNewSponsor(page, { name, category = '' }) {
    await page.goto('/sponsoring/sponsors');
    await page.click('[data-bs-target="#createSponsorModal"]');
    const modal = page.locator('#createSponsorModal');
    await modal.waitFor({ state: 'visible' });

    await modal.locator('#sponsors-index-name').fill(name);
    if (category !== '') {
        await chooseCategory(modal, category);
    }

    await modal.locator('button[type="submit"]:not([name])').click();
    await page.waitForURL('**/sponsoring/sponsors');
}

/** Wählt eine vorhandene Kategorie oder legt sie über "… neu anlegen" an. */
export async function chooseCategory(modal, category) {
    const page = modal.page();
    await modal.locator('.ts-control').first().click();
    await page.keyboard.type(category);
    const existing = modal.locator('.ts-dropdown .option', { hasText: category }).first();
    const create = modal.locator('.ts-dropdown .create').first();
    await existing.or(create).first().waitFor({ state: 'visible', timeout: 5000 });
    if (await existing.isVisible()) {
        await existing.click();
    } else {
        await create.click();
    }
}

/** Legt einen Sponsor an und öffnet seine Seite. */
export async function createSponsor(page, sponsor) {
    await submitNewSponsor(page, sponsor);
    await openSponsor(page, sponsor.name);
}

export async function openSponsor(page, name) {
    await page.goto('/sponsoring/sponsors');
    await page.locator('#sponsorsTable td[data-label="Name"] a', { hasText: name }).first().click();
    await page.locator('.sponsor-profile').waitFor({ state: 'visible' });
}

/**
 * Legt auf der geöffneten Sponsorseite einen Vorgang an. Gibt zurück, was der
 * Dialog nach der Paketwahl vorgeschlagen hatte, damit der Test es prüfen kann.
 */
export async function createSponsorship(page, { packageName = '', status = 'accepted', projectName = '', amount, assigneeLastName = '' }) {
    await page.locator('.page-actions [data-bs-target="#newSponsorshipModal"]').click();
    const modal = page.locator('#newSponsorshipModal');
    await modal.waitFor({ state: 'visible' });

    if (packageName !== '') {
        const packageSelect = modal.locator('[data-sponsorship-package]');
        const packageValue = await packageSelect.locator('option', { hasText: packageName }).first().getAttribute('value');
        await packageSelect.selectOption(packageValue);
    }

    const suggested = {
        benefits: await modal.locator('[data-sponsorship-benefits]').inputValue(),
        deliverables: await modal.locator('[data-sponsorship-deliverables]').inputValue(),
        logoPrint: await modal.locator('[data-sponsorship-logo-print]').isChecked(),
        logoOnline: await modal.locator('[data-sponsorship-logo-online]').isChecked(),
    };

    await modal.locator('select[name="status"]').selectOption(status);
    await modal.locator('input[name="amount"]').fill(amount);
    if (projectName !== '') {
        const projectSelect = modal.locator('select[name="project_id"]');
        const projectValue = await projectSelect.locator('option', { hasText: projectName }).first().getAttribute('value');
        await projectSelect.selectOption(projectValue);
    }
    if (assigneeLastName !== '') {
        const assigneeSelect = modal.locator('select[name="assigned_user_id"]');
        const assigneeValue = await assigneeSelect.locator('option', { hasText: assigneeLastName }).first().getAttribute('value');
        await assigneeSelect.selectOption(assigneeValue);
    }

    await modal.locator('button[type="submit"]').click();
    await page.waitForURL(/\/sponsoring\/sponsors\/\d+/);
    await page.locator('.sponsor-profile').waitFor({ state: 'visible' });

    return suggested;
}

/**
 * Lädt Dateien über eine Ablegefläche (macros/upload.twig). Die Fläche schickt je Datei
 * eine Anfrage und lädt die Seite danach neu.
 */
export async function uploadThroughDropzone(page, inputId, files) {
    const responses = files.map(() => page.waitForResponse(
        (response) => response.request().method() === 'POST' && response.url().includes('/sponsoring/sponsors/'),
        { timeout: 20000 }
    ));
    await page.locator(`#${inputId}`).setInputFiles(files);
    const results = await Promise.all(responses);
    await page.waitForLoadState('load');
    await page.locator('.sponsor-profile').waitFor({ state: 'visible' });

    return results.map((response) => response.status());
}

/** Zeilen der Liste "Logo fehlt" auf der Übersicht, je als Text. */
export async function missingLogoRows(page) {
    await page.goto('/sponsoring');
    return page.locator('section[aria-labelledby="sponsoring-logos-title"] .work-item').allInnerTexts();
}

/** Setzt im Dashboard die Logo-Fristen eines Projekts (nur mit "Sponsoring verwalten"). */
export async function setLogoDeadlines(page, projectName, { print = '', online = '' }) {
    await page.goto('/sponsoring');
    const details = page.locator('details.logo-deadline-settings');
    await details.locator('summary').click();
    const form = details.locator('form', { hasText: projectName }).first();
    await form.locator('input[name="logo_deadline_print"]').fill(print);
    await form.locator('input[name="logo_deadline_online"]').fill(online);
    await form.locator('button[type="submit"]').click();
    await page.waitForURL('**/sponsoring');
}

/** Zeile eines Sponsors im Bereich "Ohne Zuständigkeit" der Übersicht. */
export function unassignedRow(page, sponsorName) {
    return page.locator('section[aria-labelledby="sponsoring-unassigned-title"] .work-item', { hasText: sponsorName });
}

/** Trägt auf der Übersicht direkt in der Zeile die zuständige Person ein. */
export async function assignFromDashboard(page, sponsorName, personLastName) {
    await page.goto('/sponsoring');
    const row = unassignedRow(page, sponsorName);
    const select = row.locator('select[name="assigned_user_id"]');
    const value = await select.locator('option', { hasText: personLastName }).first().getAttribute('value');
    await select.selectOption(value);
    await row.locator('button[type="submit"]').click();
}

/** Setzt in der Sponsorenliste den Filter "Zuständig"; `text` ist Teil der Beschriftung. */
export async function filterSponsorsByAssignee(page, text) {
    await page.goto('/sponsoring/sponsors');
    const toggle = page.locator('[data-table-filter-toggle]');
    if (await toggle.isVisible()) {
        await toggle.click();
    }
    const select = page.locator('select[aria-label="Zuständigkeit filtern"]');
    const value = await select.locator('option', { hasText: text }).first().getAttribute('value');
    await select.selectOption(value);
}

/** Hakt auf der Sponsorseite eine Leistung ab (Gegenleistung oder Leistung des Sponsors). */
export async function tickLine(page, description) {
    await page.locator('button.benefit-toggle', { hasText: description }).first().click();
    await page.waitForURL(/\/sponsoring\/sponsors\/\d+/);
    await page.locator('.sponsor-profile').waitFor({ state: 'visible' });
}
