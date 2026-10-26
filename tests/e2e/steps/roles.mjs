// Verifizierte Selektoren aus templates/roles/index.twig:
//  - Anlegen-Modal: #addRoleModal, Formular POST auf role_create_action (/roles)
//  - Felder: name (required), hierarchy_level (Default 0)
//  - Rechte: Schalter input[type="checkbox"][name="<recht>"], im Anlegen-Modal ohne Präfix
//    (das Bearbeiten-Modal nutzt die ids edit_<recht>, dieselben names)
//  - Absenden: button[type="submit"] "Rolle Speichern"; RoleController::create leitet auf /roles
export async function createRole(page, role) {
    await page.goto('/roles');
    await page.click('[data-bs-toggle="modal"][data-bs-target="#addRoleModal"]');
    const modal = page.locator('#addRoleModal');
    await modal.waitFor({ state: 'visible' });

    await modal.locator('input[name="name"]').fill(role.name);
    for (const permission of role.permissions) {
        await modal.locator(`input[type="checkbox"][name="${permission}"]`).check();
    }

    await modal.locator('button[type="submit"]').click();
    await page.waitForURL('**/roles');
}
