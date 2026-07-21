// Feature-Flags koennen einzelne Checkboxen serverseitig ausblenden;
// dann darf die Zuweisung nicht auf ein fehlendes Element treffen.
function setCheckedIfPresent(id, checked) {
    const checkbox = document.getElementById(id);
    if (checkbox) {
        checkbox.checked = checked;
    }
}

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
                setCheckedIfPresent('edit_can_manage_users', this.getAttribute('data-manage') === '1');
                setCheckedIfPresent('edit_can_edit_users', this.getAttribute('data-edit') === '1');
                setCheckedIfPresent('edit_can_manage_attendance', this.getAttribute('data-attendance') === '1');
                setCheckedIfPresent('edit_can_manage_project_members', this.getAttribute('data-project-members') === '1');
                setCheckedIfPresent('edit_can_read_finances', this.getAttribute('data-finance-read') === '1');
                setCheckedIfPresent('edit_can_manage_finances', this.getAttribute('data-finances') === '1');
                setCheckedIfPresent('edit_can_manage_master_data', this.getAttribute('data-master-data') === '1');
                setCheckedIfPresent('edit_can_manage_sponsoring', this.getAttribute('data-sponsoring') === '1');
                setCheckedIfPresent('edit_can_manage_song_library', this.getAttribute('data-song-library') === '1');
                setCheckedIfPresent('edit_can_manage_newsletters', this.getAttribute('data-newsletters') === '1');
                setCheckedIfPresent('edit_can_manage_mail_queue', this.getAttribute('data-mail-queue') === '1');
                setCheckedIfPresent('edit_can_manage_sheet_archive', this.getAttribute('data-sheet-archive') === '1');
                setCheckedIfPresent('edit_can_manage_budget', this.getAttribute('data-budget') === '1');
                setCheckedIfPresent('edit_can_manage_tasks', this.getAttribute('data-tasks') === '1');
                setCheckedIfPresent('edit_can_manage_backups', this.getAttribute('data-backups') === '1');
                setCheckedIfPresent('edit_can_manage_own_voice_group', this.getAttribute('data-own-voice-group') === '1');
            });
        });
    }
});
