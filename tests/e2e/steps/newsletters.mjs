import { expect } from '@playwright/test';

// Verifizierte Selektoren aus templates/newsletters/*.twig und public/js/newsletters*.js:
//  - Uebersicht: Tabelle #newslettersTable, Statusfilter #newsletter-status,
//    Projektfilter #newsletter-status-project ("" = alle, "none" = ohne Projekt)
//  - Alle Aktionen laufen ueber EIN gemeinsames Modal (#newsletterActionModal). Der Inhalt wird
//    per fetch nachgeladen und in #newsletterActionContent eingehaengt (public/js/newsletters.js),
//    ausgeloest von Schaltflaechen mit data-newsletter-modal-url.
//  - Anlegen: #create-newsletter-form mit #project_id, #title, #template (Vorlage laden),
//    Empfaengerquellen in #recipient-sources (je Block [data-source-type="..."] mit Tom Select),
//    Absenden ueber den Button "Erstellen als Entwurf".
//  - Bearbeiten: #edit-newsletter-form (Attribut data-newsletter-id), Versenden ueber
//    #send-newsletter-btn (bestaetigt per window.confirm), Empfaengerzahl in #recipient-count-badge.
//  - Vorlagen: /newsletters/templates, Anlegen-Modal #createTemplateModal.

const MODAL = '#newsletterActionModal';
const MODAL_CONTENT = '#newsletterActionContent';

/**
 * Das Newsletter-Modul ist modul-gegatet (FEATURE_NEWSLETTER). Ist es aus, liefern die Routen 404.
 */
export async function isNewsletterModuleEnabled(page) {
    const status = (await page.request.get('/newsletters')).status();
    return status !== 404;
}

// Der Modalinhalt wird per fetch nachgeladen und enthaelt den TinyMCE-Editor. Unter paralleler
// Last dauert das laenger als der globale Aktions-Timeout von 15 Sekunden - deshalb hier explizit
// grosszuegiger.
const MODAL_CONTENT_TIMEOUT = 30_000;

async function waitForModalContent(page, selector) {
    await page.locator(MODAL).waitFor({ state: 'visible', timeout: MODAL_CONTENT_TIMEOUT });
    await page.locator(`${MODAL_CONTENT} ${selector}`).waitFor({
        state: 'visible',
        timeout: MODAL_CONTENT_TIMEOUT,
    });
}

/**
 * Wartet nach dem Absenden auf die erwartete Folgeansicht - oder bricht mit der Fehlermeldung ab,
 * die das Modal anzeigt. Ohne das liefe ein abgelehnter Datensatz nur in einen nichtssagenden
 * Timeout.
 */
async function waitForModalResultOrError(page, selector) {
    const target = page.locator(`${MODAL_CONTENT} ${selector}`);
    const alertBox = page.locator(`${MODAL_CONTENT} .newsletter-modal-alert`);

    await Promise.race([
        target.waitFor({ state: 'visible', timeout: MODAL_CONTENT_TIMEOUT }),
        alertBox.waitFor({ state: 'visible', timeout: MODAL_CONTENT_TIMEOUT }),
    ]).catch(() => {
        // Beide Wege ausgelaufen - die Pruefung unten liefert die aussagekraeftige Meldung.
    });

    if (await alertBox.isVisible()) {
        throw new Error(`Das Modal meldet einen Fehler: ${(await alertBox.innerText()).trim()}`);
    }

    await target.waitFor({ state: 'visible', timeout: MODAL_CONTENT_TIMEOUT });
}

/**
 * TinyMCE ersetzt die Textarea durch ein iframe. Wir tippen in den echten Editorkoerper,
 * damit der Inhalt genauso entsteht wie bei einer Nutzerin - beim Absenden liest das Formular
 * ihn ueber tinymce.get("content_html").getContent().
 */
export async function fillEditor(page, text) {
    const editorFrame = page.frameLocator(`${MODAL_CONTENT} .tox-edit-area iframe`);
    const body = editorFrame.locator('body');
    await body.waitFor({ state: 'visible' });
    await body.click();
    await body.fill(text);
}

/**
 * Waehlt Eintraege in einem Tom-Select-Feld einer Empfaengerquelle aus. Getippt und geklickt wird
 * im echten Widget; das darunterliegende <select multiple> wird dadurch von Tom Select gepflegt.
 */
export async function pickRecipientSource(page, sourceType, labels) {
    const block = page.locator(`${MODAL_CONTENT} #recipient-sources [data-source-type="${sourceType}"]`);
    const control = block.locator('.ts-control');
    const search = control.locator('input');
    await control.waitFor({ state: 'visible' });

    for (const label of labels) {
        await control.click();
        // Tom Select laesst den Suchtext nach einer Auswahl stehen; ohne Leeren wuerde sich der
        // naechste Suchbegriff anhaengen und keine Option mehr treffen.
        await search.fill('');
        await search.pressSequentially(label);

        const option = block.locator('.ts-dropdown .option', { hasText: label }).first();
        await option.waitFor({ state: 'visible' });
        await option.click();

        await expect(block.locator('.ts-control .item', { hasText: label })).toBeVisible();
        // Liste ueber den Fokusverlust schliessen, damit der naechste Klick nicht auf einer
        // offenen Liste landet. Kein Escape: das schliesst in Bootstrap das ganze Modal.
        await search.blur();
        await expect(block.locator('.ts-dropdown')).toBeHidden();
    }
}

/**
 * Entfernt alle Auswahlen einer Empfaengerquelle ueber die x-Schaltflaechen der Chips.
 */
export async function clearRecipientSource(page, sourceType) {
    const block = page.locator(`${MODAL_CONTENT} #recipient-sources [data-source-type="${sourceType}"]`);
    const removeButtons = block.locator('.ts-control .item .remove');
    for (let count = await removeButtons.count(); count > 0; count = await removeButtons.count()) {
        await removeButtons.first().click();
    }
}

export async function readRecipientCount(page) {
    const badge = page.locator(`${MODAL_CONTENT} #recipient-count-badge`);
    await badge.waitFor({ state: 'visible' });
    // Die Zahl wird nach jeder Aenderung per fetch neu ermittelt; auf einen stabilen Zahlenwert warten.
    await expect(badge).toHaveText(/^\d+$/, { timeout: 15_000 });
    return Number((await badge.innerText()).trim());
}

export async function openCreateModal(page) {
    await page.goto('/newsletters?status=draft');
    await page.click('[data-newsletter-modal-url*="/newsletters/create"]');
    await waitForModalContent(page, '#create-newsletter-form');
}

/**
 * Legt einen Entwurf ueber das Modal an (der Hauptweg der Anwendung) und liefert seine ID.
 * Nach dem Absenden laedt dasselbe Modal die Bearbeiten-Ansicht des neuen Entwurfs nach.
 *
 * @param {object} draft
 * @param {string} draft.title
 * @param {?string} draft.project      Projektname oder null fuer "kein Projekt"
 * @param {?string} draft.content      Text fuer den Editor; entfaellt, wenn eine Vorlage geladen wird
 * @param {?string} draft.template     Name einer Vorlage, die vor dem Tippen geladen wird
 * @param {object} draft.sources       { project_members?: string[], role?: string[], user?: string[] }
 */
export async function createNewsletterDraft(page, draft) {
    await openCreateModal(page);
    const content = page.locator(MODAL_CONTENT);

    await content.locator('#project_id').selectOption({ label: draft.project ?? '— kein Projekt —' });

    // Die Vorlage wird VOR dem Titel geladen: Das Laden setzt den Titel auf den Vorlagennamen
    // (siehe public/js/newsletters-create.js) und wuerde eine vorherige Eingabe ueberschreiben.
    if (draft.template) {
        await content.locator('#template').selectOption({ label: draft.template });
        // Der Vorlageninhalt wird per fetch geholt und in den Editor geschrieben.
        await expect(page.frameLocator(`${MODAL_CONTENT} .tox-edit-area iframe`).locator('body'))
            .not.toBeEmpty({ timeout: 15_000 });
    }

    await content.locator('#title').fill(draft.title);

    // Die Projektquelle ist mit dem gewaehlten Projekt vorbelegt. Fuer eine saubere, vorhersagbare
    // Auswahl wird sie geleert und danach genau das gesetzt, was das Szenario verlangt.
    await clearRecipientSource(page, 'project_members');
    for (const [sourceType, labels] of Object.entries(draft.sources ?? {})) {
        await pickRecipientSource(page, sourceType, labels);
    }

    if (draft.content) {
        await fillEditor(page, draft.content);
    }

    await content.locator('button[type="submit"]', { hasText: 'Erstellen als Entwurf' }).click();

    // Das Modal wechselt in die Bearbeiten-Ansicht des neuen Entwurfs.
    await waitForModalResultOrError(page, '#edit-newsletter-form');
    const id = await content.locator('#edit-newsletter-form').getAttribute('data-newsletter-id');
    return Number(id);
}

/**
 * Versendet den im Bearbeiten-Modal geoeffneten Newsletter. Der Button bestaetigt per
 * window.confirm; danach laedt die Seite auf die Liste der versendeten Newsletter um.
 */
export async function sendOpenNewsletter(page) {
    page.once('dialog', (dialog) => dialog.accept());
    await page.locator(`${MODAL_CONTENT} #send-newsletter-btn`).click();
    await page.waitForURL('**/newsletters?**status=sent**');
}

/**
 * Oeffnet die Bearbeiten-Seite direkt (ohne Modal). Diese Sitzung haelt danach die Sperre,
 * solange die Seite offen bleibt.
 */
export async function openEditPage(page, newsletterId) {
    await page.goto(`/newsletters/${newsletterId}/edit`);
    await page.locator('#edit-newsletter-form').waitFor({ state: 'visible' });
}

/**
 * Versendet den auf der Bearbeiten-SEITE (nicht im Modal) geoeffneten Newsletter.
 * Dort sendet die Schaltflaeche das versteckte Formular ab, statt per fetch zu arbeiten.
 */
export async function sendFromEditPage(page) {
    page.once('dialog', (dialog) => dialog.accept());
    await page.locator('#send-newsletter-btn').click();
    await page.waitForURL('**/newsletters?**status=sent**');
}

/**
 * Sichert den Inhalt des offenen Newsletters als neue Vorlage (Bearbeiten-SEITE).
 * Verifizierte Selektoren aus templates/newsletters/edit.twig: Schaltflaeche mit
 * data-bs-target="#saveTemplateModal", Felder #template_name und #template_description,
 * Absenden ueber #save-template-btn. Der Erfolg wird per window.alert gemeldet.
 */
export async function saveOpenNewsletterAsTemplate(page, template) {
    await page.click('[data-bs-target="#saveTemplateModal"]');
    const modal = page.locator('#saveTemplateModal');
    await modal.waitFor({ state: 'visible' });

    await modal.locator('#template_name').fill(template.name);
    await modal.locator('#template_description').fill(template.description);

    const alertShown = page.waitForEvent('dialog').then((dialog) => {
        const message = dialog.message();
        return dialog.accept().then(() => message);
    });
    await modal.locator('#save-template-btn').click();
    return alertShown;
}

export async function openEditModalByTitle(page, title) {
    await page.goto('/newsletters?status=draft');
    const row = page.locator('#newslettersTable tbody tr', { hasText: title });
    await row.waitFor({ state: 'visible' });
    await row.locator('[data-newsletter-modal-url*="/edit"]').click();
    await waitForModalContent(page, '#edit-newsletter-form');
}

/**
 * Legt eine Vorlage ueber /newsletters/templates an.
 *
 * @param {object} template
 * @param {string} template.name
 * @param {string} template.description
 * @param {string} template.content
 * @param {?string} template.project   Projektname; ohne Angabe wird die Vorlage global
 */
export async function createNewsletterTemplate(page, template) {
    await page.goto('/newsletters/templates');
    await page.click('[data-bs-target="#createTemplateModal"]');
    const modal = page.locator('#createTemplateModal');
    await modal.waitFor({ state: 'visible' });

    await modal.locator('input[name="name"]').fill(template.name);
    await modal.locator('textarea[name="description"]').fill(template.description);
    await modal.locator('select[name="project_id"]').selectOption({ label: template.project ?? 'Global' });

    const editorBody = page.frameLocator('#createTemplateModal .tox-edit-area iframe').locator('body');
    await editorBody.waitFor({ state: 'visible' });
    await editorBody.click();
    await editorBody.fill(template.content);

    await modal.locator('button[type="submit"]', { hasText: 'Vorlage erstellen' }).click();
    await page.waitForURL('**/newsletters/templates/**/edit');
}

/**
 * Titel aller Newsletter in der Uebersicht fuer den gegebenen Status.
 */
export async function listNewsletterTitles(page, { status = 'draft', projectFilter = '' } = {}) {
    const query = new URLSearchParams({ status });
    if (projectFilter !== '') {
        query.set('project_id', projectFilter);
    }
    await page.goto(`/newsletters?${query.toString()}`);
    await page.locator('#newslettersTable').waitFor({ state: 'visible' });
    return page.locator('#newslettersTable tbody tr td[data-label="Titel"]').allInnerTexts();
}
