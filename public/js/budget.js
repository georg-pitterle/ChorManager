(function () {
    'use strict';

    // Toggles the "new group" text field when the budget group select is set to
    // the "+ neue Gruppe" sentinel. Class-based so it works for every modal on the page.
    function wireGroupSelect(select) {
        const wrapper = select.closest('.mb-3');
        if (!wrapper) {
            return;
        }
        const newInput = wrapper.querySelector('[data-budget-group-new]');
        if (!newInput) {
            return;
        }

        function sync() {
            const isNew = select.value === '__new__';
            newInput.classList.toggle('d-none', !isNew);
            newInput.required = isNew;
            if (!isNew) {
                newInput.value = '';
            } else {
                newInput.focus();
            }
        }

        select.addEventListener('change', sync);
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-budget-group-select]').forEach(wireGroupSelect);
    });
})();
