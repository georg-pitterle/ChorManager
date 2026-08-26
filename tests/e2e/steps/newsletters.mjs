import { expect } from '@playwright/test';

// Verifizierte Selektoren aus templates/newsletters/*.twig und public/js/newsletters*.js:
//  - Uebersicht: Tabelle #newslettersTable, Statusfilter #newsletter-status,
//    Projektfilter #newsletter-status-project ("" = alle, "none" = ohne Projekt)
//  - Alle Aktionen laufen über EIN gemeinsames Modal (#newsletterActionModal). Der Inhalt wird
//    per fetch nachgeladen und in #newsletterActionContent eingehängt (public/js/newsletters.js),
//    ausgelöst von Schaltflächen mit data-newsletter-modal-url.
//  - Anlegen: #create-newsletter-form mit #project_id, #title, #template (Vorlage laden),
//    Empfängerquellen in #recipient-sources (je Block [data-source-type="..."] mit Tom Select),
//    Absenden über den Button "Erstellen als Entwurf".
//  - Bearbeiten: #edit-newsletter-form (Attribut data-newsletter-id), Versenden über
//    #send-newsletter-btn (bestätigt per window.confirm), Empfängerzahl in #recipient-count-badge.
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

// Der Modalinhalt wird per fetch nachgeladen und enthält den TinyMCE-Editor. Unter paralleler
// Last dauert das länger als der globale Aktions-Timeout von 15 Sekunden - deshalb hier explizit
// großzügiger.
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
        // Beide Wege ausgelaufen - die Prüfung unten liefert die aussagekräftige Meldung.
    });

    if (await alertBox.isVisible()) {
        throw new Error(`Das Modal meldet einen Fehler: ${(await alertBox.innerText()).trim()}`);
    }

    await target.waitFor({ state: 'visible', timeout: MODAL_CONTENT_TIMEOUT });
}

/**
 * TinyMCE ersetzt die Textarea durch ein iframe. Wir tippen in den echten Editorkörper,
 * damit der Inhalt genauso entsteht wie bei einer Nutzerin - beim Absenden liest das Formular
 * ihn über tinymce.get("content_html").getContent().
 */
export async function fillEditor(page, text) {
    // Das iframe ist sichtbar, bevor TinyMCE fertig initialisiert ist. Wer davor tippt, dessen
    // Text wird vom Init (setzt den Inhalt aus der Textarea) wieder verworfen - das Modal meldet
    // dann "Titel und Inhalt sind Pflichtfelder", obwohl der Editor befüllt aussah.
    await page.waitForFunction(
        () => window.tinymce?.get('content_html')?.initialized === true,
        null,
        { timeout: MODAL_CONTENT_TIMEOUT },
    );

    const editorFrame = page.frameLocator(`${MODAL_CONTENT} .tox-edit-area iframe`);
    const body = editorFrame.locator('body');
    await body.waitFor({ state: 'visible' });

    // Unter paralleler Last kommt der erste Tippvorgang gelegentlich nicht im Editor an: Der
    // Editorkörper ist sichtbar und beschreibbar, TinyMCE meldet danach aber weiter leeren
    // Inhalt, und das Modal weist den Entwurf mit "Titel und Inhalt sind Pflichtfelder" ab.
    // Deshalb mehrere Versuche, jeder mit Gegenprobe am echten Editorinhalt - genau den liest
    // das Formular beim Absenden (public/js/newsletters-create.js:
    // tinymce.get("content_html").getContent()).
    for (let attempt = 0; attempt < 3; attempt += 1) {
        await body.click();
        await body.fill(text);

        const arrived = await page
            .waitForFunction(
                () => window.tinymce.get('content_html').getContent({ format: 'text' }).trim() !== '',
                null,
                { timeout: 5_000 },
            )
            .then(() => true)
            .catch(() => false);

        if (arrived) {
            return;
        }
    }

    throw new Error('Der Text ist auch nach drei Versuchen nicht im TinyMCE-Editor angekommen.');
}

/**
 * Wählt Einträge in einem Tom-Select-Feld einer Empfängerquelle aus. Getippt und geklickt wird
 * im echten Widget; das darunterliegende <select multiple> wird dadurch von Tom Select gepflegt.
 */
export async function pickRecipientSource(page, sourceType, labels) {
    const block = page.locator(`${MODAL_CONTENT} #recipient-sources [data-source-type="${sourceType}"]`);
    const control = block.locator('.ts-control');
    const search = control.locator('input');
    await control.waitFor({ state: 'visible' });

    const dropdown = block.locator('.ts-dropdown');

    for (const label of labels) {
        // Tom Select toggelt: Ein Klick auf ein bereits offenes Feld SCHLIESST die Liste. Offen
        // sein kann sie z. B. nach clearRecipientSource(), dessen x-Klicks das Feld fokussieren.
        // Der Suchtext filtert dann eine unsichtbare Liste - das Fehlerbild ist eine vorhandene,
        // aber verborgene Option.
        if (!(await dropdown.isVisible())) {
            await control.click();
        }

        // Tom Select lässt den Suchtext nach einer Auswahl stehen; ohne Leeren würde sich der
        // nächste Suchbegriff anhängen und keine Option mehr treffen.
        await search.fill('');
        await search.pressSequentially(label);

        const option = block.locator('.ts-dropdown .option', { hasText: label }).first();
        // Die Liste kann zwischen Klick und Tippen wieder zugehen (Fokuswechsel im Modal).
        // Deshalb nicht blind auf Sichtbarkeit warten, sondern sie notfalls erneut öffnen.
        await expect
            .poll(
                async () => {
                    if (await option.isVisible()) {
                        return true;
                    }

                    await control.click();

                    return option.isVisible();
                },
                {
                    timeout: MODAL_CONTENT_TIMEOUT,
                    message: `Die Option "${label}" muss in der Liste "${sourceType}" sichtbar werden`,
                }
            )
            .toBe(true);
        await option.click();

        await expect(block.locator('.ts-control .item', { hasText: label })).toBeVisible();
        // Liste über den Fokusverlust schließen, damit der nächste Klick nicht auf einer
        // offenen Liste landet. Kein Escape: das schließt in Bootstrap das ganze Modal.
        await search.blur();
        await expect(block.locator('.ts-dropdown')).toBeHidden();
    }
}

/**
 * Entfernt alle Auswahlen einer Empfängerquelle über die x-Schaltflächen der Chips.
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
 * Legt einen Entwurf über das Modal an (der Hauptweg der Anwendung) und liefert seine ID.
 * Nach dem Absenden lädt dasselbe Modal die Bearbeiten-Ansicht des neuen Entwurfs nach.
 *
 * @param {object} draft
 * @param {string} draft.title
 * @param {?string} draft.project      Projektname oder null für "kein Projekt"
 * @param {?string} draft.content      Text für den Editor; entfällt, wenn eine Vorlage geladen wird
 * @param {?string} draft.template     Name einer Vorlage, die vor dem Tippen geladen wird
 * @param {object} draft.sources       { project_members?: string[], role?: string[], user?: string[] }
 */
export async function createNewsletterDraft(page, draft) {
    await openCreateModal(page);
    const content = page.locator(MODAL_CONTENT);

    await content.locator('#project_id').selectOption({ label: draft.project ?? '— kein Projekt —' });

    // Die Vorlage wird VOR dem Titel geladen: Das Laden setzt den Titel auf den Vorlagennamen
    // (siehe public/js/newsletters-create.js) und würde eine vorherige Eingabe überschreiben.
    if (draft.template) {
        await content.locator('#template').selectOption({ label: draft.template });
        // Der Vorlageninhalt wird per fetch geholt und in den Editor geschrieben.
        await expect(page.frameLocator(`${MODAL_CONTENT} .tox-edit-area iframe`).locator('body'))
            .not.toBeEmpty({ timeout: 15_000 });
    }

    await content.locator('#title').fill(draft.title);

    // Die Projektquelle ist mit dem gewählten Projekt vorbelegt. Für eine saubere, vorhersagbare
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
 * Versendet den im Bearbeiten-Modal geöffneten Newsletter. Der Button bestätigt per
 * window.confirm; danach lädt die Seite auf die Liste der versendeten Newsletter um.
 */
export async function sendOpenNewsletter(page) {
    page.once('dialog', (dialog) => dialog.accept());

    // Der Versand-Knopf im Modal spricht per fetch die JSON-API an, ohne den Formularinhalt
    // mitzuschicken - der Server versendet strikt das, was zuvor gespeichert wurde. Auf die
    // Antwort dieser Anfrage warten, statt blind auf die Weiterleitung zu hoffen: Lehnt der
    // Server ab (z. B. weil keine Empfänger gespeichert sind), bleibt die Seite stehen und ein
    // bloßes waitForURL liefe nur in einen nichtssagenden Zeitablauf.
    const sendResponsePromise = page.waitForResponse(
        (response) => /\/newsletters\/\d+\/send$/.test(new URL(response.url()).pathname)
            && response.request().method() === 'POST'
    );
    await page.locator(`${MODAL_CONTENT} #send-newsletter-btn`).click();
    const response = await sendResponsePromise;
    if (!response.ok()) {
        const body = await response.json().catch(() => ({}));
        throw new Error(
            `Versenden wurde vom Server abgelehnt: HTTP ${response.status()} ${body.error ?? ''}`.trim()
        );
    }

    await page.waitForURL('**/newsletters?**status=sent**');
}

/**
 * Oeffnet die Bearbeiten-Seite direkt (ohne Modal). Diese Sitzung hält danach die Sperre,
 * solange die Seite offen bleibt.
 */
export async function openEditPage(page, newsletterId) {
    await page.goto(`/newsletters/${newsletterId}/edit`);
    await page.locator('#edit-newsletter-form').waitFor({ state: 'visible' });
}

/**
 * Verlässt den geöffneten Editor (Bearbeiten-Seite oder Modal) und wartet, bis die Sperre
 * tatsächlich freigegeben ist.
 *
 * Warum nicht einfach wegnavigieren: Die Freigabe läuft per navigator.sendBeacon beim Entladen
 * (public/js/newsletters-edit.js). Endet ein Test mit noch offenem Editor und wird der Kontext
 * geschlossen, geht der Beacon verloren - der Entwurf bleibt bis zum Ablauf der 30-Minuten-Frist
 * gesperrt (NewsletterLockingService::LOCK_TIMEOUT_MINUTES). Jede spätere Bearbeiten-Anfrage
 * einer ANDEREN Sitzung endet dann auf HTTP 423, auch die des Crawlers, der nach den Szenarien
 * über dieselbe Datenbank läuft.
 */
export async function leaveEditorAndAwaitLockRelease(page, newsletterId) {
    await page.goto('/dashboard');
    await expect
        .poll(
            async () => (await (await page.request.get(`/newsletters/${newsletterId}/check-lock`)).json()).locked,
            { message: `Die Sperre auf Newsletter ${newsletterId} muss nach dem Verlassen freigegeben sein` }
        )
        .toBe(false);
}

/**
 * Versendet den auf der Bearbeiten-SEITE (nicht im Modal) geöffneten Newsletter.
 * Dort sendet die Schaltfläche das versteckte Formular ab, statt per fetch zu arbeiten.
 */
export async function sendFromEditPage(page) {
    page.once('dialog', (dialog) => dialog.accept());
    await page.locator('#send-newsletter-btn').click();
    await page.waitForURL('**/newsletters?**status=sent**');
}

/**
 * Sichert den Inhalt des offenen Newsletters als neue Vorlage (Bearbeiten-SEITE).
 * Verifizierte Selektoren aus templates/newsletters/edit.twig: Schaltfläche mit
 * data-bs-target="#saveTemplateModal", Felder #template_name und #template_description,
 * Absenden über #save-template-btn. Der Erfolg wird per window.alert gemeldet.
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
 * Öffnet den Bearbeiten-Dialog aus der Übersicht für einen Entwurf, den gerade jemand anderes
 * bearbeitet, und liefert den angezeigten Text zurück.
 *
 * Der Server antwortet in diesem Fall mit HTTP 423 und liefert dazu die fertige Sperrseite
 * (newsletters/locked.twig, modaltauglich). Der Dialog muss sie anzeigen - eine Ersatzmeldung
 * "Inhalt konnte nicht geladen werden" würde die Ursache verschweigen.
 */
export async function openEditModalForLockedDraft(page, title) {
    await page.goto('/newsletters?status=draft');
    const row = page.locator('#newslettersTable tbody tr', { hasText: title });
    await row.waitFor({ state: 'visible' });
    await row.locator('[data-newsletter-modal-url*="/edit"]').click();
    await waitForModalContent(page, '#reload-locked-newsletter-btn');

    return (await page.locator(MODAL_CONTENT).innerText()).trim();
}

/**
 * Legt eine Vorlage über /newsletters/templates an.
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

// Fügt einen Platzhalter über die Editor-Leiste ein, nicht per Tastatur: nur so ist
// sichergestellt, dass der Knopf existiert und reinen Text einfügt.
export async function insertPlaceholder(page, label) {
    // TinyMCE macht aus dem tooltip das aria-label ("Platzhalter einfügen"), und der
    // barrierefreie Name gewinnt gegen den sichtbaren Text - daher als Muster suchen.
    await page.getByRole('button', { name: /Platzhalter/ }).first().click();
    await page.getByRole('menuitem', { name: new RegExp(label) }).click();
}

/**
 * Speichert den im Bearbeiten-Modal geöffneten Entwurf. Ein erfolgreiches Speichern schließt
 * das Modal und lädt die zugrunde liegende Seite komplett neu (public/js/newsletters-edit.js:
 * saveNewsletter ruft im Modal-Fall window.newsletterModalCloseAndRefresh -> location.reload
 * auf). Das Warten endet erst, wenn dieses vom Browser selbst ausgelöste Neuladen
 * abgeschlossen ist - sonst geriete eine direkt anschließende eigene Navigation (z. B.
 * openEditPage) in einen Wettlauf mit diesem Reload ("Navigation ... interrupted by another
 * navigation").
 */
export async function saveOpenNewsletterDraft(page, newsletterId) {
    const responsePromise = page.waitForResponse(
        (response) => response.url().endsWith(`/newsletters/${newsletterId}`) && response.request().method() === 'POST'
    );
    const reloadPromise = page.waitForEvent('load');
    await page.locator(`${MODAL_CONTENT} #save-draft-btn`).click();
    const response = await responsePromise;
    if (!response.ok()) {
        throw new Error(`Speichern des Entwurfs ist fehlgeschlagen: HTTP ${response.status()}`);
    }
    await reloadPromise;
}

/**
 * Öffnet die Vorschau auf der Bearbeiten-SEITE (nicht im Modal) für eine bestimmte empfangende
 * Person und liefert den sichtbaren Vorschautext aus dem eingebetteten Rahmen. Nur auf der
 * echten Seite läuft der Vorschau-Knopf über den fetch-Weg (#previewModal,
 * /newsletters/{id}/preview-render), dessen fertiges Mail-HTML als srcdoc des Rahmens
 * #preview-modal-frame landet - im Modal navigiert derselbe Knopf stattdessen auf eine andere,
 * serverseitig gerenderte Ansicht (public/js/newsletters-edit.js: window.newsletterModalNavigate).
 */
export async function previewOpenNewsletterFor(page, recipientFirstName) {
    const option = page.locator('#preview-recipient option', { hasText: recipientFirstName });
    const value = await option.getAttribute('value');
    await page.locator('#preview-recipient').selectOption(value);

    await page.click('#preview-btn');
    const previewModal = page.locator('#previewModal');
    await previewModal.waitFor({ state: 'visible', timeout: MODAL_CONTENT_TIMEOUT });
    const frameBody = page.frameLocator('#preview-modal-frame').locator('body');
    await expect(frameBody).not.toBeEmpty({ timeout: MODAL_CONTENT_TIMEOUT });
    const text = (await frameBody.innerText()).trim();

    // Auf der Bearbeiten-SEITE öffnet der Vorschau-Knopf ein echtes Bootstrap-Modal
    // (data-bs-toggle="modal"), das nach dem Lesen offen bliebe und mit seinem Backdrop jeden
    // weiteren Klick auf der Seite abfangen würde (z. B. den Testmail-Knopf danach).
    await previewModal.locator('.modal-footer button[data-bs-dismiss="modal"]').click();
    await previewModal.waitFor({ state: 'hidden', timeout: MODAL_CONTENT_TIMEOUT });

    return text;
}

/**
 * Löst die Testmail auf der Bearbeiten-SEITE aus und liefert die Bestätigungsmeldung
 * (public/js/newsletters-edit.js: #test-mail-btn zeigt sie über .newsletter-edit-alert an).
 */
export async function sendTestMailFromEditPage(page) {
    const alertBox = page.locator('.newsletter-edit-alert');
    await page.click('#test-mail-btn');
    await alertBox.waitFor({ state: 'visible', timeout: MODAL_CONTENT_TIMEOUT });
    return (await alertBox.innerText()).trim();
}

/**
 * Titel aller Newsletter in der Uebersicht für den gegebenen Status.
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
