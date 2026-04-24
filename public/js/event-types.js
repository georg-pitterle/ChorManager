document.addEventListener('DOMContentLoaded', function () {
    if (!window.bootstrap || !window.bootstrap.Modal) {
        return;
    }

    // Auto-open create modal if validation error occurred
    const createModal = document.getElementById('createTypeModal');
    if (createModal && createModal.dataset.openCreateModal === '1') {
        window.bootstrap.Modal.getOrCreateInstance(createModal).show();
        return;
    }

    // Auto-open edit modals if validation error occurred
    const editModals = document.querySelectorAll('[id^="editTypeModal"][data-open-edit-modal="1"]');
    if (window.bootstrap && window.bootstrap.Modal) {
        editModals.forEach(function (modal) {
            window.bootstrap.Modal.getOrCreateInstance(modal).show();
        });
    }
});
