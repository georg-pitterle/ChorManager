function collectImportRowCheckboxes() {
    return Array.from(document.querySelectorAll('.finance-import-row')).filter(box => !box.disabled);
}

function updateImportSubmitLabel() {
    const submit = document.getElementById('finance-import-submit');
    if (!submit) {
        return;
    }

    const selected = collectImportRowCheckboxes().filter(box => box.checked).length;
    const template = submit.dataset.labelTemplate || '%count% Zeilen übernehmen';
    const icon = submit.querySelector('i');

    submit.textContent = ' ' + template.replace('%count%', String(selected));
    if (icon) {
        submit.prepend(icon);
    }
    submit.disabled = selected === 0;
}

function syncSelectAllState() {
    const selectAll = document.getElementById('finance-import-select-all');
    if (!selectAll) {
        return;
    }

    const boxes = collectImportRowCheckboxes();
    const checked = boxes.filter(box => box.checked).length;

    selectAll.checked = boxes.length > 0 && checked === boxes.length;
    selectAll.indeterminate = checked > 0 && checked < boxes.length;
}

function bindFinanceImportHandlers() {
    const selectAll = document.getElementById('finance-import-select-all');
    if (selectAll) {
        selectAll.addEventListener('change', function () {
            collectImportRowCheckboxes().forEach(box => {
                box.checked = selectAll.checked;
            });
            selectAll.indeterminate = false;
            updateImportSubmitLabel();
        });
    }

    collectImportRowCheckboxes().forEach(box => {
        box.addEventListener('change', function () {
            syncSelectAllState();
            updateImportSubmitLabel();
        });
    });

    syncSelectAllState();
    updateImportSubmitLabel();
}

document.addEventListener('DOMContentLoaded', bindFinanceImportHandlers);
