/**
 * Maskiert einen Wert für die Einbettung in HTML.
 *
 * Die Anhangsliste des Bearbeiten-Dialogs entsteht über innerHTML, und der
 * Dateiname stammt von der hochladenden Person. Vorher wurde er dort roh
 * eingesetzt - ein Name wie `"><img src=x onerror=...>` hätte im Dialog
 * ausgeführt. Der Apostroph ist mit drin, weil derselbe Helfer auch Werte für
 * Attribute liefert.
 */
function escapeHtml(value) {
    return String(value === null || value === undefined ? '' : value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function handleGroupSelect(sel) {
    const txt = document.getElementById('group_name');
    if (sel.value === '__new__') {
        txt.classList.remove('d-none');
        txt.required = true;
        txt.value = '';
        txt.focus();
    } else {
        txt.classList.add('d-none');
        txt.required = false;
        txt.value = sel.value;
    }
}

function bindFinanceUiHandlers() {
    const groupSelect = document.getElementById('group_select');
    if (groupSelect) {
        groupSelect.addEventListener('change', function () {
            handleGroupSelect(groupSelect);
        });
    }

    document.querySelectorAll('[data-action="reset-finance-modal"]').forEach(button => {
        button.addEventListener('click', function () {
            resetFinanceModal();
        });
    });

    document.addEventListener('click', handleEditFinanceClick);
}

function normalizeFinanceDate(value) {
    if (!value) {
        return '';
    }

    if (typeof value === 'string') {
        return value.split('T')[0].split(' ')[0];
    }

    if (typeof value === 'object' && typeof value.date === 'string') {
        return value.date.split('T')[0].split(' ')[0];
    }

    return '';
}

function handleEditFinanceClick(event) {
    const button = event.target.closest('[data-action="edit-finance"]');
    if (!button) {
        return;
    }

    const payload = button.getAttribute('data-finance-item') || '';
    if (!payload) {
        return;
    }

    try {
        editFinance(JSON.parse(payload));
    } catch (_error) {
        // Ignore malformed payloads silently.
    }
}

function resetFinanceModal() {
    const financeId = document.getElementById('finance_id');
    if (financeId) financeId.value = '';

    const invoiceDate = document.getElementById('invoice_date');
    if (invoiceDate) invoiceDate.value = new Date().toISOString().split('T')[0];

    const paymentDate = document.getElementById('payment_date');
    if (paymentDate) paymentDate.value = '';

    const desc = document.getElementById('description');
    if (desc) desc.value = '';

    const sel = document.getElementById('group_select');
    if (sel) sel.value = '';

    const txt = document.getElementById('group_name');
    if (txt) {
        txt.classList.add('d-none');
        txt.required = false;
        txt.value = '';
    }

    const type = document.getElementById('type');
    if (type) type.value = 'expense';

    const account = document.getElementById('finance_account_id');
    if (account) account.selectedIndex = 0;

    const amt = document.getElementById('amount');
    if (amt) amt.value = '';

    const att = document.getElementById('attachments');
    if (att) att.value = '';

    const attSection = document.getElementById('existing_attachments_section');
    if (attSection) attSection.classList.add('d-none');

    const attList = document.getElementById('existing_attachments_list');
    if (attList) attList.innerHTML = '';

    const label = document.getElementById('financeModalLabel');
    if (label) label.innerText = 'Neuer Eintrag';
}

function editFinance(item) {
    document.getElementById('finance_id').value = item.id;
    document.getElementById('invoice_date').value = normalizeFinanceDate(item.invoice_date);
    document.getElementById('payment_date').value = normalizeFinanceDate(item.payment_date);
    document.getElementById('description').value = item.description;

    const sel = document.getElementById('group_select');
    const txt = document.getElementById('group_name');
    const gVal = item.group_name || '';
    const opt = Array.from(sel.options).find(o => o.value === gVal && o.value !== '__new__');
    if (gVal && !opt) {
        sel.value = '__new__';
        txt.classList.remove('d-none');
        txt.required = true;
        txt.value = gVal;
    } else {
        sel.value = gVal;
        txt.classList.add('d-none');
        txt.required = false;
        txt.value = gVal;
    }

    document.getElementById('type').value = item.type;
    const accountSelect = document.getElementById('finance_account_id');
    if (accountSelect && item.finance_account_id) {
        accountSelect.value = String(item.finance_account_id);
    }
    document.getElementById('amount').value = parseFloat(item.amount).toLocaleString('de-DE', { minimumFractionDigits: 2 });

    // Attachments
    const attSection = document.getElementById('existing_attachments_section');
    const attList = document.getElementById('existing_attachments_list');
    attList.innerHTML = '';
    document.getElementById('attachments').value = '';

    if (item.attachments && item.attachments.length > 0) {
        attSection.classList.remove('d-none');
        item.attachments.forEach(att => {
            const div = document.createElement('div');
            div.className = 'list-group-item d-flex justify-content-between align-items-center py-2';
            // Dasselbe Knopfpaar wie der Baustein partials/attachment_actions.twig,
            // hier von Hand, weil diese Liste erst im Browser entsteht. Ob eine
            // Vorschau angeboten wird, hat der Server in `previewable` entschieden -
            // das Skript trifft die Entscheidung bewusst nicht selbst.
            const previewButton = att.previewable
                ? `<button type="button"
                           class="btn btn-outline-secondary"
                           data-attachment-preview
                           data-attachment-id="${att.id}"
                           data-attachment-name="${escapeHtml(att.name)}"
                           data-attachment-mime="${escapeHtml(att.mime)}"
                           data-attachment-size="${att.size}"
                           title="Vorschau">
                       <i class="bi bi-eye" aria-hidden="true"></i>
                       <span class="visually-hidden">Vorschau</span>
                   </button>`
                : '';

            div.innerHTML = `
                <div class="text-truncate finance-attachment-name">
                    <i class="bi bi-file-earmark-text me-1"></i>
                    <span class="small">${escapeHtml(att.name)}</span>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <div class="btn-group btn-group-sm attachment-actions" role="group">
                        ${previewButton}
                        <a class="btn btn-outline-primary" href="/attachments/${att.id}/download" title="Herunterladen">
                            <i class="bi bi-download" aria-hidden="true"></i>
                            <span class="visually-hidden">Herunterladen</span>
                        </a>
                    </div>
                    <form action="/finances/attachments/${att.id}/delete" method="POST" class="m-0" data-confirm="Anhang wirklich löschen?">
                        <button type="submit" class="btn btn-sm btn-link text-danger p-0">
                            <i class="bi bi-x-circle"></i>
                        </button>
                    </form>
                </div>
            `;
            attList.appendChild(div);
        });
    } else {
        attSection.classList.add('d-none');
    }

    document.getElementById('financeModalLabel').innerText = 'Eintrag ' + item.running_number + ' bearbeiten';

    if (window.bootstrap && window.bootstrap.Modal) {
        var myModal = window.bootstrap.Modal.getOrCreateInstance(document.getElementById('financeModal'));
        myModal.show();
    }
}

// Global exposure for potential onclick handlers if not yet refactored to addEventListener
var financeModalElement = document.getElementById('financeModal');
if (financeModalElement && window.bootstrap && window.bootstrap.Modal) {
    window.bootstrap.Modal.getOrCreateInstance(financeModalElement);
}

if (financeModalElement) {
    window.resetFinanceModal = resetFinanceModal;
    window.editFinance = editFinance;
}

document.addEventListener('DOMContentLoaded', bindFinanceUiHandlers);
