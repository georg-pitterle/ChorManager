// Feature-Flags koennen einzelne Checkboxen serverseitig ausblenden;
// dann darf die Zuweisung nicht auf ein fehlendes Element treffen.
function setCheckedIfPresent(id, checked) {
    const checkbox = document.getElementById(id);
    if (checkbox) {
        checkbox.checked = checked;
    }
}

// Ein Vollrecht schließt sein kleineres ein (Role::IMPLIED_PERMISSIONS, und
// seit dem Review-Lauf 56 setzt RoleController beide Paare auch in die Spalte).
// Die Häkchen folgen dem, damit die Maske nicht etwas anderes zeigt als
// gespeichert wird: Wer das Vollrecht setzt, bekommt das kleinere mit; wer das
// kleinere wegnimmt, verliert das Vollrecht.
//
// Zuvor hieß das syncFinancePermissionPair und war nur auf die Finanzen
// gelegt. Beim Sponsoring ließ sich "eigene Patenschaften anlegen" deshalb
// abwählen, obwohl "Sponsoring verwalten" stehen blieb - gespeichert wurde es
// danach doch, und die Maske hatte das Gegenteil versprochen.
function syncImpliedPermissionPair(impliedCheckbox, fullCheckbox) {
    if (!impliedCheckbox || !fullCheckbox) {
        return;
    }

    fullCheckbox.addEventListener('change', function () {
        if (fullCheckbox.checked) {
            impliedCheckbox.checked = true;
        }
    });

    impliedCheckbox.addEventListener('change', function () {
        if (!impliedCheckbox.checked) {
            fullCheckbox.checked = false;
        }
    });
}

document.addEventListener('DOMContentLoaded', function () {
    const editButtons = document.querySelectorAll('.edit-role-btn');
    const editForm = document.getElementById('editRoleForm');

    [
        ['can_read_finances', 'can_manage_finances'],
        ['can_create_own_sponsorships', 'can_manage_sponsoring']
    ].forEach(function (pair) {
        ['', 'edit_'].forEach(function (prefix) {
            syncImpliedPermissionPair(
                document.getElementById(prefix + pair[0]),
                document.getElementById(prefix + pair[1])
            );
        });
    });

    if (editForm) {
        editButtons.forEach(button => {
            button.addEventListener('click', function () {
                const id = this.getAttribute('data-id');
                editForm.action = '/roles/' + id;

                document.getElementById('edit_name').value = this.getAttribute('data-name');
                document.getElementById('edit_hierarchy_level').value = this.getAttribute('data-level');
                setCheckedIfPresent('edit_can_manage_users', this.getAttribute('data-manage') === '1');
                setCheckedIfPresent('edit_can_manage_roles', this.getAttribute('data-roles') === '1');
                setCheckedIfPresent('edit_can_edit_users', this.getAttribute('data-edit') === '1');
                setCheckedIfPresent('edit_can_manage_events', this.getAttribute('data-events') === '1');
                setCheckedIfPresent('edit_can_manage_attendance_all', this.getAttribute('data-attendance-all') === '1');
                setCheckedIfPresent('edit_can_manage_project_members', this.getAttribute('data-project-members') === '1');
                setCheckedIfPresent('edit_can_read_finances', this.getAttribute('data-finance-read') === '1');
                setCheckedIfPresent('edit_can_manage_finances', this.getAttribute('data-finances') === '1');
                setCheckedIfPresent('edit_can_manage_master_data', this.getAttribute('data-master-data') === '1');
                setCheckedIfPresent('edit_can_manage_sponsoring', this.getAttribute('data-sponsoring') === '1');
                setCheckedIfPresent('edit_can_create_own_sponsorships', this.getAttribute('data-own-sponsorships') === '1');
                setCheckedIfPresent('edit_can_manage_song_library', this.getAttribute('data-song-library') === '1');
                setCheckedIfPresent('edit_can_manage_newsletters', this.getAttribute('data-newsletters') === '1');
                setCheckedIfPresent('edit_can_manage_mail_queue', this.getAttribute('data-mail-queue') === '1');
                setCheckedIfPresent('edit_can_manage_sheet_archive', this.getAttribute('data-sheet-archive') === '1');
                setCheckedIfPresent('edit_can_manage_budget', this.getAttribute('data-budget') === '1');
                setCheckedIfPresent('edit_can_manage_tasks', this.getAttribute('data-tasks') === '1');
                setCheckedIfPresent('edit_can_manage_backups', this.getAttribute('data-backups') === '1');
                setCheckedIfPresent('edit_can_manage_files', this.getAttribute('data-files') === '1');
                setCheckedIfPresent('edit_can_manage_storage', this.getAttribute('data-storage') === '1');
                setCheckedIfPresent('edit_can_manage_own_voice_group', this.getAttribute('data-own-voice-group') === '1');
                setCheckedIfPresent('edit_can_assign_own_voice_group_to_project', this.getAttribute('data-assign-own-voice-group-project') === '1');
                setCheckedIfPresent('edit_can_keep_voice_group_lists', this.getAttribute('data-keep-voice-group-lists') === '1');
            });
        });
    }
});
