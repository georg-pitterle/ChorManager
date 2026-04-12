document.addEventListener('DOMContentLoaded', function () {
    const csrfTokenMeta = document.querySelector('meta[name="csrf-token"]');
    const csrfToken = csrfTokenMeta ? csrfTokenMeta.getAttribute('content') : '';

    function ensureCsrfField(form) {
        if (!form || !csrfToken || form.getAttribute('method')?.toLowerCase() !== 'post') {
            return;
        }

        let csrfField = form.querySelector('input[name="_csrf"]');
        if (!csrfField) {
            csrfField = document.createElement('input');
            csrfField.type = 'hidden';
            csrfField.name = '_csrf';
            form.appendChild(csrfField);
        }

        csrfField.value = csrfToken;
    }

    document.querySelectorAll('form[method="post"], form[method="POST"]').forEach(ensureCsrfField);

    document.addEventListener('submit', function (event) {
        const form = event.target;
        if (!(form instanceof HTMLFormElement)) {
            return;
        }

        ensureCsrfField(form);

        const confirmMessage = form.getAttribute('data-confirm');
        if (confirmMessage && !confirm(confirmMessage)) {
            event.preventDefault();
        }
    }, true);

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

});
