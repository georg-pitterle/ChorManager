/**
 * Ablegefläche für Datei-Uploads (templates/macros/upload.twig).
 *
 * Jede Datei geht in einer eigenen Anfrage hoch. So bekommt jede ihren eigenen
 * Fortschritt und ihre eigene Fehlermeldung, eine zu große Datei bricht nicht den
 * Rest ab, und die Grenze von PHP für Dateien je Anfrage (max_file_uploads) spielt
 * keine Rolle. Höchstens drei laufen gleichzeitig: 24 parallele Anfragen teilen
 * sich die Leitung ohnehin, und am Handy brach dann gern alles zugleich ab.
 *
 * Grenzen und Bildverkleinerung kommen aus upload-helper.js, damit Formular und
 * Ablegefläche dieselben Regeln anwenden.
 */
(function () {
    'use strict';

    const MAX_PARALLEL = 3;
    const RELOAD_DELAY_MS = 900;

    function csrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.content : '';
    }

    function formatSize(bytes) {
        const value = Number(bytes) || 0;
        if (value >= 1048576) {
            return (value / 1048576).toLocaleString('de-AT', { maximumFractionDigits: 1 }) + ' MB';
        }
        if (value >= 1024) {
            return Math.round(value / 1024).toLocaleString('de-AT') + ' KB';
        }
        return value + ' B';
    }

    /**
     * Arbeitet Einträge ab, höchstens `limit` gleichzeitig.
     * @param {number} limit
     * @param {function(*): Promise} worker
     */
    function createQueue(limit, worker) {
        const waiting = [];
        let running = 0;

        function next() {
            while (running < limit && waiting.length > 0) {
                const item = waiting.shift();
                running += 1;
                Promise.resolve()
                    .then(function () {
                        return worker(item);
                    })
                    .catch(function () {
                        // Der Worker meldet Fehler selbst an der Datei.
                    })
                    .then(function () {
                        running -= 1;
                        next();
                    });
            }
        }

        return {
            add: function (item) {
                waiting.push(item);
                next();
            },
        };
    }

    /**
     * Was nach dem letzten Upload passiert. Neu geladen wird nur, wenn alles
     * angekommen ist - sonst verschwänden die Fehlermeldungen mit dem Neuladen.
     * @param {{succeeded: number, failed: number}} counts
     * @returns {{action: string, text: string}}
     */
    function summarize(counts) {
        const total = counts.succeeded + counts.failed;
        if (counts.failed === 0) {
            return { action: 'reload', text: '' };
        }
        if (counts.succeeded === 0) {
            return { action: 'stay', text: '' };
        }
        return {
            action: 'offer',
            text: counts.failed + ' von ' + total + ' Dateien nicht hochgeladen.',
        };
    }

    /**
     * csrf-token-sync.js hält Seite und fetch-Aufrufe auf dem Token des Servers,
     * erreicht aber kein XMLHttpRequest. Ohne diese Übernahme scheiterte nach
     * einer Remember-Me-Erneuerung jeder weitere Upload bis zum Neuladen.
     */
    function adoptToken(request) {
        const token = request.getResponseHeader('X-CSRF-Token');
        if (token && window.ChorCsrf && typeof window.ChorCsrf.applyToken === 'function') {
            window.ChorCsrf.applyToken(document, token);
        }
    }

    function sendFile(url, field, file, onProgress, isRetry) {
        return new Promise(function (resolve) {
            const data = new FormData();
            data.append(field, file, file.name);

            const request = new XMLHttpRequest();
            request.open('POST', url);
            request.setRequestHeader('X-CSRF-Token', csrfToken());
            request.setRequestHeader('Accept', 'application/json');
            request.upload.addEventListener('progress', function (event) {
                if (event.lengthComputable) {
                    onProgress(Math.round((event.loaded / event.total) * 100));
                }
            });
            request.addEventListener('load', function () {
                adoptToken(request);
                // Die Sitzung wurde eben per Remember-Me neu aufgebaut, der Token
                // ist jetzt ein anderer: genau einmal mit dem neuen wiederholen.
                if (request.status === 403 && request.getResponseHeader('X-CSRF-Retry') === '1' && !isRetry) {
                    resolve(sendFile(url, field, file, onProgress, true));
                    return;
                }
                let payload = null;
                try {
                    payload = JSON.parse(request.responseText);
                } catch (error) {
                    payload = null;
                }
                if (request.status >= 200 && request.status < 300 && payload && payload.ok) {
                    resolve({ ok: true, message: payload.new_version ? 'Als neue Version gespeichert.' : 'Hochgeladen.' });
                    return;
                }
                const message = payload && payload.error
                    ? payload.error
                    : (request.status === 413 ? 'Die Datei ist zu groß.' : 'Hochladen fehlgeschlagen.');
                resolve({ ok: false, message: message });
            });
            ['error', 'abort', 'timeout'].forEach(function (type) {
                request.addEventListener(type, function () {
                    resolve({ ok: false, message: 'Verbindung unterbrochen.' });
                });
            });
            request.send(data);
        });
    }

    function setup(zone) {
        if (zone.dataset.uploadDropzoneBound === '1') {
            return;
        }
        zone.dataset.uploadDropzoneBound = '1';

        const input = zone.querySelector('[data-upload-input]');
        const list = zone.querySelector('[data-upload-list]');
        const summaryBox = zone.querySelector('[data-upload-summary]');
        const totalBox = zone.querySelector('[data-upload-total]');
        const url = zone.getAttribute('data-upload-url');
        const field = zone.getAttribute('data-upload-field') || 'attachments[]';
        const maxBytes = Number(zone.getAttribute('data-upload-max-bytes')) || 0;
        const shrinkImages = zone.hasAttribute('data-upload-shrink-images');
        const helper = window.uploadHelper || null;
        let pending = 0;
        let succeeded = 0;
        let failed = 0;

        zone.classList.add('is-enhanced');
        zone.querySelectorAll('[data-upload-fallback]').forEach(function (element) {
            element.hidden = true;
        });

        zone.addEventListener('submit', function (event) {
            event.preventDefault();
            if (input.files && input.files.length > 0) {
                uploadAll(input.files);
                input.value = '';
            }
        });

        input.addEventListener('change', function () {
            uploadAll(input.files);
            input.value = '';
        });

        ['dragenter', 'dragover'].forEach(function (type) {
            zone.addEventListener(type, function (event) {
                event.preventDefault();
                zone.classList.add('is-dragover');
            });
        });
        ['dragleave', 'drop'].forEach(function (type) {
            zone.addEventListener(type, function (event) {
                event.preventDefault();
                zone.classList.remove('is-dragover');
            });
        });
        zone.addEventListener('drop', function (event) {
            if (event.dataTransfer && event.dataTransfer.files) {
                uploadAll(event.dataTransfer.files);
            }
        });

        const queue = createQueue(MAX_PARALLEL, function (job) {
            return prepare(job.file).then(function (file) {
                if (file === null) {
                    finish(job.row, false, 'Auch nach dem Verkleinern noch zu groß.');
                    return null;
                }
                job.row.status.textContent = 'Wird hochgeladen …';
                return sendFile(url, field, file, function (percent) {
                    job.row.bar.style.setProperty('--progress-value', percent + '%');
                }).then(function (result) {
                    finish(job.row, result.ok, result.message);
                });
            }).catch(function () {
                // Ohne diesen Zweig bliebe die Zeile auf "Wird hochgeladen …"
                // stehen, und die Fläche käme nie zum Abschluss.
                finish(job.row, false, 'Hochladen fehlgeschlagen.');
            });
        });

        function uploadAll(files) {
            // Eine neue Auswahl ersetzt die Rückmeldungen der vorigen, sonst
            // stapeln sich nach mehreren Versuchen dieselben Meldungen. Läuft
            // noch etwas, bleiben dessen Zeilen stehen.
            if (pending === 0) {
                list.textContent = '';
                succeeded = 0;
                failed = 0;
                summaryBox.hidden = true;
                summaryBox.textContent = '';
            }
            // Erst alle zählen, dann prüfen: Eine sofort abgelehnte erste Datei
            // brächte `pending` sonst auf 0, bevor die übrigen überhaupt zählen.
            pending += files.length;
            showTotal();
            Array.prototype.forEach.call(files, function (file) {
                const row = addRow(file);
                const tooLarge = oversizeLimit(file, shrinkImages);
                if (tooLarge !== null) {
                    finish(row, false, 'Zu groß (' + formatSize(file.size) + ', höchstens ' + formatSize(tooLarge) + ').');
                    return;
                }
                row.status.textContent = 'Wartet …';
                queue.add({ file: file, row: row });
            });
        }

        /** Grenze, die die Datei überschreitet, oder null. */
        function oversizeLimit(file, compressImages) {
            if (!helper) {
                return maxBytes > 0 && file.size > maxBytes ? maxBytes : null;
            }
            const found = helper.findOversizedFiles([file], helper.readLimits(), {
                maxBytes: maxBytes,
                compressImages: compressImages,
            });
            return found.length > 0 ? found[0].limit : null;
        }

        /** Bild verkleinern, wo vorgesehen; null, wenn es danach noch zu groß ist. */
        function prepare(file) {
            if (!shrinkImages || !helper || !String(file.type || '').startsWith('image/')) {
                return Promise.resolve(file);
            }
            return helper.processFile(file).catch(function () {
                return file;
            }).then(function (processed) {
                return oversizeLimit(processed, false) === null ? processed : null;
            });
        }

        function addRow(file) {
            const item = document.createElement('li');
            item.className = 'upload-item';
            const line = document.createElement('div');
            line.className = 'upload-item-line small';
            const label = document.createElement('span');
            label.className = 'text-truncate';
            label.textContent = file.name + ' (' + formatSize(file.size) + ')';
            const status = document.createElement('span');
            status.className = 'upload-item-status text-muted';
            line.appendChild(label);
            line.appendChild(status);
            const progress = document.createElement('div');
            progress.className = 'progress upload-progress';
            const bar = document.createElement('div');
            bar.className = 'progress-bar';
            progress.appendChild(bar);
            item.appendChild(line);
            item.appendChild(progress);
            list.appendChild(item);
            return { item: item, bar: bar, status: status };
        }

        function showTotal() {
            const total = succeeded + failed + pending;
            const done = succeeded + failed;
            totalBox.hidden = total <= 1;
            totalBox.textContent = pending > 0
                ? done + ' von ' + total + ' Dateien fertig …'
                : succeeded + ' von ' + total + ' Dateien hochgeladen.';
        }

        function finish(row, ok, message) {
            row.bar.classList.add(ok ? 'bg-success' : 'bg-danger');
            row.bar.style.setProperty('--progress-value', '100%');
            row.status.textContent = message;
            // text-muted setzt seine Farbe mit !important und überstimmte text-danger.
            row.status.classList.toggle('text-muted', ok);
            row.status.classList.toggle('text-danger', !ok);
            pending -= 1;
            if (ok) {
                succeeded += 1;
            } else {
                failed += 1;
                // Fehler nach oben holen: Bei 24 Dateien läge er sonst
                // unterhalb des sichtbaren Ausschnitts der Liste.
                list.insertBefore(row.item, list.firstChild);
            }
            showTotal();
            if (pending === 0) {
                settle();
            }
        }

        function settle() {
            const outcome = summarize({ succeeded: succeeded, failed: failed });
            if (outcome.action === 'reload') {
                // Kurz stehen lassen, damit die Rückmeldung lesbar ist. Kommen in
                // der Zeit neue Dateien dazu, wartet das Neuladen auf sie.
                window.setTimeout(function () {
                    if (pending === 0) {
                        window.location.reload();
                    }
                }, RELOAD_DELAY_MS);
                return;
            }
            if (outcome.action === 'offer') {
                summaryBox.textContent = outcome.text + ' ';
                const reload = document.createElement('button');
                reload.type = 'button';
                reload.className = 'btn btn-link btn-sm p-0 align-baseline';
                reload.textContent = 'Liste aktualisieren';
                reload.addEventListener('click', function () {
                    window.location.reload();
                });
                summaryBox.appendChild(reload);
                summaryBox.hidden = false;
            }
        }
    }

    function setupAll() {
        document.querySelectorAll('[data-upload-dropzone]').forEach(setup);
    }

    window.uploadDropzone = {
        createQueue: createQueue,
        summarize: summarize,
        setup: setup,
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', setupAll);
    } else {
        setupAll();
    }
})();
