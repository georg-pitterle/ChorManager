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

    // Gleiche Grenze wie Bootstraps lg-Breakpoint (992px).
    var compactQuery = window.matchMedia('(max-width: 991.98px)');

    function toolbarFor(compact) {
        return {
            left: 'prev,next today',
            center: 'title',
            right: compact ? 'listMonth' : 'dayGridMonth,timeGridWeek,listMonth'
        };
    }

    var calendar = new FullCalendar.Calendar(container, {
        locale: 'de',
        firstDay: 1,
        initialView: compactQuery.matches ? 'listMonth' : 'dayGridMonth',
        headerToolbar: toolbarFor(compactQuery.matches),
        height: 'auto',
        contentHeight: 'auto',
        events: events,
        // FullCalendar 7 vergibt keine festen fc-*-Klassen mehr; eigene Regeln
        // in style.css hängen an diesen Namen.
        headerToolbarClass: 'event-calendar-toolbar',
        toolbarSectionClass: 'event-calendar-toolbar-section',
        toolbarTitleClass: 'event-calendar-title',
        eventTitleClass: 'event-calendar-event-title',
        listItemEventInnerClass: 'event-calendar-dot-event-inner',
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

    // Die Option windowResize gibt es seit FullCalendar 7 nicht mehr. Der
    // MediaQuery-Listener meldet sich außerdem nur beim Überschreiten der
    // Grenze statt bei jedem Pixel.
    compactQuery.addEventListener('change', function (event) {
        var current = calendar.view.type;
        if (event.matches && current !== 'listMonth') {
            calendar.changeView('listMonth');
        } else if (!event.matches && current === 'listMonth') {
            calendar.changeView('dayGridMonth');
        }
        calendar.setOption('headerToolbar', toolbarFor(event.matches));
    });

    calendar.render();
}());
