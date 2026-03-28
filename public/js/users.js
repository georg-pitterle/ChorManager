document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.vg-checkbox').forEach(function (checkbox) {
        checkbox.addEventListener('change', function () {
            var container = this.closest('.border');
            var selector = container.querySelector('.collapse-sv');
            if (this.checked) {
                selector.style.display = 'block';
            } else {
                selector.style.display = 'none';
                selector.querySelector('select').value = '';
            }
        });
    });

    const selectAll = document.getElementById('selectAllUsers');
    const hidden = document.getElementById('bulkUserIds');
    const button = document.getElementById('bulkDeactivateButton');
    const rowCheckboxes = Array.from(document.querySelectorAll('.user-row-select')).filter(function (cb) {
        return !cb.disabled;
    });

    function syncBulkSelection() {
        if (!hidden || !button) {
            return;
        }

        const selected = rowCheckboxes.filter(function (cb) {
            return cb.checked;
        }).map(function (cb) {
            return cb.value;
        });

        hidden.value = selected.join(',');
        button.disabled = selected.length === 0;

        if (selectAll) {
            selectAll.checked = rowCheckboxes.length > 0 && selected.length === rowCheckboxes.length;
            selectAll.indeterminate = selected.length > 0 && selected.length < rowCheckboxes.length;
        }
    }

    if (selectAll) {
        selectAll.addEventListener('change', function () {
            rowCheckboxes.forEach(function (cb) {
                cb.checked = selectAll.checked;
            });
            syncBulkSelection();
        });
    }

    rowCheckboxes.forEach(function (cb) {
        cb.addEventListener('change', syncBulkSelection);
    });

    syncBulkSelection();
});
