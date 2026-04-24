document.addEventListener('DOMContentLoaded', function () {
    if (!window.bootstrap || !window.bootstrap.Modal) {
        return;
    }

    const createModal = document.getElementById('createTypeModal');
    if (createModal && createModal.dataset.openCreateModal === '1') {
        window.bootstrap.Modal.getOrCreateInstance(createModal).show();
        return;
    }

    const openEditInput = document.getElementById('eventTypeOpenEditModalId');
    if (!openEditInput) {
        return;
    }

    const openEditId = parseInt(openEditInput.value || '0', 10);
    if (!Number.isInteger(openEditId) || openEditId <= 0) {
        return;
    }

    const editModal = document.getElementById('editTypeModal' + openEditId);
    if (editModal) {
        window.bootstrap.Modal.getOrCreateInstance(editModal).show();
    }
});
