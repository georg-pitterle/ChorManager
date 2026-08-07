// Verifizierte Selektoren aus templates/users/manage.twig:
//  - Anlegen-Modal: #addUserModal, Formular POST /users
//  - Felder: first_name, last_name, email (required)
//  - Rolle: checkbox name="roles[]" im .form-check.form-check-inline-Wrapper mit Rollenname
//    (UserController::create verlangt server-seitig mind. eine Rolle, sonst schlaegt das
//    Speichern still fehl und redirected trotzdem nach /users ohne neuen Datensatz)
//  - Stimmgruppe: checkbox name="voice_groups[]" value={group.id}, im .form-check-Wrapper
//    (nur Checkbox + Label; Gruppenname steht im .form-check-label-Text)
//  - Untergruppe: select name="sub_voices[{group.id}]", Optionen mit Label = Untergruppenname
//    (wird per JS sichtbar, sobald die zugehoerige Checkbox angehakt wird)
//  - Absenden: button[name="submit_action"][value="save"]

const DEFAULT_ROLE = 'Mitglied';

export async function createMember(page, member) {
    await page.goto('/users');
    await page.click('[data-bs-target="#addUserModal"]');
    const modal = page.locator('#addUserModal');
    await modal.waitFor({ state: 'visible' });

    await modal.locator('input[name="first_name"]').fill(member.firstName);
    await modal.locator('input[name="last_name"]').fill(member.lastName);
    await modal.locator('input[name="email"]').fill(member.email);

    // Rolle ist Pflichtfeld (server-seitig validiert) - Standardrolle "Mitglied" ankreuzen.
    const roleCheckbox = modal
        .locator('.form-check', { hasText: member.role ?? DEFAULT_ROLE })
        .locator('input[name="roles[]"]')
        .first();
    await roleCheckbox.check();

    // Gruppen-Checkbox anhand des Gruppennamens finden und deren value (=group.id) lesen.
    // Der Gruppenname steht ausschliesslich im .form-check-label-Text der zugehoerigen
    // .form-check-Box (Checkbox + Label), daher liefert hasText hier keine Substring-Treffer
    // ueber Gruppengrenzen hinweg (Sopran/Alt/Tenor/Bass sind eindeutig).
    const groupCheckbox = modal
        .locator('.form-check', { hasText: member.group })
        .locator('input[name="voice_groups[]"]')
        .first();
    await groupCheckbox.check();
    const groupId = await groupCheckbox.getAttribute('value');

    // Nach dem Anhaken wird der Untergruppen-Select sichtbar (collapse-sv d-none entfernt).
    const subSelect = modal.locator(`select[name="sub_voices[${groupId}]"]`);
    await subSelect.waitFor({ state: 'visible' });
    await subSelect.selectOption({ label: member.sub });

    await modal.locator('button[name="submit_action"][value="save"]').click();
    await page.waitForURL('**/users');
}
