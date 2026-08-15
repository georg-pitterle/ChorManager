// Verifizierte Selektoren aus templates/projects/index.twig:
//  - Anlegen-Modal: #addProjectModal, Formular POST /projects
//  - Felder: name (required), description, start_date, end_date
//  - Absenden: button[type="submit"] "Speichern"
export async function createProject(page, project) {
    await page.goto('/projects');
    await page.click('[data-bs-target="#addProjectModal"]');
    const modal = page.locator('#addProjectModal');
    await modal.waitFor({ state: 'visible' });

    await modal.locator('input[name="name"]').fill(project.name);
    await modal.locator('textarea[name="description"]').fill(project.description);
    await modal.locator('input[name="start_date"]').fill(project.startDate);
    await modal.locator('input[name="end_date"]').fill(project.endDate);

    await modal.locator('button[type="submit"]').click();
    await page.waitForURL('**/projects');
}

// Verifizierte Selektoren aus templates/projects/member_projects.twig und members.twig:
//  - Projektliste: /projects/members, je Projekt ein Link auf /projects/{id}/members
//  - Zuordnen: Formular POST /projects/{id}/members mit Tom-Select-Feld name="user_id"
//    und Submit-Button "Hinzufügen"
export async function addProjectMember(page, projectName, personLabel) {
    await page.goto('/projects/members');
    await page.locator('a[href^="/projects/"][href$="/members"]', { hasText: projectName }).first().click();
    await page.waitForURL('**/members');

    const control = page.locator('form[action$="/members"] .ts-control').first();
    await control.waitFor({ state: 'visible' });
    await control.click();
    await page.keyboard.type(personLabel);
    const option = page.locator('.ts-dropdown .option', { hasText: personLabel }).first();
    await option.waitFor({ state: 'visible' });
    await option.click();

    await page.locator('form[action$="/members"] button[type="submit"]').first().click();
    await page.waitForURL('**/members');
}
