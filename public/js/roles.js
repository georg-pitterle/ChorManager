function syncFinancePermissionPair(readCheckbox, writeCheckbox) {
    if (!readCheckbox || !writeCheckbox) {
        return;
    }

    writeCheckbox.addEventListener('change', function () {
        if (writeCheckbox.checked) {
            readCheckbox.checked = true;
        }
    });

    readCheckbox.addEventListener('change', function () {
        if (!readCheckbox.checked) {
            writeCheckbox.checked = false;
        }
    });
}

document.addEventListener('DOMContentLoaded', function () {
    const editButtons = document.querySelectorAll('.edit-role-btn');
    const editForm = document.getElementById('editRoleForm');

    syncFinancePermissionPair(
        document.getElementById('can_read_finances'),
        document.getElementById('can_manage_finances')
    );
    syncFinancePermissionPair(
        document.getElementById('edit_can_read_finances'),
        document.getElementById('edit_can_manage_finances')
    );

    if (editForm) {
        editButtons.forEach(button => {
            button.addEventListener('click', function () {
                const id = this.getAttribute('data-id');
                editForm.action = '/roles/' + id;

                document.getElementById('edit_name').value = this.getAttribute('data-name');
                document.getElementById('edit_hierarchy_level').value = this.getAttribute('data-level');
                document.getElementById('edit_can_manage_users').checked = this.getAttribute('data-manage') === '1';
                document.getElementById('edit_can_edit_users').checked = this.getAttribute('data-edit') === '1';
                document.getElementById('edit_can_manage_attendance').checked = this.getAttribute('data-attendance') === '1';
                document.getElementById('edit_can_manage_project_members').checked = this.getAttribute('data-project-members') === '1';
                document.getElementById('edit_can_read_finances').checked = this.getAttribute('data-finance-read') === '1';
                document.getElementById('edit_can_manage_finances').checked = this.getAttribute('data-finances') === '1';
                document.getElementById('edit_can_manage_master_data').checked = this.getAttribute('data-master-data') === '1';
                document.getElementById('edit_can_manage_sponsoring').checked = this.getAttribute('data-sponsoring') === '1';
                document.getElementById('edit_can_manage_song_library').checked = this.getAttribute('data-song-library') === '1';
                document.getElementById('edit_can_manage_newsletters').checked = this.getAttribute('data-newsletters') === '1';
                document.getElementById('edit_can_manage_mail_queue').checked = this.getAttribute('data-mail-queue') === '1';
                document.getElementById('edit_can_manage_sheet_archive').checked = this.getAttribute('data-sheet-archive') === '1';
                document.getElementById('edit_can_manage_budget').checked = this.getAttribute('data-budget') === '1';
                document.getElementById('edit_can_manage_tasks').checked = this.getAttribute('data-tasks') === '1';
                document.getElementById('edit_can_manage_backups').checked = this.getAttribute('data-backups') === '1';
            });
        });
    }
});
