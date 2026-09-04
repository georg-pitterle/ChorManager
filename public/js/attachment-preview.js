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

    // pdf.js liegt lokal unter /vendor/pdfjs (kopiert von bin/copy-assets.php).
    // Kein CDN - instructions/template-hygiene.md verbietet externe Laufzeit-Assets.
    const PDFJS_BASE = '/vendor/pdfjs/';

    // Ab hier wird die *Darstellung* abgeschnitten, nicht die Datei. Ein Programmheft
    // mit hundert Seiten in ein Modal zu zeichnen kostet auf dem Telefon mehr Speicher
    // als der Anhang je wert ist; die vollständige Datei steht im Download daneben.
    const PDF_PAGE_LIMIT = 30;

    // Mehr als das Doppelte bringt sichtbar nichts mehr und vervierfacht die Fläche.
    const PDF_MAX_PIXEL_RATIO = 2;

    // Zählt jedes Leeren des Körpers mit. Die PDF- und die Textvorschau laufen
    // asynchron; ohne diesen Vergleich zeichnete eine langsam geladene Datei noch in
    // ein Fenster, das längst den nächsten Anhang zeigt.
    let renderToken = 0;

    let pdfLibraryPromise = null;

    function loadPdfLibrary() {
        if (pdfLibraryPromise === null) {
            // Der Import steckt hinter Promise.resolve(), damit auch ein synchroner
            // Fehler - fehlende Datei, abgelehnte Übersetzung - als abgelehntes
            // Promise ankommt statt den Klick-Handler abzubrechen.
            pdfLibraryPromise = Promise.resolve()
                .then(function () {
                    return import(PDFJS_BASE + 'pdf.min.mjs');
                })
                .then(function (pdfjs) {
                    pdfjs.GlobalWorkerOptions.workerSrc = PDFJS_BASE + 'pdf.worker.min.mjs';
                    return pdfjs;
                });

            // Ein Fehlschlag darf sich nicht einbrennen: der nächste Versuch soll
            // wieder laden dürfen.
            pdfLibraryPromise.catch(function () {
                pdfLibraryPromise = null;
            });
        }

        return pdfLibraryPromise;
    }

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
        renderToken++;

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

    /**
     * Zeichnet das PDF selbst, Seite für Seite auf je ein Canvas.
     *
     * Vorher hing hier ein iframe auf die eigene Vorschau-Route. Das setzte voraus,
     * dass der Browser einen eingebauten PDF-Betrachter für eingebettete Rahmen
     * mitbringt - Chrome auf Android tut das nicht, dort blieb der Rahmen leer. Der
     * Hinweistext darunter machte den leeren Rahmen erklärbar, aber nicht brauchbar.
     *
     * Mit pdf.js hängt die Vorschau nicht mehr am Browser. Nebenwirkung: die
     * Vorschau-Route muss nicht mehr eingebettet werden dürfen, weshalb ihre Ausnahme
     * vom Framing-Verbot in SecurityHeadersMiddleware entfallen ist.
     */
    function renderPdf(url) {
        const token = renderToken;

        const container = document.createElement('div');
        container.className = 'attachment-preview-pdf';
        body.appendChild(container);

        const status = document.createElement('p');
        status.className = 'text-muted small mt-2 mb-0';
        status.textContent = 'Vorschau wird geladen …';
        body.appendChild(status);

        loadPdfLibrary()
            .then(function (pdfjs) {
                return pdfjs.getDocument({
                    url: url,
                    // Ohne diese drei Pfade greift pdf.js auf seine eingebauten
                    // Vorgaben zurück, und die zeigen auf ein CDN.
                    standardFontDataUrl: PDFJS_BASE + 'standard_fonts/',
                    wasmUrl: PDFJS_BASE + 'wasm/',
                    iccUrl: PDFJS_BASE + 'iccs/',
                }).promise;
            })
            .then(function (pdf) {
                return renderPdfPages(pdf, container, status, token);
            })
            .catch(function () {
                if (token !== renderToken) {
                    return;
                }

                showMessage('Die Vorschau konnte nicht geladen werden. Bitte die Datei herunterladen.');
            });
    }

    /**
     * Zeichnet nacheinander, nicht parallel: jede Seite belegt für die Dauer des
     * Zeichnens eine Bitmap in Ansichtsgröße, und zehn davon gleichzeitig sind auf
     * einem Telefon der Punkt, an dem der Tab wegfliegt.
     */
    function renderPdfPages(pdf, container, status, token) {
        const pageCount = Math.min(pdf.numPages, PDF_PAGE_LIMIT);
        let chain = Promise.resolve();

        for (let number = 1; number <= pageCount; number++) {
            chain = chain.then(function () {
                if (token !== renderToken) {
                    return null;
                }

                return pdf.getPage(number).then(function (page) {
                    return drawPdfPage(page, container);
                });
            });
        }

        return chain.then(function () {
            if (token !== renderToken) {
                return;
            }

            if (pdf.numPages > pageCount) {
                status.textContent = 'Vorschau auf die ersten ' + pageCount + ' Seiten gekürzt. '
                    + 'Die vollständige Datei steht im Download.';
                return;
            }

            status.textContent = pdf.numPages === 1 ? '1 Seite' : pdf.numPages + ' Seiten';
        });
    }

    function drawPdfPage(page, container) {
        const canvas = document.createElement('canvas');
        canvas.className = 'attachment-preview-pdf-page';

        const unscaled = page.getViewport({ scale: 1 });
        const availableWidth = container.clientWidth || unscaled.width;

        // Die Bitmap wird um den Geräte-Pixelwert größer als die Anzeigefläche, die
        // CSS-Breite bleibt bei 100 Prozent (.attachment-preview-pdf-page). Ohne das
        // ist die Seite auf einem Telefon-Display sichtbar unscharf.
        const pixelRatio = Math.min(window.devicePixelRatio || 1, PDF_MAX_PIXEL_RATIO);
        const viewport = page.getViewport({ scale: (availableWidth / unscaled.width) * pixelRatio });

        canvas.width = Math.floor(viewport.width);
        canvas.height = Math.floor(viewport.height);
        container.appendChild(canvas);

        return page.render({ canvasContext: canvas.getContext('2d'), viewport: viewport }).promise;
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
            renderPdf(previewUrl);
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
