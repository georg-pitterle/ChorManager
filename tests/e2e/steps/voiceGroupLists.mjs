// Verifizierte Selektoren aus templates/voice_group_lists/:
//  - index.twig / _new_list_modal.twig: Knopf data-bs-target="#newListModal", Formular POST
//    /voice-group-lists mit title und project_id (Option "Ohne Projekt" = leerer Wert);
//    VoiceGroupListController::create leitet auf /voice-group-lists/{id}
//  - _column_form.twig: <details class="voice-group-list-columns"> (offen nur ohne Spalte),
//    Formular POST /voice-group-lists/{id}/columns mit label und type (checkbox|note)
//  - _cell.twig: Häkchen-Knopf .voice-group-list-tick mit aria-label "<Spalte>: <Name>" und
//    aria-pressed; Notizfeld input[name="note"] mit demselben aria-label

export async function createVoiceGroupList(page, list) {
    await page.goto('/voice-group-lists');
    await page.click('[data-bs-toggle="modal"][data-bs-target="#newListModal"]');
    const modal = page.locator('#newListModal');
    await modal.waitFor({ state: 'visible' });

    await modal.locator('input[name="title"]').fill(list.title);
    await modal.locator('select[name="project_id"]').selectOption({ label: list.project ?? 'Ohne Projekt' });

    await modal.locator('button[type="submit"]').click();
    await page.waitForURL(/\/voice-group-lists\/\d+$/);

    return Number(new URL(page.url()).pathname.split('/').pop());
}

export async function addVoiceGroupListColumn(page, column) {
    const section = page.locator('details.voice-group-list-columns');
    if (!(await section.evaluate((element) => element.open))) {
        await section.locator('summary').first().click();
    }

    const form = section.locator('form[action$="/columns"]');
    await form.locator('input[name="label"]').fill(column.label);
    await form.locator('select[name="type"]').selectOption(column.type);
    await form.locator('button[type="submit"]').click();
    await page.waitForLoadState('load');
}

// Name im aria-label folgt dem eingestellten Namensformat; der Nachname steht in jedem Format.
export function tickButton(page, columnLabel, lastName) {
    return page.locator(`button.voice-group-list-tick[aria-label^="${columnLabel}: "][aria-label*="${lastName}"]`);
}

export function noteInput(page, columnLabel, lastName) {
    return page.locator(`input[name="note"][aria-label^="${columnLabel}: "][aria-label*="${lastName}"]`);
}
