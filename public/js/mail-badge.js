/**
 * Hält den Zähler des Mail-Badges aktuell, wenn der Tab wieder in den Vordergrund kommt.
 *
 * Das Postfach öffnet sich in einem eigenen Tab. Der ChorManager-Tab daneben bleibt
 * unverändert stehen, solange niemand navigiert - der Zähler zeigte deshalb nach dem
 * Lesen weiter die alte Zahl. Beim Zurückwechseln fragt diese Datei den aktuellen
 * Stand ab und blendet die rote Pille entsprechend ein, aus oder schreibt sie um.
 */
document.addEventListener('DOMContentLoaded', function () {
    var trigger = document.querySelector('[data-mail-badge]');
    if (!trigger) {
        return;
    }

    var pill = trigger.querySelector('[data-mail-badge-count]');
    if (!pill) {
        return;
    }

    var ENDPOINT = '/profile/mail-badge';

    /*
     * Nur gegen Ereignis-Salven: 'focus' und 'visibilitychange' feuern beim
     * Zurückwechseln in den Tab beide. Die eigentliche Bremse für den IMAP-Abgleich
     * sitzt im Server (MailBadgeRefreshMiddleware) - ein hier durchgelassener Abruf
     * kostet dort im Zweifel nur eine Datenbankabfrage.
     */
    var MIN_INTERVAL_MS = 5000;

    var lastRequestedAt = 0;
    var inFlight = false;

    function render(unseenCount) {
        if (unseenCount === null) {
            return;
        }

        pill.textContent = unseenCount > 99 ? '99+' : String(unseenCount);
        pill.classList.toggle('d-none', unseenCount <= 0);
    }

    function refresh() {
        var now = Date.now();
        if (inFlight || now - lastRequestedAt < MIN_INTERVAL_MS) {
            return;
        }

        inFlight = true;
        lastRequestedAt = now;

        fetch(ENDPOINT, {
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin'
        })
            .then(function (response) {
                return response.ok ? response.json() : null;
            })
            .then(function (data) {
                if (data && typeof data.unseen_count !== 'undefined') {
                    render(data.unseen_count);
                }
            })
            .catch(function () {
                // Netzfehler oder abgelaufene Sitzung: Der zuletzt gerenderte Stand
                // bleibt stehen. Ein leeres oder fälschlich genulltes Badge wäre die
                // schlechtere Auskunft als ein möglicherweise veralteter Zähler.
            })
            .finally(function () {
                inFlight = false;
            });
    }

    window.addEventListener('focus', refresh);

    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) {
            refresh();
        }
    });
});
