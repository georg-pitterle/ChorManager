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

    // Delegated so it also works inside the lazily-injected edit modal fragment.
    document.addEventListener('change', function (e) {
        var checkbox = e.target.closest('.vg-checkbox');
        if (!checkbox) {
            return;
        }
        var container = checkbox.closest('.border');
        if (!container) {
            return;
        }
        var selector = container.querySelector('.collapse-sv');
        if (!selector) {
            return;
        }
        if (checkbox.checked) {
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

    // Bulk archive selection must respect the active table filter: only members that
    // match the current filter may ever be submitted, and "select all" covers the whole
    // filtered set across pagination pages (not just the visible page). The table engine
    // exposes the filtered rows via the 'chor-table:applied' event and the
    // container.chorTableLastApplied snapshot.
    const selectAll = document.getElementById('selectAllUsers');
    const hidden = document.getElementById('bulkUserIds');
    const button = document.getElementById('bulkDeactivateButton');
    const bulkForm = document.getElementById('bulkDeactivateForm');
    const crossPageHint = document.getElementById('bulkCrossPageHint');
    const usersTable = document.getElementById('usersTable');
    const tableContainer = usersTable ? usersTable.closest('[data-table-engine]') : null;

    if (hidden && button) {
        const selectedIds = new Set();
        const baseConfirm = bulkForm ? (bulkForm.getAttribute('data-confirm') || '') : '';

        function enabledCheckbox(row) {
            const cb = row.querySelector('.user-row-select');
            return (cb && !cb.disabled) ? cb : null;
        }

        function currentFilteredRows() {
            if (tableContainer && tableContainer.chorTableLastApplied) {
                return tableContainer.chorTableLastApplied.filteredRows || [];
            }
            // Fallback before the engine has applied once: treat every row as filtered-in.
            return Array.from(document.querySelectorAll('#usersTable tbody tr'));
        }

        function updateCrossPageHint(count, offPageCount) {
            // Only warn when the selection actually reaches rows on other (hidden) pages,
            // not merely because the table happens to be paginated.
            const show = offPageCount > 0;

            if (crossPageHint) {
                crossPageHint.textContent = show
                    ? 'Achtung: Die Auswahl umfasst ' + count + ' Mitglieder – auch auf anderen Seiten.'
                    : '';
                crossPageHint.classList.toggle('d-none', !show);
            }

            if (bulkForm) {
                bulkForm.setAttribute(
                    'data-confirm',
                    show
                        ? count + ' Mitglieder (auch auf anderen Seiten) wirklich archivieren?'
                        : baseConfirm
                );
            }
        }

        function recompute() {
            const filteredCheckboxes = currentFilteredRows()
                .map(enabledCheckbox)
                .filter(function (cb) { return cb !== null; });
            const filteredIds = new Set(filteredCheckboxes.map(function (cb) { return cb.value; }));

            // Drop selections that no longer match the filter, so the submitted ids are
            // always a subset of the current filtered set.
            Array.from(selectedIds).forEach(function (id) {
                if (!filteredIds.has(id)) {
                    selectedIds.delete(id);
                }
            });

            // Reflect selection onto the checkboxes, including hidden rows on other pages.
            let offPageSelected = 0;
            filteredCheckboxes.forEach(function (cb) {
                cb.checked = selectedIds.has(cb.value);
                const row = cb.closest('tr');
                if (cb.checked && row && row.hidden) {
                    offPageSelected += 1;
                }
            });

            hidden.value = Array.from(selectedIds).join(',');
            button.disabled = selectedIds.size === 0;

            if (selectAll) {
                selectAll.checked = filteredCheckboxes.length > 0 && selectedIds.size === filteredCheckboxes.length;
                selectAll.indeterminate = selectedIds.size > 0 && selectedIds.size < filteredCheckboxes.length;
            }

            updateCrossPageHint(selectedIds.size, offPageSelected);
        }

        if (selectAll) {
            selectAll.addEventListener('change', function () {
                const filteredCheckboxes = currentFilteredRows()
                    .map(enabledCheckbox)
                    .filter(function (cb) { return cb !== null; });
                filteredCheckboxes.forEach(function (cb) {
                    if (selectAll.checked) {
                        selectedIds.add(cb.value);
                    } else {
                        selectedIds.delete(cb.value);
                    }
                });
                recompute();
            });
        }

        // Delegated: row checkboxes are re-ordered/paginated by the table engine.
        document.addEventListener('change', function (e) {
            const cb = e.target && e.target.closest ? e.target.closest('.user-row-select') : null;
            if (!cb || cb.disabled) {
                return;
            }
            if (cb.checked) {
                selectedIds.add(cb.value);
            } else {
                selectedIds.delete(cb.value);
            }
            recompute();
        });

        if (tableContainer) {
            tableContainer.addEventListener('chor-table:applied', recompute);
        }

        recompute();
    }

    // Auto-open the create modal when a validation error occurred.
    const addUserModal = document.getElementById('addUserModal');
    if (addUserModal && addUserModal.dataset.openCreateModal === '1' && window.bootstrap && window.bootstrap.Modal) {
        window.bootstrap.Modal.getOrCreateInstance(addUserModal).show();
    }

    // Single reusable edit modal: fetch the form fragment lazily instead of
    // rendering one full edit form per member into the /users response.
    const editShell = document.getElementById('editUserModal');
    if (editShell && window.bootstrap && window.bootstrap.Modal) {
        const dialog = editShell.querySelector('.modal-dialog');
        const loadingHtml = dialog ? dialog.innerHTML : '';
        const urlTemplate = editShell.dataset.editFormUrlTemplate || '/users/__ID__/edit-form';

        function openEditModal(userId) {
            if (!userId || !dialog) {
                return;
            }
            const instance = window.bootstrap.Modal.getOrCreateInstance(editShell);
            dialog.innerHTML = loadingHtml;
            instance.show();

            fetch(urlTemplate.replace('__ID__', encodeURIComponent(userId)), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
                .then(function (response) {
                    return response.text();
                })
                .then(function (html) {
                    dialog.innerHTML = html;
                })
                .catch(function () {
                    dialog.innerHTML = '<div class="modal-content"><div class="modal-body">'
                        + '<div class="alert alert-danger mb-0">Formular konnte nicht geladen werden.</div>'
                        + '</div></div>';
                });
        }

        document.addEventListener('click', function (e) {
            const btn = e.target.closest('.js-edit-user');
            if (!btn) {
                return;
            }
            e.preventDefault();
            openEditModal(btn.dataset.userId);
        });

        const autoOpenId = editShell.dataset.openEditUserId;
        if (autoOpenId) {
            openEditModal(autoOpenId);
        }
    }
});
