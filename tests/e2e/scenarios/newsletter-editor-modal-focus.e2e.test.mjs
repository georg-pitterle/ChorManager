import { test, expect } from '@playwright/test';
import {
    isNewsletterModuleEnabled,
    openCreateModal,
    createNewsletterDraft,
    fillEditor,
    leaveEditorAndAwaitLockRelease,
} from '../steps/newsletters.mjs';
import { openToolbarOverflow, openSourceCodeDialog, typeIntoSourceCodeDialog } from '../steps/tinymce.mjs';

// Reproduziert den Fokus-Konflikt zwischen Bootstrap und TinyMCE im Newsletter-Anlegen-Modal
// (#newsletterActionModal, Trigger data-newsletter-modal-url). Bootstrap holt im Modal bei jedem
// Fokuswechsel den Fokus zurück in den Dialog; TinyMCE hängt Überlauf-Leiste, Menüs und Dialoge
// aber in den Behälter .tox-tinymce-aux direkt an das body-Element, außerhalb des Modals. Ohne
// die Absicherung in public/js/tinymce-init.js (Fokus-Beobachter in der Erfassungsphase) öffnet
// sich die Überlauf-Leiste nicht, und im Quelltext-Dialog kommt keine Tastatureingabe an.

const MODAL_CONTENT = '#newsletterActionContent';

test('Newsletter-Editor im Modal: Überlauf-Leiste und Quelltext-Dialog sind bedienbar', async ({ page }) => {
    test.setTimeout(60_000);

    test.skip(!(await isNewsletterModuleEnabled(page)), 'Newsletter-Modul ist in dieser Umgebung aus.');

    // Ein schmaler Viewport erzwingt deterministisch eine Überlauf-Leiste: Bei voller
    // Fensterbreite passen im modal-xl sonst alle Symbolleisten-Knöpfe in eine Zeile, und der
    // "..."-Knopf erscheint erst gar nicht.
    await page.setViewportSize({ width: 800, height: 900 });

    await openCreateModal(page);

    // Erst nach vollständiger Initialisierung tippen/klicken - siehe Kommentar in
    // steps/newsletters.mjs (fillEditor) zum selben Wettlauf.
    await page.waitForFunction(() => window.tinymce?.get('content_html')?.initialized === true);

    const content = page.locator(MODAL_CONTENT);

    // 1. Überlauf-Leiste: öffnet sich nur, wenn Bootstrap den Fokus auf dem "..."-Knopf lässt.
    const overflow = await openToolbarOverflow(page, content);
    await expect(overflow).toBeVisible();
    await expect(overflow.locator('button').first()).toBeVisible();

    // 2. Quelltext-Dialog: Ansicht -> Quellcode, dann eine eindeutige Markierung eintippen.
    const dialog = await openSourceCodeDialog(page, content);
    const marker = 'Fokus-Testmarkierung-' + Date.now();
    const fieldValue = await typeIntoSourceCodeDialog(page, marker);

    expect(fieldValue, 'Die Tastatureingabe im Quelltext-Dialog muss im Feld ankommen').toContain(marker);

    await dialog.getByRole('button', { name: 'Abbrechen' }).click();
    await dialog.waitFor({ state: 'hidden' });
});

// Die Vorschau im Bearbeiten-Modal hat den Editor früher durch die Vorschauseite ERSETZT: Wer
// sie schloss, landete in der Listenansicht, und alle noch nicht gespeicherten Änderungen waren
// weg. Sie öffnet jetzt ein eigenes Fenster über dem Editor.
test('Newsletter-Editor im Modal: Vorschau lässt den Editor stehen', async ({ page }) => {
    test.setTimeout(90_000);

    test.skip(!(await isNewsletterModuleEnabled(page)), 'Newsletter-Modul ist in dieser Umgebung aus.');

    const newsletterId = await createNewsletterDraft(page, {
        title: 'Vorschau-Rückkehr ' + Date.now(),
        content: 'Inhalt für die Vorschau-Prüfung.',
    });

    await page.waitForFunction(() => window.tinymce?.get('content_html')?.initialized === true);

    // Ungespeicherte Änderung: Sie muss die Vorschau überleben.
    const unsavedTitle = 'Ungespeicherter Titel ' + Date.now();
    await page.locator('#title').fill(unsavedTitle);

    await page.locator('#preview-btn').click();

    const previewModal = page.locator('#previewModal');
    await expect(previewModal).toBeVisible();

    // Der Editor bleibt darunter stehen, statt weggeblendet zu werden.
    await expect(page.locator('#edit-newsletter-form')).toBeAttached();

    // Die Vorschau zeigt den ungespeicherten Stand, nicht die gespeicherte Fassung.
    await expect(
        page.frameLocator('#preview-modal-frame').locator('body')
    ).toContainText(unsavedTitle, { timeout: 15_000 });

    await previewModal.locator('.btn-close').click();
    await expect(previewModal).toBeHidden();

    // Zurück im Editor, mit der Änderung.
    await expect(page.locator('#edit-newsletter-form')).toBeVisible();
    await expect(page.locator('#title')).toHaveValue(unsavedTitle);

    // Das Vorschaufenster wird zum Öffnen aus dem Dialoginhalt an das body-Element gehängt
    // (public/js/newsletters-edit.js, openPreviewOverlay). Beim Schließen des Dialogs muss es
    // wieder verschwinden - sonst bleibt es dort liegen, der nächste Dialog bringt sein eigenes
    // mit, und im Dokument stehen mehrere Knoten mit derselben id (#previewModal,
    // #preview-modal-frame), jeder mit dem vollständigen Vorschau-HTML im srcdoc.
    await page.locator('#newsletterActionModal > .modal-dialog > .modal-content > .modal-header > .btn-close').click();
    await expect(page.locator('#newsletterActionModal')).toBeHidden();
    await expect(page.locator('#previewModal')).toHaveCount(0);

    // Den Entwurf nicht gesperrt zurücklassen (siehe leaveEditorAndAwaitLockRelease).
    await leaveEditorAndAwaitLockRelease(page, newsletterId);
});
