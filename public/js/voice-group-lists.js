// Stimmgruppen-Listen: Häkchen und Notizen speichern ohne Neuladen.
//
// Wer 30 Mitglieder abhakt, soll nicht 30-mal neu laden und zurückscrollen. Die Zellen sind
// gewöhnliche Formulare (ohne JavaScript speichern sie mit Neuladen); dieses Skript schickt
// sie per fetch mit Accept: application/json ab und setzt die Zelle aus der Antwort.
// Geht etwas schief, bleibt der alte Stand stehen und die Zelle nennt den Grund - nichts
// wird still verworfen. Eine gerade abgehakte Zeile bleibt auch im Filter "nur offene"
// stehen, bis neu geladen wird, damit nichts unter dem Mauszeiger wegrutscht.
//
// Notizen speichern beim Verlassen des Feldes und mit Enter. Der Speichern-Knopf erscheint
// nur, solange etwas ungespeichert ist, und wer die Seite dann verlassen will, wird gewarnt.
(function (root) {
    'use strict';

    const FALLBACK_ERROR = 'Speichern fehlgeschlagen. Bitte erneut versuchen.';
    const SAVED_NOTICE = 'Gespeichert';

    function progressLabel(progress) {
        return progress.done + ' / ' + progress.total;
    }

    function progressPercent(progress) {
        return progress.total > 0 ? Math.round(progress.done * 100 / progress.total) : 0;
    }

    // Leerzeichen am Rand speichert der Server ohnehin nicht mit.
    function noteChanged(current, saved) {
        return String(current || '').trim() !== String(saved || '').trim();
    }

    function cellState(result, previous) {
        if (result && result.ok === true) {
            return {
                checked: result.checked === true,
                note: typeof result.note === 'string' ? result.note : null,
                tooltip: typeof result.tooltip === 'string' ? result.tooltip : null,
                error: null,
            };
        }

        const error = result && typeof result.error === 'string' && result.error !== '' ? result.error : FALLBACK_ERROR;

        return {
            checked: previous.checked,
            note: previous.note,
            tooltip: previous.tooltip,
            error: error,
        };
    }

    function savedNote(input) {
        return input.dataset.saved ?? input.defaultValue;
    }

    function readState(form) {
        const button = form.querySelector('button');
        if (form.dataset.vglEntry === 'checkbox') {
            const field = form.querySelector('input[name="checked"]');

            return { checked: field.value === '0', note: null, tooltip: button.title || null };
        }

        const input = form.querySelector('input[name="note"]');

        return { checked: false, note: savedNote(input), tooltip: input.title || null };
    }

    function flash(cell, className, ms) {
        cell.classList.remove(className);
        // Neu auslösen, auch wenn dieselbe Zelle zweimal kurz hintereinander speichert.
        void cell.offsetWidth;
        cell.classList.add(className);
        root.setTimeout(function () {
            cell.classList.remove(className);
        }, ms);
    }

    function applyState(form, state, succeeded) {
        const cell = form.closest('[data-vgl-cell]');
        const errorBox = cell ? cell.querySelector('[data-vgl-error]') : null;
        const statusBox = cell ? cell.querySelector('[data-vgl-status]') : null;
        const byBox = cell ? cell.querySelector('[data-vgl-by]') : null;
        if (errorBox) {
            errorBox.textContent = state.error || '';
        }
        if (byBox) {
            byBox.textContent = state.tooltip || '';
        }

        if (form.dataset.vglEntry === 'checkbox') {
            const button = form.querySelector('button');
            const icon = button.querySelector('i');
            form.querySelector('input[name="checked"]').value = state.checked ? '0' : '1';
            button.title = state.tooltip || '';
            button.setAttribute('aria-pressed', state.checked ? 'true' : 'false');
            icon.className = state.checked ? 'bi bi-check-square-fill text-success' : 'bi bi-square text-muted';
            if (succeeded && cell) {
                flash(cell, 'is-saved', 400);
            }

            return;
        }

        const input = form.querySelector('input[name="note"]');
        input.title = state.tooltip || '';
        // Bei einem Fehler bleibt der getippte Text im Feld stehen: Er ist nicht gespeichert,
        // die Meldung sagt das, und so lässt er sich ohne Neutippen erneut senden.
        if (succeeded) {
            input.value = state.note || '';
            input.dataset.saved = state.note || '';
            form.classList.remove('is-dirty');
            if (statusBox) {
                statusBox.textContent = SAVED_NOTICE;
                root.setTimeout(function () {
                    if (statusBox.textContent === SAVED_NOTICE) {
                        statusBox.textContent = '';
                    }
                }, 2000);
            }
        }
    }

    function updateProgress(doc, result) {
        if (!result || !result.ok || !result.progress) {
            return;
        }

        const id = result.column_id;
        doc.querySelectorAll('[data-vgl-progress="' + id + '"]').forEach(function (target) {
            target.textContent = progressLabel(result.progress);
        });
        doc.querySelectorAll('[data-vgl-progress-bar="' + id + '"]').forEach(function (bar) {
            const percent = progressPercent(result.progress);
            bar.style.setProperty('--progress-value', percent + '%');
            const track = bar.closest('[role="progressbar"]');
            if (track) {
                track.setAttribute('aria-valuenow', String(percent));
            }
        });
        doc.querySelectorAll('[data-vgl-done="' + id + '"]').forEach(function (marker) {
            marker.hidden = !(result.progress.total > 0 && result.progress.done === result.progress.total);
        });
    }

    function submitEntry(form, doc) {
        if (form.dataset.vglBusy === '1') {
            return;
        }

        if (form.dataset.vglEntry === 'note') {
            const input = form.querySelector('input[name="note"]');
            if (!noteChanged(input.value, savedNote(input))) {
                form.classList.remove('is-dirty');
                return;
            }
        }

        const previous = readState(form);
        const buttons = form.querySelectorAll('button');
        form.dataset.vglBusy = '1';
        buttons.forEach(function (button) {
            button.disabled = true;
        });

        root.fetch(form.action, {
            method: 'POST',
            body: new root.FormData(form),
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        })
            .then(function (response) {
                return response.json().catch(function () {
                    return null;
                });
            })
            .catch(function () {
                return null;
            })
            .then(function (result) {
                const state = cellState(result, previous);
                applyState(form, state, state.error === null);
                updateProgress(doc, result);
            })
            .finally(function () {
                delete form.dataset.vglBusy;
                buttons.forEach(function (button) {
                    button.disabled = false;
                });
            });
    }

    function noteForm(target) {
        return target instanceof HTMLInputElement && target.name === 'note'
            ? target.closest('form[data-vgl-entry="note"]')
            : null;
    }

    if (typeof document !== 'undefined') {
        document.documentElement.setAttribute('data-vgl-ready', '');

        document.addEventListener('submit', function (event) {
            const form = event.target;
            if (!(form instanceof HTMLFormElement) || !form.matches('form[data-vgl-entry]') || event.defaultPrevented) {
                return;
            }

            event.preventDefault();
            submitEntry(form, document);
        });

        document.addEventListener('input', function (event) {
            const form = noteForm(event.target);
            if (form) {
                form.classList.toggle('is-dirty', noteChanged(event.target.value, savedNote(event.target)));
            }
        });

        // Verlassen des Feldes speichert; Enter schickt das Formular ohnehin ab.
        document.addEventListener('change', function (event) {
            const form = noteForm(event.target);
            if (form) {
                submitEntry(form, document);
            }
        });

        root.addEventListener('beforeunload', function (event) {
            if (document.querySelector('form[data-vgl-entry="note"].is-dirty')) {
                event.preventDefault();
                event.returnValue = '';
            }
        });
    }

    root.ChorVoiceGroupLists = {
        progressLabel: progressLabel,
        progressPercent: progressPercent,
        noteChanged: noteChanged,
        cellState: cellState,
    };
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = {
            progressLabel: progressLabel,
            progressPercent: progressPercent,
            noteChanged: noteChanged,
            cellState: cellState,
        };
    }
})(typeof window !== 'undefined' ? window : globalThis);
