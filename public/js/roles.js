document.addEventListener('DOMContentLoaded', function() {
    const editButtons = document.querySelectorAll('.edit-role-btn');
    const editForm = document.getElementById('editRoleForm');
    
    if (editForm) {
        editButtons.forEach(button => {
            button.addEventListener('click', function() {
                const id = this.getAttribute('data-id');
                editForm.action = '/roles/' + id;
                
                document.getElementById('edit_name').value = this.getAttribute('data-name');
                document.getElementById('edit_hierarchy_level').value = this.getAttribute('data-level');
                document.getElementById('edit_can_manage_users').checked = this.getAttribute('data-manage') === '1';
                document.getElementById('edit_can_edit_users').checked = this.getAttribute('data-edit') === '1';
                document.getElementById('edit_can_manage_project_members').checked = this.getAttribute('data-project-members') === '1';
                document.getElementById('edit_can_manage_finances').checked = this.getAttribute('data-finances') === '1';
                document.getElementById('edit_can_manage_master_data').checked = this.getAttribute('data-master-data') === '1';
            });
        });
    }
});
