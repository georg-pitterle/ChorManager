document.addEventListener('DOMContentLoaded', function () {
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function () {
            navigator.serviceWorker.register('/sw.js').catch(function () {
                // Ignore registration failures silently. The app remains usable without installation support.
            });
        });
    }

    // Project selectors (onchange submit)
    document.querySelectorAll('select[name="project_id"].onchange-submit').forEach(select => {
        select.addEventListener('change', function () {
            this.form.submit();
        });
    });

    // Fiscal year selector in finance report
    document.querySelectorAll('select[name="year"].onchange-submit').forEach(select => {
        select.addEventListener('change', function () {
            this.form.submit();
        });
    });

    // Attendance event selector (special case)
    const attendanceSelector = document.querySelector('select[name="event_id"].attendance-selector');
    if (attendanceSelector) {
        attendanceSelector.addEventListener('change', function () {
            if (this.value) {
                window.location.href = '/attendance/' + this.value;
            } else {
                window.location.href = '/attendance';
            }
        });
    }

    // Confirmation dialogs
    document.querySelectorAll('form[data-confirm]').forEach(form => {
        form.addEventListener('submit', function (e) {
            const message = this.getAttribute('data-confirm');
            if (!confirm(message)) {
                e.preventDefault();
            }
        });
    });
});
