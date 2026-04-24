document.addEventListener('DOMContentLoaded', function () {
    const repeatCheckbox = document.getElementById('repeat_event');
    const recurrenceOptions = document.getElementById('recurrence_options');
    const frequencySelect = document.getElementById('frequency');
    const weekdaySelector = document.getElementById('weekday_selector');

    function updateRecurrenceVisibility() {
        if (!repeatCheckbox || !recurrenceOptions) {
            return;
        }

        recurrenceOptions.style.display = repeatCheckbox.checked ? 'block' : 'none';
        const seriesEndDate = document.getElementById('series_end_date');
        if (seriesEndDate) {
            if (repeatCheckbox.checked) {
                seriesEndDate.setAttribute('required', 'required');
            } else {
                seriesEndDate.removeAttribute('required');
            }
        }
    }

    function updateWeekdayVisibility() {
        if (!frequencySelect || !weekdaySelector) {
            return;
        }

        weekdaySelector.style.display = (frequencySelect.value === 'weekly') ? 'block' : 'none';
    }

    if (repeatCheckbox) {
        repeatCheckbox.addEventListener('change', function () {
            updateRecurrenceVisibility();
        });

        updateRecurrenceVisibility();
    }

    if (frequencySelect) {
        frequencySelect.addEventListener('change', function () {
            updateWeekdayVisibility();
        });

        updateWeekdayVisibility();
    }

    const addEventModal = document.getElementById('addEventModal');
    if (addEventModal && addEventModal.dataset.openCreateModal === '1' && window.bootstrap && window.bootstrap.Modal) {
        window.bootstrap.Modal.getOrCreateInstance(addEventModal).show();
    }

    const filterForm = document.getElementById('event-filter-form');
    const projectFilter = document.getElementById('filter_project');
    const typeFilter = document.getElementById('filter_type');
    const showOldEventsCheckbox = document.getElementById('show_old_events');

    if (filterForm) {
        if (projectFilter) {
            projectFilter.addEventListener('change', function () {
                filterForm.submit();
            });
        }

        if (typeFilter) {
            typeFilter.addEventListener('change', function () {
                filterForm.submit();
            });
        }

        if (showOldEventsCheckbox) {
            showOldEventsCheckbox.addEventListener('change', function () {
                filterForm.submit();
            });
        }
    }
});
