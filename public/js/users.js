document.addEventListener('DOMContentLoaded', function () {
    function getCsrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') || '' : '';
    }

    // Knopf oder Menüeintrag: Nur ein Knopf bekommt die Konturfarbe. Ein Eintrag im
    // Zeilenmenü sähe mit Knopfklassen wie ein Fremdkörper aus; dort zählt der Text.
    function setTone(btn, tone) {
        if (btn.classList.contains('dropdown-item')) {
            btn.classList.toggle('text-success', tone === 'success');
            btn.classList.toggle('text-danger', tone === 'danger');
            return;
        }
        btn.classList.remove('btn-outline-secondary', 'btn-outline-success', 'btn-outline-danger');
        btn.classList.add('btn-outline-' + tone);
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

        // Eine erneute Einladung entwertet den alten Link. Nur dann fragen; eine
        // erste Einladung geht ohne Umweg hinaus.
        if (btn.dataset.inviteConfirm && !window.confirm(btn.dataset.inviteConfirm)) {
            return;
        }

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
                    setTone(btn, 'success');
                    btn.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i>Bereits gesendet - erneut senden';
                    btn.dataset.invitePending = '0';
                    btn.disabled = false;
                    return;
                }

                setTone(btn, 'danger');
                btn.textContent = data.message || 'Fehler';
                btn.dataset.invitePending = '0';
                btn.disabled = false;
            })
            .catch(function () {
                setTone(btn, 'danger');
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
        var container = checkbox.closest('.voice-group-choice');
        if (!container) {
            return;
        }
        var selector = container.querySelector('.collapse-sv');
        if (!selector) {
            return;
        }
        if (checkbox.checked) {
            selector.classList.remove('d-none');
        } else {
            selector.classList.add('d-none');
            var subVoiceSelect = selector.querySelector('select');
            if (subVoiceSelect) {
                subVoiceSelect.value = '';
            }
        }
    });

    // Bulk selection must respect the active table filter: only members that match
    // the current filter may ever be submitted, and "select all" covers the whole
    // filtered set across pagination pages (not just the visible page). The table engine
    // exposes the filtered rows via the 'chor-table:applied' event and the
    // container.chorTableLastApplied snapshot.
    //
    // Die Sammelaktionen stehen in einer eigenen Leiste, die erst mit der Auswahl
    // erscheint. Beide Formulare (Einladen, Archivieren) tragen dieselben Kennungen.
    const selectAll = document.getElementById('selectAllUsers');
    const hidden = document.getElementById('bulkUserIds');
    const button = document.getElementById('bulkDeactivateButton');
    const bulkForm = document.getElementById('bulkDeactivateForm');
    const inviteForm = document.getElementById('bulkInviteForm');
    const inviteButton = inviteForm ? inviteForm.querySelector('button[type="submit"]') : null;
    const selectionBar = document.getElementById('userSelectionBar');
    const selectionCount = document.getElementById('userSelectionCount');
    const clearSelection = document.getElementById('clearUserSelection');
    const crossPageHint = document.getElementById('bulkCrossPageHint');
    const usersTable = document.getElementById('usersTable');
    const tableContainer = usersTable ? usersTable.closest('[data-table-engine]') : null;

    if (hidden && button) {
        const selectedIds = new Set();
        const idInputs = Array.from(document.querySelectorAll('.js-bulk-user-ids'));

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

        function updateSelectionBar(count, offPageCount, notArchivable, alreadyInvited, usesApp) {
            // Only mention other pages when the selection actually reaches hidden rows,
            // not merely because the table happens to be paginated.
            const offPage = offPageCount > 0;

            if (selectionBar) {
                selectionBar.classList.toggle('d-none', count === 0);
            }
            if (selectionCount) {
                selectionCount.textContent = count + ' ausgewählt';
            }
            if (crossPageHint) {
                crossPageHint.textContent = offPage
                    ? '– davon ' + offPageCount + ' auf anderen Seiten'
                    : '';
                crossPageHint.classList.toggle('d-none', !offPage);
            }

            // Die Rückfragen nennen vorher, was nachher passiert (users-bulk-confirm.js).
            const texts = window.ChorUsersBulk
                ? window.ChorUsersBulk.confirmTexts({
                    count: count,
                    offPage: offPageCount,
                    notArchivable: notArchivable,
                    alreadyInvited: alreadyInvited,
                    usesApp: usesApp
                })
                : null;
            if (bulkForm && texts) {
                bulkForm.setAttribute('data-confirm', texts.archive);
            }
            if (inviteForm && texts) {
                inviteForm.setAttribute('data-confirm', texts.invite);
            }

            // Trifft eine Aktion niemanden - drei ausgewählt, alle drei nutzen die App
            // schon -, ist sie gesperrt statt eine Rückfrage zu einer leeren Aktion.
            button.disabled = !texts || texts.archivable === 0;
            button.title = button.disabled && count > 0
                ? 'Keins der ausgewählten Mitglieder lässt sich archivieren.'
                : '';
            if (inviteButton) {
                inviteButton.disabled = !texts || texts.invitable === 0;
                inviteButton.title = inviteButton.disabled && count > 0
                    ? 'Alle ausgewählten Mitglieder nutzen die App schon.'
                    : '';
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
            let notArchivable = 0;
            let alreadyInvited = 0;
            let usesApp = 0;
            filteredCheckboxes.forEach(function (cb) {
                cb.checked = selectedIds.has(cb.value);
                const row = cb.closest('tr');
                if (!cb.checked || !row) {
                    return;
                }
                if (row.hidden) {
                    offPageSelected += 1;
                }
                if (row.dataset.archivable !== '1') {
                    notArchivable += 1;
                }
                // Wer die App schon nutzt, überspringt der Server; die übrigen
                // mit früherer Einladung bekommen einen neuen Link.
                if (row.dataset.usesApp === '1') {
                    usesApp += 1;
                } else if ((row.dataset.invitation || '').indexOf('|sent|') !== -1) {
                    alreadyInvited += 1;
                }
            });

            const value = Array.from(selectedIds).join(',');
            idInputs.forEach(function (input) { input.value = value; });

            if (selectAll) {
                selectAll.checked = filteredCheckboxes.length > 0 && selectedIds.size === filteredCheckboxes.length;
                selectAll.indeterminate = selectedIds.size > 0 && selectedIds.size < filteredCheckboxes.length;
            }

            updateSelectionBar(selectedIds.size, offPageSelected, notArchivable, alreadyInvited, usesApp);
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

        if (clearSelection) {
            clearSelection.addEventListener('click', function () {
                selectedIds.clear();
                recompute();
            });
        }

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
