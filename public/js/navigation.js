/**
 * Seitenleiste: Einklappen am Desktop, Offcanvas am Handy, aufklappbare Abschnitte und
 * das Merken der zuletzt besuchten Seite für die Schnellsuche.
 *
 * Der Menüknopf hat zwei Bedeutungen je nach Breite. Ab lg schaltet er zwischen breiter
 * Leiste und Symbolleiste um, darunter öffnet er das Offcanvas. Deshalb trägt er kein
 * data-bs-toggle: Bootstrap würde sonst auch am Desktop ein Offcanvas samt Backdrop öffnen.
 */
document.addEventListener('DOMContentLoaded', function () {
    var sidebar = document.getElementById('app-sidebar');
    if (!sidebar) {
        return;
    }

    var root = document.documentElement;
    var desktop = window.matchMedia('(min-width: 992px)');
    var COLLAPSED_KEY = 'chormanager.nav.collapsed';
    var OPEN_KEY_PREFIX = 'chormanager.nav.open.';
    var RECENT_KEY = 'chormanager.nav.recent';
    var RECENT_LIMIT = 5;

    function store(key, value) {
        try {
            window.localStorage.setItem(key, value);
        } catch (e) {
            // Ohne localStorage gilt der Zustand nur bis zum nächsten Seitenwechsel.
        }
    }

    var toggles = document.querySelectorAll('[data-nav-toggle]');
    var returnFocus = null;

    function syncToggles() {
        var expanded = desktop.matches
            ? !root.classList.contains('nav-collapsed')
            : sidebar.classList.contains('show');
        toggles.forEach(function (toggle) {
            toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        });
    }

    toggles.forEach(function (toggle) {
        toggle.addEventListener('click', function () {
            if (desktop.matches) {
                var collapsed = root.classList.toggle('nav-collapsed');
                store(COLLAPSED_KEY, collapsed ? '1' : '0');
            } else {
                returnFocus = toggle;
                bootstrap.Offcanvas.getOrCreateInstance(sidebar).toggle();
            }
            syncToggles();
        });
    });

    sidebar.addEventListener('shown.bs.offcanvas', syncToggles);
    sidebar.addEventListener('hidden.bs.offcanvas', syncToggles);

    // Das Offcanvas wird programmatisch geöffnet, Bootstrap gibt den Fokus beim Schließen
    // deshalb nicht von selbst an den Menüknopf zurück. Hat die Schnellsuche das Menü
    // geschlossen, gehört der Fokus ihr (body.modal-open ist dann schon gesetzt).
    sidebar.addEventListener('hidden.bs.offcanvas', function () {
        if (returnFocus && !document.body.classList.contains('modal-open')) {
            returnFocus.focus();
        }
        returnFocus = null;
    });

    // Bootstrap räumt ein offenes offcanvas-lg beim Vergrößern nur auf, solange es nicht
    // position: fixed hat - unsere Desktop-Leiste hat genau das. Ohne dieses Schließen
    // bliebe nach dem Drehen eines Tablets ein grauer Backdrop über der Seite stehen.
    desktop.addEventListener('change', function () {
        if (desktop.matches) {
            var instance = bootstrap.Offcanvas.getInstance(sidebar);
            if (instance) {
                instance.hide();
            }
        }
        syncToggles();
    });

    sidebar.querySelectorAll('[data-nav-fold]').forEach(function (button) {
        var key = button.getAttribute('data-nav-fold');
        var section = button.closest('[data-nav-section]');
        var openClass = 'nav-open-' + key;

        function syncFold() {
            var open = section.classList.contains('is-active') || root.classList.contains(openClass);
            button.setAttribute('aria-expanded', open ? 'true' : 'false');
        }

        button.addEventListener('click', function () {
            // Liegt die aktuelle Seite im Abschnitt, bleibt er offen - sonst verschwände
            // der hervorgehobene Eintrag.
            if (section.classList.contains('is-active')) {
                return;
            }
            var open = root.classList.toggle(openClass);
            store(OPEN_KEY_PREFIX + key, open ? '1' : '0');
            syncFold();
        });

        syncFold();
    });

    var current = sidebar.querySelector('.app-sidebar__link[aria-current="page"]');
    if (current) {
        try {
            var url = current.getAttribute('href');
            var stored = JSON.parse(window.localStorage.getItem(RECENT_KEY) || '[]');
            var recent = (Array.isArray(stored) ? stored : []).filter(function (entry) {
                return typeof entry === 'string' && entry !== url;
            });
            recent.unshift(url);
            window.localStorage.setItem(RECENT_KEY, JSON.stringify(recent.slice(0, RECENT_LIMIT)));
        } catch (e) {
            // Kaputter oder gesperrter Speicher: dann eben ohne "Zuletzt besucht".
        }
    }

    syncToggles();
});
