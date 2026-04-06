document.addEventListener('DOMContentLoaded', function() {
    const repeatCheckbox = document.getElementById('repeat_event');
    const recurrenceOptions = document.getElementById('recurrence_options');
    const frequencySelect = document.getElementById('frequency');
    const weekdaySelector = document.getElementById('weekday_selector');
    
    if (repeatCheckbox) {
        repeatCheckbox.addEventListener('change', function() {
            recurrenceOptions.style.display = this.checked ? 'block' : 'none';
            if (this.checked) {
                document.getElementById('series_end_date').setAttribute('required', 'required');
            } else {
                document.getElementById('series_end_date').removeAttribute('required');
            }
        });
    }

    if (frequencySelect) {
        frequencySelect.addEventListener('change', function() {
            weekdaySelector.style.display = (this.value === 'weekly') ? 'block' : 'none';
        });
    }

    const filterForm = document.getElementById('event-filter-form');
    const projectFilter = document.getElementById('filter_project');
    const typeFilter = document.getElementById('filter_type');
    const showOldEventsCheckbox = document.getElementById('show_old_events');

    if (filterForm) {
        if (projectFilter) {
            projectFilter.addEventListener('change', function() {
                filterForm.submit();
            });
        }

        if (typeFilter) {
            typeFilter.addEventListener('change', function() {
                filterForm.submit();
            });
        }

        if (showOldEventsCheckbox) {
            showOldEventsCheckbox.addEventListener('change', function() {
                filterForm.submit();
            });
        }
    }
});
