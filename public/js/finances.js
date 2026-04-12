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

    document.querySelectorAll('[data-action="edit-finance"]').forEach(button => {
        button.addEventListener('click', function () {
            const payload = button.getAttribute('data-finance-item') || '';
            if (!payload) {
                return;
            }

            try {
                editFinance(JSON.parse(payload));
            } catch (_error) {
                // Ignore malformed payloads silently.
            }
        });
    });
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

    const method = document.getElementById('payment_method');
    if (method) method.value = 'bank_transfer';

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
    document.getElementById('invoice_date').value = item.invoice_date.split('T')[0];
    document.getElementById('payment_date').value = item.payment_date ? item.payment_date.split('T')[0] : '';
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
    document.getElementById('payment_method').value = item.payment_method;
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
            div.innerHTML = `
                <div class="text-truncate" style="max-width: 80%;">
                    <i class="bi bi-file-earmark-text me-1"></i>
                    <a href="/finances/attachments/${att.id}" target="_blank" class="text-decoration-none small">${att.filename}</a>
                </div>
                <form action="/finances/attachments/${att.id}/delete" method="POST" class="m-0" data-confirm="Anhang wirklich löschen?">
                    <button type="submit" class="btn btn-sm btn-link text-danger p-0">
                        <i class="bi bi-x-circle"></i>
                    </button>
                </form>
            `;
            attList.appendChild(div);
        });
    } else {
        attSection.classList.add('d-none');
    }

    document.getElementById('financeModalLabel').innerText = 'Eintrag ' + item.running_number + ' bearbeiten';

    var myModal = new bootstrap.Modal(document.getElementById('financeModal'));
    myModal.show();
}

// Global exposure for potential onclick handlers if not yet refactored to addEventListener
window.handleGroupSelect = handleGroupSelect;
window.resetFinanceModal = resetFinanceModal;
window.editFinance = editFinance;

document.addEventListener('DOMContentLoaded', bindFinanceUiHandlers);
