import { expect } from '@playwright/test';

// Verifizierte Selektoren aus templates/events/index.twig und templates/attendance/show.twig:
//  - Termin anlegen: Modal #addEventModal, Formular POST /events mit den Feldern title,
//    starts_at (Datum), start_time / end_time (Uhrzeit), event_type_id (Select mit den
//    geseedeten Typen Probe/Auftritt/Sondertermin) und der Checkbox attendance_required
//    (standardmäßig aktiv). Absenden über den Button "Speichern".
//  - Ein neuer Termin startet mit einer Zielgruppen-Zeile "Alle Mitglieder". Optional lassen
//    sich stattdessen Einzelpersonen wählen (event.audienceUsers, siehe pickAudienceUsers).
//  - Die Termin-ID wird über die Auswahlliste auf /attendance ermittelt, nicht über die
//    Terminliste: dort trägt der Link auf /events/{id} den Text "Bemerkungen (x/y)".
//  - Anwesenheit: /attendance/{id}, je Person eine Radiogruppe name="attendance[{userId}]".
//    Die Radios selbst sind visuell versteckt (.btn-check), geklickt wird das zugehörige Label.

export async function createEvent(page, event) {
    await page.goto('/events');
    await page.click('[data-bs-target="#addEventModal"]');
    const modal = page.locator('#addEventModal');
    await modal.waitFor({ state: 'visible' });

    await modal.locator('input[name="title"]').fill(event.title);
    await modal.locator('input[name="starts_at"]').fill(event.date);
    await modal.locator('input[name="start_time"]').fill(event.startTime);
    await modal.locator('input[name="end_time"]').fill(event.endTime);
    await modal.locator('select[name="event_type_id"]').selectOption({ label: event.type });

    if (event.audienceUsers?.length) {
        await pickAudienceUsers(modal, event.audienceUsers);
    }

    await modal.locator('button[type="submit"]', { hasText: 'Speichern' }).click();
    await page.waitForURL('**/events**');
    await expect(page.locator('#eventsTable, table').filter({ hasText: event.title }).first()).toBeVisible();
}

/**
 * Wählt Einzelpersonen als Zielgruppe des Termins.
 *
 * Selektoren aus templates/partials/audience/filter_row.twig: Die erste Zeile (Index 0) ist
 * zugeklappt und hat "Alle Mitglieder" angehakt. Aufklappen über [data-audience-toggle],
 * Häkchen #audience-audience-0-all lösen, dann im Feld <select id="audience-audience-0-user">
 * suchen, das Tom Select (public/js/tom-select-init.js) ersetzt: Suchfeld
 * "audience-audience-0-user-ts-control", Trefferliste "audience-audience-0-user-ts-dropdown".
 *
 * @param {string[]} labels Teilstrings der angezeigten Namen (z. B. der Nachname)
 */
async function pickAudienceUsers(modal, labels) {
    const row = modal.locator('[data-audience-row]').first();
    await row.locator('[data-audience-toggle]').click();
    await row.locator('#audience-audience-0-all').uncheck();
    const search = modal.locator('#audience-audience-0-user-ts-control');
    for (const label of labels) {
        await search.click();
        await search.fill('');
        await search.pressSequentially(label);
        await modal.locator('#audience-audience-0-user-ts-dropdown .option', { hasText: label }).first().click();
        await expect(modal.locator('.ts-control .item', { hasText: label })).toBeVisible();
    }
    // Liste über einen Klick auf den Titel schließen - Escape würde das ganze Modal schließen.
    await modal.locator('.modal-title').first().click();
}

/**
 * Setzt den Anwesenheitsstatus für einzelne Personen und speichert die Liste.
 *
 * Der Termin wird über die Auswahlliste auf /attendance gefunden: Dort steht der Titel im
 * Optionstext. In der Terminliste selbst trägt der Link auf /events/{id} den Text
 * "Bemerkungen (x/y)" statt des Titels und taugt deshalb nicht zum Nachschlagen.
 *
 * @param {string} eventTitle
 * @param {Array<{name: string, status: 'present'|'excused'|'unexcused'}>} entries
 *        `name` ist ein Teilstring des angezeigten Personennamens (z. B. der Nachname).
 */
export async function markAttendance(page, eventTitle, entries) {
    await page.goto('/attendance');
    const selector = page.locator('select[name="event_id"].attendance-selector');
    await selector.waitFor({ state: 'visible' });

    const option = selector.locator('option', { hasText: eventTitle }).first();
    await option.waitFor({ state: 'attached' });
    const eventId = await option.getAttribute('value');

    // Die Auswahl schickt das GET-Formular per JS ab (public/js/common.js).
    await selector.selectOption(eventId);
    await page.waitForURL(`**/attendance/${eventId}`);

    const form = page.locator(`form[action="/attendance/${eventId}"]`);
    await form.waitFor({ state: 'visible' });

    for (const entry of entries) {
        const row = form.locator('li.list-group-item').filter({ hasText: entry.name }).first();
        await row.waitFor({ state: 'visible' });

        // Das Radio ist visuell versteckt; über die id des Inputs das zugehörige Label finden.
        const radio = row.locator(`input[type="radio"][value="${entry.status}"]`);
        const radioId = await radio.getAttribute('id');
        await row.locator(`label[for="${radioId}"]`).click();
        await expect_checked(radio);
    }

    await form.locator('button[type="submit"]').click();
    await page.waitForURL(`**/attendance/${eventId}`);
}

async function expect_checked(radio) {
    // Kleine Eigenprüfung: Der Klick auf das Label muss die Radiogruppe wirklich umgestellt
    // haben - sonst würde die Anwesenheit still nicht gesetzt.
    const checked = await radio.isChecked();
    if (!checked) {
        throw new Error('Anwesenheitsstatus wurde durch den Klick auf das Label nicht gesetzt.');
    }
}
