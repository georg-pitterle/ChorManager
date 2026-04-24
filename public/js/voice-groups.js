document.addEventListener('DOMContentLoaded', function () {
    if (!window.bootstrap || !window.bootstrap.Modal) {
        return;
    }

    const scopeInput = document.getElementById('voiceGroupOpenModalScope');
    if (!scopeInput) {
        return;
    }

    const scope = scopeInput.value || '';
    const groupId = document.getElementById('voiceGroupOpenModalGroupId')?.value || '';
    const subId = document.getElementById('voiceGroupOpenModalSubId')?.value || '';

    let modalId = '';
    if (scope === 'create_group') {
        modalId = 'createGroupModal';
    } else if (scope === 'edit_group' && groupId !== '') {
        modalId = 'editGroupModal' + groupId;
    } else if (scope === 'create_sub' && groupId !== '') {
        modalId = 'createSubVoiceModal' + groupId;
    } else if (scope === 'edit_sub' && subId !== '') {
        modalId = 'editSubVoiceModal' + subId;
    }

    if (modalId === '') {
        return;
    }

    const modalElement = document.getElementById(modalId);
    if (!modalElement) {
        return;
    }

    window.bootstrap.Modal.getOrCreateInstance(modalElement).show();
});
