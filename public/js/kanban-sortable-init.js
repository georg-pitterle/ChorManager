// public/js/kanban-sortable-init.js

document.addEventListener('DOMContentLoaded', function () {
    // Den Hinweis bringt die Spalte selbst als data-empty-text mit. Vorher stand
    // hier eine eigene Zuordnung, verschluesselt nach den deutschen Statuswerten -
    // dieselben Texte wie in der Vorlage, nur ein zweites Mal, und das JavaScript
    // musste die Zustaende kennen. Seit die Werte englische Bezeichner sind,
    // haette diese Zuordnung ohnehin ins Leere gegriffen.
    function getEmptyTextForZone(zone) {
        return zone.dataset.emptyText || 'Keine Aufgaben';
    }

    function getOrCreatePlaceholder(zone) {
        var placeholder = zone.querySelector('.kanban-empty-placeholder');
        if (placeholder) {
            return placeholder;
        }

        placeholder = document.createElement('div');
        placeholder.className = 'kanban-empty-placeholder';

        var icon = document.createElement('i');
        icon.className = 'bi bi-inbox';
        placeholder.appendChild(icon);

        var text = document.createElement('span');
        text.textContent = getEmptyTextForZone(zone);
        placeholder.appendChild(text);

        zone.appendChild(placeholder);
        return placeholder;
    }

    function updatePlaceholder(zone) {
        if (!zone) {
            return;
        }

        var placeholder = getOrCreatePlaceholder(zone);
        var cards = zone.querySelectorAll('.kanban-card');
        placeholder.style.display = cards.length === 0 ? 'flex' : 'none';
    }

    // Ein überschrittenes Datum ist nur bei offener Arbeit eine Warnung. Wandert
    // eine Karte nach "erledigt" oder wieder heraus, passt sich die Farbe an,
    // ohne dass die Seite neu geladen werden muss.
    function syncDueWarning(card, status) {
        var due = card.querySelector('[data-past-due]');
        if (due) {
            due.classList.toggle('kanban-date-warning', status !== 'done');
        }
    }

    if (window.Sortable) {
        document.querySelectorAll('.kanban-cards-container').forEach(function (zone) {
            updatePlaceholder(zone);

            Sortable.create(zone, {
                group: 'kanban',
                animation: 150,
                // Auf Touch-Geräten erst nach langem Drücken ziehen, sonst löst jedes Wischen
                // zum Scrollen der Spalte versehentlich ein Verschieben der Karte aus.
                delay: 200,
                delayOnTouchOnly: true,
                touchStartThreshold: 5,
                draggable: '.kanban-card',
                onEnd: function (evt) {
                    updatePlaceholder(evt.from);
                    updatePlaceholder(evt.to);

                    var card = evt.item;
                    var newZone = evt.to;
                    var newStatus = newZone.dataset.dropZone;
                    var taskId = card.dataset.taskId;
                    if (card.dataset.taskStatus !== newStatus) {
                        var csrfToken = (document.querySelector('meta[name="csrf-token"]') || {}).getAttribute
                            ? document.querySelector('meta[name="csrf-token"]').getAttribute('content') || ''
                            : '';
                        fetch('/tasks/' + taskId + '/status', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-Token': csrfToken
                            },
                            body: JSON.stringify({ status: newStatus })
                        })
                            .then(function (res) { return res.json(); })
                            .then(function (data) {
                                if (data.success) {
                                    card.dataset.taskStatus = newStatus;
                                    syncDueWarning(card, newStatus);
                                } else {
                                    // Fehler: zurückschieben
                                    evt.from.insertBefore(card, evt.from.children[evt.oldIndex]);
                                    updatePlaceholder(evt.from);
                                    updatePlaceholder(evt.to);
                                    alert(data.error || 'Status konnte nicht geändert werden.');
                                }
                            })
                            .catch(function () {
                                evt.from.insertBefore(card, evt.from.children[evt.oldIndex]);
                                updatePlaceholder(evt.from);
                                updatePlaceholder(evt.to);
                                alert('Status konnte nicht geändert werden.');
                            });
                    }
                }
            });
        });
    }
});
