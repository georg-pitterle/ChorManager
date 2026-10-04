/**
 * Zielgruppen-Zeilen (Freigaben, Termine, Newsletter, Vorlagen): auf- und
 * zuklappen, "Alle Mitglieder" sperrt die Felder, Zusammenfassung und
 * Trefferzahl folgen der Auswahl, Zeilen hinzufügen und entfernen.
 *
 * Jede Änderung löst auf dem Container das Ereignis "audience:change" aus,
 * damit z. B. die Newsletter-Seite ihre Gesamtzahl nachziehen kann.
 */
(function () {
    'use strict';

    const csrfToken = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    let nextIndex = 0;

    function categoryOf(select) {
        return select.getAttribute('data-audience-condition') || '';
    }

    function rowPayload(row) {
        const data = new URLSearchParams();
        const all = row.querySelector('[data-audience-all]');
        if (all && all.checked) {
            data.append('all', '1');
        }
        row.querySelectorAll('[data-audience-condition]').forEach(function (select) {
            Array.prototype.forEach.call(select.selectedOptions, function (option) {
                data.append('conditions[' + categoryOf(select) + '][]', option.value);
            });
        });
        return data;
    }

    function setFieldsDisabled(row) {
        const all = row.querySelector('[data-audience-all]');
        const disabled = !!(all && all.checked);
        row.querySelectorAll('[data-audience-condition]').forEach(function (select) {
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
     * AudienceDescriber::summarize: "Rolle: A, B · Stimmgruppe: Tenor".
     */
    function summaryOf(row) {
        const all = row.querySelector('[data-audience-all]');
        if (all && all.checked) {
            return 'Alle Mitglieder';
        }
        const parts = [];
        row.querySelectorAll('[data-audience-condition]').forEach(function (select) {
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
        const target = row.querySelector('[data-audience-summary-text]');
        if (target) {
            target.textContent = summaryOf(row);
        }
    }

    function announce(element) {
        const container = element.closest('[data-audience-rows]') || element;
        container.dispatchEvent(new CustomEvent('audience:change', { bubbles: true }));
    }

    function updateEmptyHint(container) {
        const hint = container.querySelector('[data-audience-empty-hint]');
        if (hint) {
            hint.hidden = container.querySelectorAll('[data-audience-row-list] [data-audience-row]').length > 0;
        }
    }

    function bind(row) {
        if (row.dataset.audienceBound === '1') {
            return;
        }
        row.dataset.audienceBound = '1';

        let timer = null;
        const counter = row.querySelector('[data-audience-count]');

        function refresh() {
            window.clearTimeout(timer);
            timer = window.setTimeout(function () {
                fetch('/audience-preview', {
                    method: 'POST',
                    headers: { 'X-CSRF-Token': csrfToken, Accept: 'application/json' },
                    body: rowPayload(row),
                })
                    .then(function (response) {
                        return response.json();
                    })
                    .then(function (data) {
                        const ok = !!(data && data.ok);
                        row.classList.toggle('audience-row--empty', ok && data.count === 0);
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

        const toggle = row.querySelector('[data-audience-toggle]');
        const editor = row.querySelector('[data-audience-editor]');
        if (toggle && editor) {
            toggle.addEventListener('click', function () {
                editor.hidden = !editor.hidden;
                toggle.setAttribute('aria-expanded', editor.hidden ? 'false' : 'true');
            });
        }
        row.addEventListener('change', function (event) {
            if (event.target.matches('[data-audience-all]')) {
                setFieldsDisabled(row);
            }
            updateSummary(row);
            refresh();
            announce(row);
        });
        setFieldsDisabled(row);
        refresh();
    }

    function applyConditions(row, conditions) {
        const all = row.querySelector('[data-audience-all]');
        if (all) {
            all.checked = Object.keys(conditions).length === 0;
        }
        row.querySelectorAll('[data-audience-condition]').forEach(function (select) {
            const wanted = Array.isArray(conditions[categoryOf(select)])
                ? conditions[categoryOf(select)].map(String)
                : [];
            Array.prototype.forEach.call(select.options, function (option) {
                option.selected = wanted.indexOf(option.value) !== -1;
            });
            // Werte ohne Option (beendetes Projekt, inaktives Mitglied, gelöschter
            // Eintrag) bleiben gewählt. Fielen sie weg, würde die Zeile still weiter.
            wanted.forEach(function (value) {
                const known = Array.prototype.some.call(select.options, function (option) {
                    return option.value === value;
                });
                if (!known) {
                    select.add(new Option('nicht mehr wählbarer Eintrag', value, true, true));
                }
            });
        });
    }

    /**
     * Neue Zeile aus dem <template>; mit conditions vorbelegt (z. B. aus einer
     * Newsletter-Vorlage), sonst leer und aufgeklappt.
     */
    function addRow(container, conditions) {
        const template = container.querySelector('template[data-audience-row-template]');
        const list = container.querySelector('[data-audience-row-list]');
        if (!template || !list) {
            return null;
        }
        const row = template.content.firstElementChild.cloneNode(true);
        const index = 'n' + nextIndex;
        nextIndex += 1;
        ['name', 'id', 'for'].forEach(function (attribute) {
            row.querySelectorAll('[' + attribute + ']').forEach(function (field) {
                field.setAttribute(attribute, field.getAttribute(attribute).split('__INDEX__').join(index));
            });
        });
        if (conditions) {
            applyConditions(row, conditions);
        }
        list.appendChild(row);
        if (window.initTomSelects) {
            window.initTomSelects(row);
        }
        bind(row);
        updateSummary(row);
        updateEmptyHint(container);
        announce(container);
        return row;
    }

    /** Ersetzt alle Zeilen, z. B. beim Übernehmen einer Newsletter-Vorlage. */
    function setRows(container, sets) {
        container.querySelectorAll('[data-audience-row-list] [data-audience-row]').forEach(function (row) {
            row.remove();
        });
        (sets || []).forEach(function (conditions) {
            addRow(container, conditions);
        });
        updateEmptyHint(container);
        announce(container);
    }

    function setupContainer(container) {
        if (container.dataset.audienceContainerBound === '1') {
            return;
        }
        container.dataset.audienceContainerBound = '1';
        const add = container.querySelector('[data-audience-add]');
        if (add) {
            add.addEventListener('click', function () {
                addRow(container, null);
            });
        }
        container.addEventListener('click', function (event) {
            const remove = event.target.closest('[data-audience-remove]');
            if (remove && container.contains(remove)) {
                remove.closest('[data-audience-row]').remove();
                updateEmptyHint(container);
                announce(container);
            }
        });
        container.querySelectorAll('[data-audience-row-list] [data-audience-row]').forEach(bind);
    }

    function setupAll(root) {
        root.querySelectorAll('[data-audience-rows]').forEach(setupContainer);
    }

    // setupAll ist für nachgeladene Inhalte gedacht (z. B. Dialoge, deren
    // Markup erst nach dem Laden dieses Skripts eingefügt wird).
    window.AudienceFilter = { setRows: setRows, addRow: addRow, bind: bind, setupAll: setupAll };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            setupAll(document);
        });
    } else {
        setupAll(document);
    }

    // In Modals baut TomSelect die Felder erst beim Öffnen auf; danach müssen
    // die Felder erneut gesperrt werden.
    document.addEventListener('shown.bs.modal', function (event) {
        setupAll(event.target);
        event.target.querySelectorAll('[data-audience-row]').forEach(function (row) {
            bind(row);
            setFieldsDisabled(row);
        });
    });
})();
