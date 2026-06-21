document.addEventListener('DOMContentLoaded', function () {
    const restoreButtons = document.querySelectorAll('.restore-backup-btn');
    const restoreForm = document.getElementById('restoreBackupForm');

    restoreButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            restoreForm.action = '/backups/' + this.getAttribute('data-id') + '/restore';
        });
    });

    const deleteButtons = document.querySelectorAll('.delete-backup-btn');
    const deleteForm = document.getElementById('deleteBackupForm');

    deleteButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            deleteForm.action = '/backups/' + this.getAttribute('data-id') + '/delete';
        });
    });
});
