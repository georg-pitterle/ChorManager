import { expect } from '@playwright/test';

// Verifizierte Selektoren aus templates/projects/index.twig und templates/projects/tasks.twig:
//  - Projektzeile: tr[data-sort-project_name="<name in kleinbuchstaben>"]
//  - Link zur Planung: a[href="/projects/{id}/tasks"] (steckt im Aktionen-Dropdown)
//  - Anlegen-Modal: #addTaskModal, Formular POST /projects/{id}/tasks
//  - Felder: title (Pflicht), status (Offen | In Bearbeitung | Abgeschlossen)
//  - Absenden: button[type="submit"] "Aufgabe Speichern"
//  - Kanban: #btn-view-kanban schaltet #kanban-view sichtbar,
//    Spalten sind .kanban-cards-container[data-drop-zone="<Status>"]

/**
 * Liest den Planungs-Pfad eines Projekts aus seiner Zeile in /projects.
 *
 * Der Link liegt in einem geschlossenen Dropdown. Das Attribut lässt sich trotzdem
 * lesen - das Dropdown erst aufzuklappen wäre ein zusätzlicher Klick, der nichts
 * beiträgt und im Kartenmodus anders sitzt.
 */
export async function taskBoardPath(page, projectName) {
    await page.goto('/projects');

    const row = page.locator(`tr[data-sort-project_name="${projectName.toLowerCase()}"]`);
    await row.waitFor({ state: 'attached' });

    const link = row.locator('a[href$="/tasks"]').first();
    await link.waitFor({ state: 'attached' });

    const href = await link.getAttribute('href');
    expect(href).toBeTruthy();

    return href;
}

/**
 * Ist das Aufgaben-Modul in dieser Umgebung an? Ohne FEATURE_TASKS gibt es die
 * Route gar nicht, und das Szenario soll übersprungen werden statt rot zu laufen.
 */
export async function isTaskModuleEnabled(page) {
    await page.goto('/projects');

    return (await page.locator('a[href$="/tasks"]').count()) > 0;
}

export async function createTask(page, boardPath, { title, status = 'Offen' }) {
    await page.goto(boardPath);
    await page.click('[data-bs-target="#addTaskModal"]');

    const modal = page.locator('#addTaskModal');
    await modal.waitFor({ state: 'visible' });

    await modal.locator('input[name="title"]').fill(title);
    await modal.locator('select[name="status"]').selectOption(status);
    await modal.locator('button[type="submit"]').click();

    await page.waitForURL('**/tasks');
}

/**
 * Schaltet auf das Kanban-Board und wartet, bis die Spalten stehen.
 *
 * `#kanban-view` liegt zunächst als `hidden` im DOM; erst der Knopf blendet es ein.
 * Auf die Spalten zu warten statt nur auf den Container ist nötig, weil das Ziehen
 * sonst gegen eine noch nicht vermessene Fläche läuft.
 */
export async function openKanbanBoard(page, boardPath) {
    if (boardPath) {
        await page.goto(boardPath);
    }

    await page.waitForSelector('#kanban-view', { state: 'attached' });
    await expect(page.locator('#btn-view-kanban')).toBeVisible();
    await page.click('#btn-view-kanban');
    await page.waitForSelector('#kanban-view:not([hidden])', { timeout: 10000 });
    await page.waitForSelector('.kanban-cards-container', { state: 'visible' });

    // Auf die sichtbaren Spalten zu warten reicht nicht: public/js/kanban-sortable-init.js
    // hängt SortableJS erst bei DOMContentLoaded an jede Spalte. Wer vorher zieht, bewegt
    // den Zeiger über eine Fläche, die noch niemand beobachtet - die Karte bleibt liegen,
    // und zwar lautlos. Genau daran war dieser Test unzuverlässig (ein Lauf rot, der
    // nächste grün).
    await page.waitForFunction(() => {
        if (!window.Sortable || typeof window.Sortable.get !== 'function') {
            return false;
        }

        const zones = Array.from(document.querySelectorAll('.kanban-cards-container'));

        return zones.length > 0 && zones.every((zone) => Boolean(window.Sortable.get(zone)));
    }, null, { timeout: 10000 });
}

/**
 * Liest die Fläche eines Elements erst, wenn sie sich zwei Messungen lang nicht
 * mehr bewegt.
 *
 * hover() bringt diese Prüfung für die *Karte* schon mit, aber nicht für die
 * Zielspalte. Und genau die rückt nach dem vorigen Zug noch nach: der Platzhalter
 * "Keine offenen Aufgaben" erscheint und ändert die Höhe der leeren Spalte. Beim
 * kalten Erstlauf dauert das länger als der Rest - gemessen blieb dort jeder
 * zweite Zug hängen, während der zweite und dritte Lauf grün waren.
 */
async function stableBox(page, locator) {
    let previous = null;

    for (let attempt = 0; attempt < 25; attempt += 1) {
        const box = await locator.boundingBox();

        if (
            previous && box
            && Math.abs(box.x - previous.x) < 1
            && Math.abs(box.y - previous.y) < 1
            && Math.abs(box.height - previous.height) < 1
            && Math.abs(box.width - previous.width) < 1
        ) {
            return box;
        }

        previous = box;
        await page.waitForTimeout(100);
    }

    return previous;
}

/**
 * Zieht eine Karte in eine andere Spalte und gibt ihre Aufgaben-Kennung zurück.
 *
 * Der Zug läuft über echte Mausschritte statt über dragTo(): SortableJS wertet
 * pointer-/mouse-Ereignisse aus und reagiert nicht auf einen einzelnen Sprung von
 * Quelle zu Ziel. Die Zwischenschritte sind deshalb keine Zierde, sondern die
 * Bedingung dafür, dass die Bibliothek den Zug überhaupt bemerkt.
 */
export async function dragCardToZone(page, card, targetZone) {
    const taskId = await card.getAttribute('data-task-id');
    expect(taskId).toBeTruthy();

    // hover() statt einer selbst gerechneten Koordinate: es wartet darauf, dass die
    // Karte sichtbar, *stabil* und anklickbar ist, und setzt den Zeiger erst dann auf
    // ihre Mitte. Genau die Stabilität fehlte - nach dem vorigen Zug rückt die Spalte
    // noch um wenige Pixel nach (der Platzhalter erscheint), und ein vorher gelesener
    // boundingBox() zeigte daneben. SortableJS bekam den Griff dann gar nicht mit:
    // gemessen Sortable.active === false, kein Ghost, Karte blieb liegen.
    await card.hover();

    const sourceBox = await card.boundingBox();
    const targetBox = await stableBox(page, targetZone);
    expect(sourceBox).toBeTruthy();
    expect(targetBox).toBeTruthy();

    // Der Statuswechsel wird per POST gespeichert. Auf die Antwort zu warten macht
    // den nachfolgenden Reload aussagekräftig - ohne sie prüfte er womöglich einen
    // Stand, der noch gar nicht geschrieben war.
    const statusUpdate = page.waitForResponse(
        (response) => response.url().includes('/tasks/')
            && response.url().includes('/status')
            && response.request().method() === 'POST',
        { timeout: 5000 }
    ).catch(() => null);

    await page.mouse.down();

    // Erst ein kleiner Ruck, dann der Weg zum Ziel. SortableJS startet den Zug über
    // eine Mindestentfernung; springt der Zeiger in einem Zug auf die Zielspalte,
    // liegt die Karte beim Loslassen bereits dort, ohne dass die Bibliothek je einen
    // Zug gesehen hat - gemessen: die Karte blieb dann in ihrer Ausgangsspalte.
    await page.mouse.move(
        sourceBox.x + sourceBox.width / 2 + 10,
        sourceBox.y + sourceBox.height / 2 + 10,
        { steps: 5 }
    );
    await page.mouse.move(
        targetBox.x + targetBox.width / 2,
        targetBox.y + Math.min(targetBox.height / 2, 60),
        { steps: 20 }
    );
    await page.mouse.up();

    await expect(
        targetZone.locator(`.kanban-card[data-task-id="${taskId}"]`).first()
    ).toBeVisible({ timeout: 10000 });

    // Die Karte ist sichtbar, der Zug aber noch nicht vorbei: SortableJS animiert
    // 150 ms nach und hält solange seine Zustandsklassen sowie Sortable.active. Wer
    // in dieser Zeit den nächsten Zug beginnt, wird ignoriert - die Karte bleibt
    // liegen, ohne Fehler. Genau daran scheiterte der zweite Zug dieses Szenarios
    // reproduzierbar; ein fester Schlaf von 1200 ms deckte es nur zu.
    await page.waitForFunction(() => {
        const inFlight = document.querySelector('.sortable-ghost, .sortable-chosen, .sortable-drag');

        return !inFlight && (!window.Sortable || window.Sortable.active === null);
    }, null, { timeout: 10000 });

    const response = await statusUpdate;
    if (response) {
        expect(response.ok()).toBeTruthy();
    }

    return taskId;
}
