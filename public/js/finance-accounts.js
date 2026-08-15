function resetAccountModal() {
    const form = document.getElementById('finance-account-form');
    if (!form) {
        return;
    }

    form.reset();
    document.getElementById('account_id').value = '';
    document.getElementById('account_opening_balance').value = '0,00';
    document.getElementById('account_opening_date').value = new Date().toISOString().slice(0, 10);
    document.getElementById('account_is_active').checked = true;

    const title = document.getElementById('finance-account-modal-title');
    if (title) {
        title.textContent = 'Konto anlegen';
    }
}

function fillAccountModal(payload) {
    document.getElementById('account_id').value = payload.id ?? '';
    document.getElementById('account_name').value = payload.name ?? '';
    document.getElementById('account_type').value = payload.type ?? 'cash';
    document.getElementById('account_iban').value = payload.iban ?? '';
    document.getElementById('account_opening_balance').value = String(payload.opening_balance ?? '0').replace('.', ',');
    document.getElementById('account_opening_date').value = payload.opening_date ?? '';
    document.getElementById('account_sort_order').value = payload.sort_order ?? 0;
    document.getElementById('account_is_active').checked = Boolean(payload.is_active);

    const title = document.getElementById('finance-account-modal-title');
    if (title) {
        title.textContent = 'Konto bearbeiten';
    }
}

function bindFinanceAccountHandlers() {
    document.querySelectorAll('[data-action="reset-account-modal"]').forEach(button => {
        button.addEventListener('click', resetAccountModal);
    });

    document.querySelectorAll('[data-account-item]').forEach(button => {
        button.addEventListener('click', function () {
            try {
                fillAccountModal(JSON.parse(button.getAttribute('data-account-item')));
            } catch (error) {
                resetAccountModal();
            }
        });
    });
}

document.addEventListener('DOMContentLoaded', bindFinanceAccountHandlers);
