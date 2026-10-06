/**
 * Hält die Glocke in der Kopfzeile aktuell und füllt ihr Dropdown.
 *
 * Der Zähler wird beim Zurückwechseln in den Tab und alle zwei Minuten bei
 * sichtbarem Tab abgefragt - kein WebSocket, wie beim Mail-Badge. Die Liste
 * lädt erst beim Aufklappen. Texte werden ausschließlich über textContent
 * gesetzt: Titel und Zeile stammen aus Eingaben anderer Mitglieder.
 */
document.addEventListener('DOMContentLoaded', function () {
    var root = document.querySelector('[data-notification-bell]');
    if (!root) {
        return;
    }

    var pill = root.querySelector('[data-notification-bell-count]');
    var list = root.querySelector('[data-notification-bell-list]');
    var readAllForm = root.querySelector('[data-notification-bell-read-all]');
    if (!pill || !list) {
        return;
    }

    var MIN_INTERVAL_MS = 5000;
    var POLL_INTERVAL_MS = 120000;

    // Dieselbe Zuordnung wie in templates/partials/navigation/notification_icon.twig.
    var ICONS = {
        tasks: 'bi-check2-square',
        events: 'bi-calendar-event',
        projects: 'bi-folder2-open',
        sponsoring: 'bi-briefcase'
    };

    var lastRequestedAt = 0;
    var inFlight = false;

    function renderCount(count) {
        if (typeof count !== 'number') {
            return;
        }
        pill.textContent = count > 99 ? '99+' : String(count);
        pill.classList.toggle('d-none', count <= 0);
    }

    function getJson(url) {
        return fetch(url, {
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin'
        }).then(function (response) {
            return response.ok ? response.json() : null;
        });
    }

    function refreshCount() {
        var now = Date.now();
        if (inFlight || now - lastRequestedAt < MIN_INTERVAL_MS) {
            return;
        }
        inFlight = true;
        lastRequestedAt = now;

        getJson('/notifications/badge')
            .then(function (data) {
                if (data) {
                    renderCount(data.unread_count);
                }
            })
            .catch(function () {
                // Netzfehler oder abgelaufene Sitzung: Der zuletzt gezeigte Stand bleibt.
            })
            .finally(function () {
                inFlight = false;
            });
    }

    function message(text) {
        var div = document.createElement('div');
        div.className = 'px-3 py-3 text-muted small';
        div.textContent = text;
        return div;
    }

    function renderItem(item) {
        var link = document.createElement('a');
        link.href = item.url;
        link.className = 'dropdown-item notification-item' + (item.unread ? ' notification-item-unread' : '');

        var icon = document.createElement('i');
        icon.className = 'bi ' + (ICONS[item.group] || 'bi-bell') + ' notification-item-icon';

        var text = document.createElement('div');
        text.className = 'notification-item-text';

        var title = document.createElement('div');
        title.className = 'notification-item-title';
        title.textContent = item.title;
        text.appendChild(title);

        if (item.body) {
            var body = document.createElement('div');
            body.className = 'small text-muted notification-item-body';
            body.textContent = item.body;
            text.appendChild(body);
        }

        var time = document.createElement('div');
        time.className = 'small text-muted';
        time.textContent = item.relative_time;
        text.appendChild(time);

        link.appendChild(icon);
        link.appendChild(text);
        return link;
    }

    function loadList() {
        getJson('/notifications/recent')
            .then(function (data) {
                if (!data) {
                    list.replaceChildren(message('Benachrichtigungen konnten nicht geladen werden.'));
                    return;
                }
                renderCount(data.unread_count);
                if (!data.items || data.items.length === 0) {
                    list.replaceChildren(message('Keine Benachrichtigungen'));
                    return;
                }
                list.replaceChildren.apply(list, data.items.map(renderItem));
            })
            .catch(function () {
                list.replaceChildren(message('Benachrichtigungen konnten nicht geladen werden.'));
            });
    }

    root.addEventListener('show.bs.dropdown', loadList);

    if (readAllForm) {
        readAllForm.addEventListener('submit', function (event) {
            event.preventDefault();
            fetch(readAllForm.action, {
                method: 'POST',
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin',
                body: new FormData(readAllForm)
            })
                .then(function (response) {
                    return response.ok ? response.json() : null;
                })
                .then(function (data) {
                    if (!data) {
                        return;
                    }
                    renderCount(data.unread_count);
                    list.querySelectorAll('.notification-item-unread').forEach(function (element) {
                        element.classList.remove('notification-item-unread');
                    });
                })
                .catch(function () {
                    // Ohne Antwort bleibt alles, wie es war; die Seite /notifications hilft weiter.
                });
        });
    }

    window.addEventListener('focus', refreshCount);
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) {
            refreshCount();
        }
    });
    window.setInterval(function () {
        if (!document.hidden) {
            refreshCount();
        }
    }, POLL_INTERVAL_MS);
});
