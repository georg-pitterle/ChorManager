// Abgelaufene Sitzung: einmal behandeln statt in jedem Aufrufer.
//
// Bis hierher bekam ein fetch-Aufruf auf eine geschützte Route nach dem Ablauf
// der Sitzung eine Weiterleitung auf /login. fetch folgt ihr selbst, bekommt die
// Anmeldeseite als HTML mit Status 200 und scheitert erst beim Auswerten - die
// Oberfläche meldete dann "Speichern fehlgeschlagen", was mit der Ursache nichts
// zu tun hat. Jetzt antworten AuthMiddleware und CsrfMiddleware mit einem Fehler
// und dem Kopfeintrag X-Session-Expired.
//
// Der Ersatz liegt um window.fetch und nicht in den elf Aufrufstellen: Sonst
// bliebe der nächste neu geschriebene Aufruf wieder ohne Behandlung, und genau
// das ist der Fehler, den diese Korrektur behebt. common.js steht in layout.twig
// vor den seitenweisen Skripten, der Ersatz ist also gesetzt, bevor der erste
// Aufruf losgeht.
(function () {
    const originalFetch = window.fetch;
    if (typeof originalFetch !== 'function') {
        return;
    }

    let alreadyLeaving = false;

    function goToLogin() {
        // Der Aufruf ging an ein Ziel wie /users/42/roles - dazu gehört keine
        // Seite. Zurück soll es auf die Seite, auf der die Person gerade steht.
        if (alreadyLeaving) {
            return;
        }

        alreadyLeaving = true;
        const here = window.location.pathname + window.location.search;
        window.location.assign('/login?redirect=' + encodeURIComponent(here));
    }

    window.fetch = function (...args) {
        return originalFetch.apply(this, args).then(function (response) {
            // Der Kopfeintrag statt des Rumpfs: Einen Rumpf kann man nur einmal
            // lesen, und danach fehlte er dem eigentlichen Aufrufer.
            if (response && response.headers && response.headers.get('X-Session-Expired') === '1') {
                goToLogin();
            }

            return response;
        });
    };
})();

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

    // Generic auto-submit for selects marked with onchange-submit.
    // This keeps project/year behavior and also supports status switches like newsletters.
    document.querySelectorAll('select.onchange-submit').forEach(select => {
        select.addEventListener('change', function () {
            if (this.form) {
                this.form.submit();
            }
        });
    });

    // Attach upload compression behavior to marked forms.
    if (window.uploadHelper && typeof window.uploadHelper.setupFormCompression === 'function') {
        document.querySelectorAll('form[data-upload-compress="true"]').forEach(form => {
            if (!form.dataset.uploadCompressBound) {
                window.uploadHelper.setupFormCompression(form);
                form.dataset.uploadCompressBound = '1';
            }
        });
    }

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
