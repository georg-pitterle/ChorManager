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
});
