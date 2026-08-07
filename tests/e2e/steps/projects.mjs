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
