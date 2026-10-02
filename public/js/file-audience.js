/**
 * Freigabe-Zeilen mit Zielgruppen-Filter: auf- und zuklappen, "Alle
 * Mitglieder" sperrt die Felder, Trefferzahl live über /files/audience-preview.
 */
(function () {
    'use strict';

    const csrfToken = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

    function rowPayload(row) {
        const data = new URLSearchParams();
        const all = row.querySelector('[data-files-share-all]');
        if (all && all.checked) {
            data.append('all', '1');
        }
        row.querySelectorAll('[data-files-share-condition]').forEach(function (select) {
            const match = select.name.match(/\[conditions\]\[([a-z_]+)\]/);
            if (!match) {
                return;
            }
            Array.prototype.forEach.call(select.selectedOptions, function (option) {
                data.append('conditions[' + match[1] + '][]', option.value);
            });
        });
        return data;
    }

    function setFieldsDisabled(row) {
        const all = row.querySelector('[data-files-share-all]');
        const disabled = !!(all && all.checked);
        row.querySelectorAll('[data-files-share-condition]').forEach(function (select) {
            select.disabled = disabled;
            if (select.tomselect) {
                if (disabled) {
                    select.tomselect.disable();
                } else {
                    select.tomselect.enable();
                }
            }
        });
    }

    /**
     * Zusammenfassung aus der aktuellen Auswahl, im selben Wortlaut wie
     * FileShareDescriber::summarize: "Rolle: A, B · Stimmgruppe: Tenor".
     */
    function summaryOf(row) {
        const all = row.querySelector('[data-files-share-all]');
        if (all && all.checked) {
            return 'Alle Mitglieder';
        }
        const parts = [];
        row.querySelectorAll('[data-files-share-condition]').forEach(function (select) {
            const names = Array.prototype.map.call(select.selectedOptions, function (option) {
                return option.textContent.replace(/\s+/g, ' ').trim();
            });
            if (names.length === 0) {
                return;
            }
            const label = row.querySelector('label[for="' + select.id + '"]');
            parts.push((label ? label.textContent.trim() : '') + ': ' + names.join(', '));
        });
        return parts.length > 0 ? parts.join(' · ') : 'Keine Bedingung gewählt';
    }

    function updateSummary(row) {
        const target = row.querySelector('[data-files-share-summary-text]');
        if (target) {
            target.textContent = summaryOf(row);
        }
    }

    function bind(row) {
        if (row.dataset.filesAudienceBound === '1') {
            return;
        }
        row.dataset.filesAudienceBound = '1';

        let timer = null;
        const counter = row.querySelector('[data-files-share-count]');

        function refresh() {
            window.clearTimeout(timer);
            timer = window.setTimeout(function () {
                fetch('/files/audience-preview', {
                    method: 'POST',
                    headers: { 'X-CSRF-Token': csrfToken, Accept: 'application/json' },
                    body: rowPayload(row),
                })
                    .then(function (response) {
                        return response.json();
                    })
                    .then(function (data) {
                        const ok = !!(data && data.ok);
                        row.classList.toggle('files-share-row--empty', ok && data.count === 0);
                        if (ok) {
                            counter.textContent = 'trifft derzeit ' + data.count
                                + (data.count === 1 ? ' Mitglied' : ' Mitglieder');
                        } else {
                            counter.textContent = data && data.error ? data.error : '';
                        }
                    })
                    .catch(function () {
                        counter.textContent = '';
                    });
            }, 300);
        }

        const toggle = row.querySelector('[data-files-share-toggle]');
        const editor = row.querySelector('[data-files-share-editor]');
        if (toggle && editor) {
            toggle.addEventListener('click', function () {
                editor.hidden = !editor.hidden;
                toggle.setAttribute('aria-expanded', editor.hidden ? 'false' : 'true');
            });
        }
        row.addEventListener('change', function (event) {
            if (event.target.matches('[data-files-share-all]')) {
                setFieldsDisabled(row);
            }
            updateSummary(row);
            refresh();
        });
        setFieldsDisabled(row);
        refresh();
    }

    window.fileAudience = { bind: bind };

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-files-share-row]').forEach(bind);
    });

    // Im Ordner-Dialog stehen die Zeilen in einem Modal: TomSelect wird dort erst
    // beim Öffnen aufgebaut, die Felder müssen danach erneut gesperrt werden.
    document.addEventListener('shown.bs.modal', function (event) {
        event.target.querySelectorAll('[data-files-share-row]').forEach(function (row) {
            bind(row);
            setFieldsDisabled(row);
        });
    });
})();
