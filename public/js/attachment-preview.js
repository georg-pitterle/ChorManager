/**
 * Füllt das gemeinsame Anhang-Vorschau-Modal.
 *
 * Delegiert auf dem Dokument statt auf einzelnen Buttons, damit auch Anhänge
 * in einem Dropdown oder in einer nachgeladenen Tabellenzeile funktionieren.
 */
document.addEventListener('DOMContentLoaded', function () {
    const modalElement = document.getElementById('attachmentPreviewModal');
    if (!modalElement || !window.bootstrap || !window.bootstrap.Modal) {
        return;
    }

    const body = document.getElementById('attachmentPreviewBody');
    const title = document.getElementById('attachmentPreviewTitle');
    const meta = document.getElementById('attachmentPreviewMeta');
    const downloadLink = document.getElementById('attachmentPreviewDownload');

    // Ab dieser Größe wird die *Darstellung* einer Textvorschau abgeschnitten:
    // der Modal-Körper ist kein Editor. Geladen wird die Datei weiterhin ganz -
    // die Grenze schützt das Rendern, nicht die Übertragung.
    const TEXT_PREVIEW_LIMIT = 200 * 1024;

    function formatSize(bytes) {
        const value = Number(bytes);
        if (!Number.isFinite(value) || value <= 0) {
            return '';
        }
        if (value >= 1048576) {
            return (value / 1048576).toFixed(1).replace('.', ',') + ' MB';
        }
        if (value >= 1024) {
            return Math.round(value / 1024) + ' KB';
        }
        return value + ' B';
    }

    function clearBody() {
        while (body.firstChild) {
            body.removeChild(body.firstChild);
        }
    }

    function showMessage(text) {
        clearBody();
        const paragraph = document.createElement('p');
        paragraph.className = 'text-muted mb-0';
        paragraph.textContent = text;
        body.appendChild(paragraph);
    }

    function renderImage(url, name) {
        const image = document.createElement('img');
        image.src = url;
        image.alt = name;
        image.className = 'attachment-preview-image';
        image.addEventListener('error', function () {
            showMessage('Die Vorschau konnte nicht geladen werden. Bitte die Datei herunterladen.');
        });
        body.appendChild(image);
    }

    function renderPdf(url, name) {
        const frame = document.createElement('iframe');
        frame.src = url;
        frame.title = name;
        frame.className = 'attachment-preview-frame';
        body.appendChild(frame);

        // Der Ladezustand eines iframe ist von außen nicht sauber zu erkennen:
        // eine Fehlerseite des Browsers löst kein `error` aus. Statt das zu
        // erraten, steht der Hinweis dauerhaft unter dem Rahmen.
        const note = document.createElement('p');
        note.className = 'text-muted small mt-2 mb-0';
        note.textContent = 'Bleibt der Rahmen leer, lädt die Datei über den Knopf unten herunter.';
        body.appendChild(note);
    }

    function renderAudio(url) {
        const audio = document.createElement('audio');
        audio.controls = true;
        audio.preload = 'none';
        audio.className = 'w-100';
        audio.src = url;
        audio.addEventListener('error', function () {
            showMessage('Die Vorschau konnte nicht geladen werden. Bitte die Datei herunterladen.');
        });
        body.appendChild(audio);
    }

    function renderText(url) {
        showMessage('Vorschau wird geladen …');

        fetch(url, { credentials: 'same-origin' })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('Antwort ' + response.status);
                }
                return response.text();
            })
            .then(function (text) {
                clearBody();
                const block = document.createElement('pre');
                block.className = 'attachment-preview-text mb-0';
                block.textContent = text.length > TEXT_PREVIEW_LIMIT
                    ? text.slice(0, TEXT_PREVIEW_LIMIT)
                    : text;
                body.appendChild(block);

                if (text.length > TEXT_PREVIEW_LIMIT) {
                    const note = document.createElement('p');
                    note.className = 'text-muted small mt-2 mb-0';
                    note.textContent = 'Vorschau gekürzt. Die vollständige Datei steht im Download.';
                    body.appendChild(note);
                }
            })
            .catch(function () {
                showMessage('Die Vorschau konnte nicht geladen werden. Bitte die Datei herunterladen.');
            });
    }

    function render(mime, id, name) {
        const previewUrl = '/attachments/' + encodeURIComponent(id) + '/preview';
        clearBody();

        if (mime.indexOf('image/') === 0) {
            renderImage(previewUrl, name);
            return;
        }
        if (mime === 'application/pdf') {
            renderPdf(previewUrl, name);
            return;
        }
        if (mime === 'audio/mpeg') {
            renderAudio(previewUrl);
            return;
        }
        if (mime === 'text/plain') {
            renderText(previewUrl);
            return;
        }

        showMessage('Für diesen Dateityp gibt es keine Vorschau. Bitte die Datei herunterladen.');
    }

    document.addEventListener('click', function (event) {
        // Bewusst der eigene Haken und nicht [data-attachment-id]: die
        // Kennung ist ein Datenträger, den ab Task 7 sechs Templates setzen.
        // Ein Klick auf ein Element, das sie nur mitführt, darf hier nicht
        // landen und schon gar nicht sein Standardverhalten verlieren.
        const trigger = event.target.closest('[data-attachment-preview]');
        if (!trigger) {
            return;
        }

        event.preventDefault();

        const id = trigger.getAttribute('data-attachment-id');
        const name = trigger.getAttribute('data-attachment-name') || 'Anhang';
        const rawMime = trigger.getAttribute('data-attachment-mime') || '';
        const mime = rawMime.split(';')[0].trim().toLowerCase();
        const size = trigger.getAttribute('data-attachment-size');

        title.textContent = name;
        const sizeText = formatSize(size);
        meta.textContent = sizeText ? rawMime + ' · ' + sizeText : rawMime;
        downloadLink.setAttribute('href', '/attachments/' + encodeURIComponent(id) + '/download');

        render(mime, id, name);

        window.bootstrap.Modal.getOrCreateInstance(modalElement).show();
    });

    modalElement.addEventListener('hidden.bs.modal', function () {
        clearBody();
    });
});
