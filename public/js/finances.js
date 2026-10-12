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

/** Schlüssel für das zuletzt gebuchte Konto (nur Bequemlichkeit, pro Browser). */
const LAST_ACCOUNT_KEY = 'finances.lastAccountId';

/**
 * Heutiges Datum im Formularformat, nach der Uhr des Geräts.
 *
 * Nicht toISOString(): das rechnet in UTC, und kurz nach Mitternacht stand
 * dadurch in Österreich der Vortag im Feld - im Extremfall im alten
 * Geschäftsjahr.
 */
function localIsoDate(date = new Date()) {
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${date.getFullYear()}-${month}-${day}`;
}

const euroFormat = new Intl.NumberFormat('de-AT', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

/** Betrag mit echtem Minuszeichen, wie ihn das Template ausgibt. */
function formatSignedEuro(value, forceSign = false) {
    const sign = value < 0 ? '−' : (forceSign && value > 0 ? '+' : '');

    return `${sign}${euroFormat.format(Math.abs(value))} €`;
}

function readLastAccountId() {
    try {
        return window.localStorage.getItem(LAST_ACCOUNT_KEY);
    } catch (_error) {
        return null;
    }
}

function rememberLastAccountId(value) {
    try {
        window.localStorage.setItem(LAST_ACCOUNT_KEY, value);
    } catch (_error) {
        // Ohne Speicher beginnt der Dialog eben mit dem ersten Konto.
    }
}

function setBookingType(type) {
    const radio = document.getElementById(type === 'income' ? 'type_income' : 'type_expense');
    if (radio) radio.checked = true;
}

function setPaymentOpen(isOpen) {
    const open = document.getElementById('payment_open');
    if (open) open.checked = isOpen;
}

/** Die Meldung eines abgewiesenen Speicherns gehört nur zu genau diesem Versuch. */
function clearFormError() {
    document.querySelectorAll('[data-finance-form-error]').forEach(alert => alert.remove());
}

function setSaveNextVisible(visible) {
    const button = document.querySelector('[data-finance-save-next]');
    if (button) button.classList.toggle('d-none', !visible);
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

    // "Noch nicht bezahlt" und ein Zahldatum schließen einander aus: Wer ein Datum
    // einträgt, meint "bezahlt"; wer anhakt, meint "noch offen".
    const paymentOpen = document.getElementById('payment_open');
    const paymentDate = document.getElementById('payment_date');
    if (paymentOpen && paymentDate) {
        paymentOpen.addEventListener('change', function () {
            if (paymentOpen.checked) paymentDate.value = '';
        });
        paymentDate.addEventListener('input', function () {
            if (paymentDate.value) paymentOpen.checked = false;
        });
    }

    const form = document.getElementById('finance-form');
    const account = document.getElementById('finance_account_id');
    if (form && account) {
        form.addEventListener('submit', function () {
            rememberLastAccountId(account.value);
        });
    }

    openFinanceModalAfterReload();
    bindFinanceSum();
}

/**
 * Abgewiesene Eingaben oder "Speichern und nächste": Der Server hat den Dialog
 * schon befüllt, hier wird er nur geöffnet - ohne Zurücksetzen.
 */
function openFinanceModalAfterReload() {
    const modalElement = document.getElementById('financeModal');
    if (!modalElement || modalElement.getAttribute('data-open-on-load') !== '1') {
        return;
    }
    if (!window.bootstrap || !window.bootstrap.Modal) {
        return;
    }

    // Eine abgewiesene Bearbeitung: die Felder hat der Server befüllt, die
    // Belegliste entsteht wie beim ersten Öffnen hier im Browser.
    const reloadItem = modalElement.getAttribute('data-finance-reload-item');
    if (reloadItem) {
        try {
            renderExistingAttachments(JSON.parse(reloadItem).attachments || []);
        } catch (_error) {
            // Ohne Belegliste bleibt der Dialog trotzdem bedienbar.
        }
    }

    modalElement.addEventListener('shown.bs.modal', function () {
        const amount = document.getElementById('amount');
        const hasError = modalElement.querySelector('[data-finance-form-error]') !== null;
        // Nach einem Fehler bleibt der Blick auf der Meldung; sonst geht es mit dem
        // Betrag des nächsten Belegs weiter.
        if (amount && !hasError) amount.focus();
    }, { once: true });
    window.bootstrap.Modal.getOrCreateInstance(modalElement).show();
}

/**
 * Summe der Buchungen, die Suche und Filter der Tabellenleiste übrig lassen.
 * Die Tabellen-Engine meldet nach jedem Filtern die Treffer über
 * `chor-table:applied` (siehe public/js/table-engine.js).
 */
function bindFinanceSum() {
    const bar = document.querySelector('[data-finance-sum]');
    const table = document.getElementById('financesTable');
    const container = table ? table.closest('[data-table-engine="true"]') : null;
    if (!bar || !container) {
        return;
    }

    const bookingRows = rows => rows.filter(row => row.hasAttribute('data-finance-amount'));
    const render = function (rows) {
        let income = 0;
        let expense = 0;
        rows.forEach(function (row) {
            // In Cent: mit Gleitkomma bliebe bei Buchung plus Storno "−0,00 €" stehen.
            const cents = Math.round(Number.parseFloat(row.getAttribute('data-finance-amount') || '0') * 100);
            if (Number.isNaN(cents)) return;
            if (cents >= 0) income += cents; else expense += cents;
        });

        bar.querySelector('[data-finance-sum-income]').textContent = formatSignedEuro(income / 100, true);
        bar.querySelector('[data-finance-sum-expense]').textContent = formatSignedEuro(expense / 100);
        bar.querySelector('[data-finance-sum-total]').textContent = formatSignedEuro((income + expense) / 100, true);
        bar.hidden = rows.length === 0;
    };

    container.addEventListener('chor-table:applied', function (event) {
        render(bookingRows(event.detail.filteredRows));
    });

    if (container.chorTableLastApplied) {
        render(bookingRows(container.chorTableLastApplied.filteredRows));
    } else {
        render(Array.from(table.querySelectorAll('tbody tr[data-finance-amount]')));
    }
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

    clearFormError();
    setSaveNextVisible(true);

    // Bezahlt ist der Normalfall: beide Daten auf heute, ein offener Posten muss
    // ausdrücklich angehakt werden.
    const today = localIsoDate();
    const invoiceDate = document.getElementById('invoice_date');
    if (invoiceDate) invoiceDate.value = today;

    const paymentDate = document.getElementById('payment_date');
    if (paymentDate) paymentDate.value = today;
    setPaymentOpen(false);

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

    setBookingType('expense');

    const account = document.getElementById('finance_account_id');
    if (account) {
        const lastAccountId = readLastAccountId();
        const known = lastAccountId && Array.from(account.options).some(option => option.value === lastAccountId);
        if (known) {
            account.value = lastAccountId;
        } else {
            account.selectedIndex = 0;
        }
    }

    const amt = document.getElementById('amount');
    if (amt) amt.value = '';

    const att = document.getElementById('attachments');
    if (att) att.value = '';

    const attSection = document.getElementById('existing_attachments_section');
    if (attSection) attSection.classList.add('d-none');

    const attList = document.getElementById('existing_attachments_list');
    if (attList) attList.innerHTML = '';

    const label = document.getElementById('financeModalLabel');
    if (label) label.innerText = 'Neue Buchung';
}

function editFinance(item) {
    clearFormError();
    setSaveNextVisible(false);
    document.getElementById('finance_id').value = item.id;
    document.getElementById('invoice_date').value = normalizeFinanceDate(item.invoice_date);
    const paymentDate = normalizeFinanceDate(item.payment_date);
    document.getElementById('payment_date').value = paymentDate;
    setPaymentOpen(paymentDate === '');
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

    setBookingType(item.type);
    const accountSelect = document.getElementById('finance_account_id');
    if (accountSelect && item.finance_account_id) {
        accountSelect.value = String(item.finance_account_id);
    }
    document.getElementById('amount').value = euroFormat.format(Number.parseFloat(item.amount));

    document.getElementById('attachments').value = '';
    renderExistingAttachments(item.attachments || []);

    document.getElementById('financeModalLabel').innerText = 'Buchung ' + item.running_number + ' bearbeiten';

    if (window.bootstrap && window.bootstrap.Modal) {
        var myModal = window.bootstrap.Modal.getOrCreateInstance(document.getElementById('financeModal'));
        myModal.show();
    }
}

function renderExistingAttachments(attachments) {
    const attSection = document.getElementById('existing_attachments_section');
    const attList = document.getElementById('existing_attachments_list');
    if (!attSection || !attList) {
        return;
    }
    attList.innerHTML = '';

    if (attachments.length > 0) {
        attSection.classList.remove('d-none');
        attachments.forEach(att => {
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
                    <form action="/finances/attachments/${att.id}/delete" method="POST" class="m-0" data-confirm="Beleg „${escapeHtml(att.name)}“ wirklich löschen?">
                        <button type="submit" class="btn btn-sm btn-link text-danger p-0" title="Beleg löschen">
                            <i class="bi bi-x-circle" aria-hidden="true"></i>
                            <span class="visually-hidden">Beleg löschen</span>
                        </button>
                    </form>
                </div>
            `;
            attList.appendChild(div);
        });
    } else {
        attSection.classList.add('d-none');
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
