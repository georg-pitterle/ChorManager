// public/js/kanban-sortable-init.js

document.addEventListener('DOMContentLoaded', function () {
    function getEmptyTextForStatus(status) {
        var map = {
            'Offen': 'Keine offenen Aufgaben',
            'In Bearbeitung': 'Keine Aufgaben in Bearbeitung',
            'Blockiert': 'Keine blockierten Aufgaben',
            'Abgeschlossen': 'Keine erledigten Aufgaben'
        };
        return map[status] || 'Keine Aufgaben';
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
        text.textContent = getEmptyTextForStatus(zone.dataset.dropZone);
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

    if (window.Sortable) {
        document.querySelectorAll('.kanban-cards-container').forEach(function (zone) {
            updatePlaceholder(zone);

            Sortable.create(zone, {
                group: 'kanban',
                animation: 150,
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
