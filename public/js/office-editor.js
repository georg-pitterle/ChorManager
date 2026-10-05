/**
 * Editor-Seite der Dateiablage: schickt das Formular mit dem Zugangstoken in den
 * Collabora-Rahmen und reagiert auf dessen Nachrichten. Die Auswertung der
 * Nachrichten steht in office-editor-messages.js.
 */
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('officeEditorForm');
    const frame = document.getElementById('officeEditorFrame');
    if (!form || !frame || !window.OfficeEditorMessages) {
        return;
    }

    const officeOrigin = form.dataset.officeOrigin || '';
    const backUrl = form.dataset.backUrl || '/files';

    window.addEventListener('message', function (event) {
        if (event.source !== frame.contentWindow) {
            return;
        }

        const action = window.OfficeEditorMessages.actionFor(event.origin, event.data, officeOrigin);
        if (action === 'ready') {
            frame.contentWindow.postMessage(window.OfficeEditorMessages.hostMessage('Host_PostmessageReady'), officeOrigin);
        } else if (action === 'close') {
            window.location.assign(backUrl);
        }
    });

    form.submit();
});
