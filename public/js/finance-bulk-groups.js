// Stapelbearbeitung der Gruppen in der Buchungsliste der Kasse.
//
// Ein Haken je Zeile, im Tabellenkopf "alle sichtbaren": Das sind die Zeilen,
// die Suche und Filter der Tabellenleiste übrig lassen - auch auf anderen
// Seiten der Tabelle, nicht nur die gerade gezeigten. Die Leiste erscheint erst
// mit der Auswahl; beim Absenden wandern die Kennungen als finance_ids[] ins
// Formular.
(function () {
    'use strict';

    const form = document.querySelector('[data-finance-bulk]');
    if (!form) {
        return;
    }

    const countLabel = form.querySelector('[data-finance-bulk-count]');
    const hint = form.querySelector('[data-finance-bulk-hint]');
    const groupSelect = form.querySelector('select[name="group_name"]');

    function rowBoxes() {
        return Array.from(document.querySelectorAll('input[data-finance-select]'));
    }

    function checkedIds() {
        return rowBoxes().filter((box) => box.checked).map((box) => box.value);
    }

    // Zeilen, die die Tabellenleiste nach Suche und Filter übrig lässt.
    function filteredRows(container) {
        const applied = container.chorTableLastApplied;
        if (applied && Array.isArray(applied.filteredRows)) {
            return applied.filteredRows;
        }
        return Array.from(container.querySelectorAll('tbody tr'));
    }

    function boxesIn(rows) {
        return rows
            .map((row) => row.querySelector('input[data-finance-select]'))
            .filter((box) => box !== null);
    }

    function syncSelectAll() {
        document.querySelectorAll('input[data-finance-select-all]').forEach((master) => {
            const container = master.closest('[data-table-engine]');
            const boxes = container ? boxesIn(filteredRows(container)) : [];
            const checked = boxes.filter((box) => box.checked).length;
            master.checked = boxes.length > 0 && checked === boxes.length;
            master.indeterminate = checked > 0 && checked < boxes.length;
        });
    }

    function sync() {
        const count = checkedIds().length;
        countLabel.textContent = count === 1 ? '1 Buchung ausgewählt' : count + ' Buchungen ausgewählt';
        form.classList.toggle('d-none', count === 0);
        if (count === 0 && hint) {
            hint.classList.add('d-none');
        }
        syncSelectAll();
    }

    document.addEventListener('change', (event) => {
        const target = event.target;
        if (!(target instanceof HTMLInputElement)) {
            return;
        }
        if (target.matches('[data-finance-select-all]')) {
            const container = target.closest('[data-table-engine]');
            if (container) {
                boxesIn(filteredRows(container)).forEach((box) => {
                    box.checked = target.checked;
                });
            }
            sync();
        } else if (target.matches('[data-finance-select]')) {
            sync();
        }
    });

    // Suche oder Filter geändert: Zeilen, die nicht mehr zu den Treffern gehören,
    // verlieren ihren Haken. Sonst ginge eine Änderung an Buchungen, die gerade
    // niemand sieht.
    document.querySelectorAll('[data-table-engine]').forEach((container) => {
        container.addEventListener('chor-table:applied', () => {
            const visible = new Set(filteredRows(container));
            container.querySelectorAll('tbody tr').forEach((row) => {
                const box = row.querySelector('input[data-finance-select]');
                if (box && box.checked && !visible.has(row)) {
                    box.checked = false;
                }
            });
            sync();
        });
    });

    form.querySelector('[data-finance-bulk-clear]').addEventListener('click', () => {
        rowBoxes().forEach((box) => {
            box.checked = false;
        });
        sync();
    });

    form.addEventListener('submit', (event) => {
        if (!groupSelect || groupSelect.value.trim() === '') {
            event.preventDefault();
            if (hint) {
                hint.classList.remove('d-none');
            }
            if (groupSelect && groupSelect.tomselect) {
                groupSelect.tomselect.focus();
            }
            return;
        }

        form.querySelectorAll('input[name="finance_ids[]"]').forEach((input) => input.remove());
        checkedIds().forEach((id) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'finance_ids[]';
            input.value = id;
            form.appendChild(input);
        });
    });

    if (groupSelect) {
        groupSelect.addEventListener('change', () => {
            if (hint && groupSelect.value.trim() !== '') {
                hint.classList.add('d-none');
            }
        });
    }

    sync();
})();
