document.addEventListener('DOMContentLoaded', function () {
    function getCsrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') || '' : '';
    }

    function handleInviteClick(e) {
        var btn = e.target.closest('.js-invite-btn');
        if (!btn) {
            return;
        }

        if (btn.dataset.invitePending === '1') {
            return;
        }

        e.preventDefault();
        var url = btn.dataset.inviteUrl;
        if (!url) {
            return;
        }

        var csrfToken = getCsrfToken();
        var originalHtml = btn.dataset.inviteDefaultHtml || btn.innerHTML;
        btn.dataset.inviteDefaultHtml = originalHtml;
        btn.dataset.invitePending = '1';
        btn.disabled = true;
        btn.textContent = 'Wird gesendet…';

        fetch(url, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': csrfToken,
                'Accept': 'application/json'
            }
        })
            .then(function (response) {
                return response.text().then(function (text) {
                    try {
                        return JSON.parse(text);
                    } catch (_) {
                        return {
                            success: false,
                            message: text || 'Fehler beim Senden'
                        };
                    }
                });
            })
            .then(function (data) {
                if (data.success) {
                    btn.classList.remove('btn-outline-secondary');
                    btn.classList.remove('btn-outline-danger');
                    btn.classList.add('btn-outline-success');
                    btn.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i>Bereits gesendet - erneut senden';
                    btn.dataset.invitePending = '0';
                    btn.disabled = false;
                    return;
                }

                btn.classList.remove('btn-outline-secondary');
                btn.classList.remove('btn-outline-success');
                btn.classList.add('btn-outline-danger');
                btn.textContent = data.message || 'Fehler';
                btn.dataset.invitePending = '0';
                btn.disabled = false;
            })
            .catch(function () {
                btn.classList.remove('btn-outline-secondary');
                btn.classList.remove('btn-outline-success');
                btn.classList.add('btn-outline-danger');
                btn.textContent = 'Fehler beim Senden';
                btn.dataset.invitePending = '0';
                btn.disabled = false;
                btn.dataset.inviteDefaultHtml = originalHtml;
            });
    }

    document.addEventListener('click', handleInviteClick);

    document.querySelectorAll('.vg-checkbox').forEach(function (checkbox) {
        checkbox.addEventListener('change', function () {
            var container = this.closest('.border');
            if (!container) {
                return;
            }
            var selector = container.querySelector('.collapse-sv');
            if (!selector) {
                return;
            }
            if (this.checked) {
                selector.classList.remove('d-none');
                selector.style.display = 'block';
            } else {
                selector.classList.add('d-none');
                selector.style.display = 'none';
                var subVoiceSelect = selector.querySelector('select');
                if (subVoiceSelect) {
                    subVoiceSelect.value = '';
                }
            }
        });
    });

    const selectAll = document.getElementById('selectAllUsers');
    const hidden = document.getElementById('bulkUserIds');
    const button = document.getElementById('bulkDeactivateButton');
    const rowCheckboxes = Array.from(document.querySelectorAll('.user-row-select')).filter(function (cb) {
        return !cb.disabled;
    });

    function syncBulkSelection() {
        if (!hidden || !button) {
            return;
        }

        const selected = rowCheckboxes.filter(function (cb) {
            return cb.checked;
        }).map(function (cb) {
            return cb.value;
        });

        hidden.value = selected.join(',');
        button.disabled = selected.length === 0;

        if (selectAll) {
            selectAll.checked = rowCheckboxes.length > 0 && selected.length === rowCheckboxes.length;
            selectAll.indeterminate = selected.length > 0 && selected.length < rowCheckboxes.length;
        }
    }

    if (selectAll) {
        selectAll.addEventListener('change', function () {
            rowCheckboxes.forEach(function (cb) {
                cb.checked = selectAll.checked;
            });
            syncBulkSelection();
        });
    }

    rowCheckboxes.forEach(function (cb) {
        cb.addEventListener('change', syncBulkSelection);
    });

    syncBulkSelection();
});
