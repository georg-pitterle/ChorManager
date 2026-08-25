// Bausteine für TinyMCE-Editoren, die in einem Bootstrap-Modal stehen (siehe
// public/js/tinymce-init.js). TinyMCE hängt seine Überlauf-Leiste, Menüs und Dialoge in den
// Behälter .tox-tinymce-aux direkt an das body-Element, also außerhalb jedes Modals - genau
// dort wird sichtbar, ob Bootstraps Fokus-Falle im Modal diese Bedienelemente unbrauchbar
// macht. Wiederverwendbar für jeden Editor in einem Modal, nicht nur den Newsletter-Editor.

/**
 * Öffnet die Überlauf-Leiste der Symbolleiste über den Knopf "... ein- oder ausblenden"
 * (deutsche Übersetzung von "Reveal or hide additional toolbar items", siehe
 * public/vendor/tinymce/langs/de.js) und wartet, bis sie im DOM sichtbar erscheint.
 *
 * `toolbarRoot` grenzt die Suche nach dem Knopf auf den sichtbaren Editor ein (z. B. das
 * Modal) - die Leiste selbst liegt aber immer im globalen .tox-tinymce-aux-Behälter am body,
 * unabhängig vom Modal.
 */
export async function openToolbarOverflow(page, toolbarRoot) {
    const moreButton = toolbarRoot.locator('button[aria-label*="ein- oder ausblenden"]');
    await moreButton.waitFor({ state: 'visible' });
    await moreButton.click();

    const overflow = page.locator('.tox-tinymce-aux .tox-toolbar__overflow');
    await overflow.waitFor({ state: 'visible' });
    return overflow;
}

/**
 * Öffnet über die Menüleiste (Ansicht -> Quellcode) den Quelltext-Dialog des Editors.
 * `toolbarRoot` grenzt den Klick auf den Menüpunkt "Ansicht" auf den sichtbaren Editor ein;
 * das aufklappende Menü sowie der Dialog selbst liegen am globalen .tox-tinymce-aux-Behälter.
 */
export async function openSourceCodeDialog(page, toolbarRoot) {
    await toolbarRoot.getByRole('menuitem', { name: 'Ansicht', exact: true }).click();
    await page.getByRole('menuitem', { name: 'Quellcode', exact: true }).click();

    const dialog = page.locator('.tox-dialog');
    await dialog.waitFor({ state: 'visible' });
    return dialog;
}

/**
 * Tippt in das Textfeld des offenen Quelltext-Dialogs und liefert den tatsächlich
 * angekommenen Feldinhalt zurück. Beim ursprünglichen Fokus-Fehler riss Bootstrap den Fokus
 * sofort wieder aus dem Feld, sodass keine der getippten Tasten dort ankam.
 */
export async function typeIntoSourceCodeDialog(page, text) {
    const textarea = page.locator('.tox-dialog textarea.tox-textarea');
    await textarea.waitFor({ state: 'visible' });
    await textarea.click();
    await page.keyboard.type(text);
    return textarea.inputValue();
}
