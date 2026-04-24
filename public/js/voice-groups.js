document.addEventListener('DOMContentLoaded', function () {
    if (!window.bootstrap || !window.bootstrap.Modal) {
        return;
    }

    // Auto-open create group modal
    const createGroupModal = document.getElementById('createGroupModal');
    if (createGroupModal && createGroupModal.dataset.openCreateGroupModal === '1') {
        window.bootstrap.Modal.getOrCreateInstance(createGroupModal).show();
        return;
    }

    // Auto-open edit group modals
    document.querySelectorAll('[id^="editGroupModal"][data-open-edit-group-modal="1"]').forEach(function (modal) {
        window.bootstrap.Modal.getOrCreateInstance(modal).show();
    });

    // Auto-open create sub-voice modals
    document.querySelectorAll('[id^="createSubVoiceModal"][data-open-create-sub-modal="1"]').forEach(function (modal) {
        window.bootstrap.Modal.getOrCreateInstance(modal).show();
    });

    // Auto-open edit sub-voice modals
    document.querySelectorAll('[id^="editSubVoiceModal"][data-open-edit-sub-modal="1"]').forEach(function (modal) {
        window.bootstrap.Modal.getOrCreateInstance(modal).show();
    });
});
