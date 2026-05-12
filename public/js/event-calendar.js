(function () {
    'use strict';

    var container = document.getElementById('event-calendar');
    if (!container) {
        return;
    }

    var eventsJson = container.dataset.calendarEvents || '[]';
    var isAdmin = container.dataset.calendarAdmin === '1';
    var events = [];
    try {
        events = JSON.parse(eventsJson);
    } catch (e) {
        console.error('event-calendar: failed to parse calendar events JSON', e);
    }

    function isCompact() {
        return window.innerWidth < 992;
    }

    var calendar = new FullCalendar.Calendar(container, {
        locale: 'de',
        firstDay: 1,
        initialView: isCompact() ? 'listMonth' : 'dayGridMonth',
        headerToolbar: {
            left: 'prev,next today',
            center: 'title',
            right: isCompact() ? 'listMonth' : 'dayGridMonth,timeGridWeek,listMonth'
        },
        height: 'auto',
        contentHeight: 'auto',
        events: events,
        windowResize: function (arg) {
            var compact = isCompact();
            var current = arg.view.type;
            if (compact && current !== 'listMonth') {
                calendar.changeView('listMonth');
                calendar.setOption('headerToolbar', {
                    left: 'prev,next today',
                    center: 'title',
                    right: 'listMonth'
                });
            } else if (!compact && current === 'listMonth') {
                calendar.changeView('dayGridMonth');
                calendar.setOption('headerToolbar', {
                    left: 'prev,next today',
                    center: 'title',
                    right: 'dayGridMonth,timeGridWeek,listMonth'
                });
            }
        },
        eventClick: function (info) {
            if (info.event.url) {
                info.jsEvent.preventDefault();
                window.location.href = info.event.url;
            }
        },
        dateClick: function (info) {
            if (!isAdmin) {
                return;
            }
            var modal = document.getElementById('addEventModal');
            if (!modal) {
                return;
            }
            var dateInput = modal.querySelector('#starts_at');
            if (dateInput) {
                dateInput.value = info.dateStr;
            }
            var bsModal = bootstrap.Modal.getOrCreateInstance(modal);
            bsModal.show();
        }
    });

    calendar.render();
}());
