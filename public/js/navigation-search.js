/**
 * Schnellsuche über die Seiten der Seitenleiste (Strg+K / ⌘+K oder Suchknopf).
 *
 * Datenquelle sind die gerenderten Leistenlinks: was der NavigationBuilder für diese
 * Rolle nicht ausgibt, steht nicht im HTML und kann deshalb auch nicht gefunden werden.
 * Die Suchlogik selbst liegt in navigation-search-rank.js.
 */
document.addEventListener('DOMContentLoaded', function () {
    var modalElement = document.getElementById('nav-search-modal');
    var sidebar = document.getElementById('app-sidebar');
    var rank = window.NavigationSearchRank;
    if (!modalElement || !sidebar || !rank) {
        return;
    }

    var input = document.getElementById('nav-search-input');
    var list = document.getElementById('nav-search-results');
    var empty = modalElement.querySelector('[data-nav-search-empty]');
    var modal = bootstrap.Modal.getOrCreateInstance(modalElement);
    var RECENT_KEY = 'chormanager.nav.recent';

    var entries = [];
    var shown = [];
    var selected = 0;
    var returnFocus = null;

    function readEntries() {
        return Array.prototype.map.call(sidebar.querySelectorAll('a.app-sidebar__link'), function (link) {
            var section = link.closest('[data-nav-section-title]');
            var label = link.querySelector('.app-sidebar__label');
            return {
                label: label ? label.textContent.trim() : '',
                url: link.getAttribute('href'),
                icon: link.getAttribute('data-nav-icon') || 'bi-link-45deg',
                section: section ? section.getAttribute('data-nav-section-title') : '',
                keywords: (link.getAttribute('data-nav-keywords') || '').split('|').filter(Boolean),
            };
        });
    }

    function recentEntries() {
        var raw = null;
        try {
            raw = window.localStorage.getItem(RECENT_KEY);
        } catch (e) {
            raw = null;
        }
        return rank.resolveRecentEntries(entries, rank.readRecentUrls(raw));
    }

    function asHits(list) {
        return list.map(function (entry) {
            return { entry: entry, keyword: null };
        });
    }

    function groups() {
        if (input.value.trim() !== '') {
            return [{ title: 'Treffer', hits: rank.rankNavigationEntries(entries, input.value) }];
        }
        var result = [];
        var recent = recentEntries();
        if (recent.length > 0) {
            result.push({ title: 'Zuletzt besucht', hits: asHits(recent) });
        }
        result.push({ title: 'Alle Seiten', hits: asHits(entries) });
        return result;
    }

    function option(hit, index) {
        var item = document.createElement('li');
        item.id = 'nav-search-option-' + index;
        item.className = 'nav-search__option';
        item.setAttribute('role', 'option');
        item.setAttribute('aria-selected', index === selected ? 'true' : 'false');
        item.setAttribute('data-index', String(index));

        var icon = document.createElement('i');
        icon.className = 'bi ' + hit.entry.icon;
        icon.setAttribute('aria-hidden', 'true');

        var label = document.createElement('span');
        label.className = 'nav-search__label';
        label.textContent = hit.entry.label;
        if (hit.keyword) {
            var keyword = document.createElement('span');
            keyword.className = 'nav-search__keyword';
            keyword.textContent = 'Stichwort: ' + hit.keyword;
            label.appendChild(document.createTextNode(' '));
            label.appendChild(keyword);
        }

        var section = document.createElement('span');
        section.className = 'nav-search__section';
        section.textContent = hit.entry.section;

        item.appendChild(icon);
        item.appendChild(label);
        item.appendChild(section);
        return item;
    }

    function render() {
        var visibleGroups = groups();
        shown = [];
        visibleGroups.forEach(function (group) {
            shown = shown.concat(group.hits);
        });
        selected = Math.min(selected, Math.max(shown.length - 1, 0));

        list.replaceChildren();
        var index = 0;
        visibleGroups.forEach(function (group) {
            if (group.hits.length === 0) {
                return;
            }
            var heading = document.createElement('li');
            heading.className = 'nav-search__group';
            heading.setAttribute('role', 'presentation');
            heading.textContent = group.title;
            list.appendChild(heading);
            group.hits.forEach(function (hit) {
                list.appendChild(option(hit, index));
                index++;
            });
        });

        empty.classList.toggle('d-none', shown.length > 0);
        if (shown.length > 0) {
            input.setAttribute('aria-activedescendant', 'nav-search-option-' + selected);
            var current = document.getElementById('nav-search-option-' + selected);
            if (current) {
                current.scrollIntoView({ block: 'nearest' });
            }
        } else {
            input.removeAttribute('aria-activedescendant');
        }
    }

    function open() {
        // Über einem anderen offenen Dialog (z. B. dem Newsletter-Editor) stapelt Bootstrap
        // Modals nicht sauber - dann bleibt das Kürzel wirkungslos.
        if (document.querySelector('.modal.show:not(#nav-search-modal)')) {
            return;
        }
        returnFocus = document.activeElement;
        var offcanvas = bootstrap.Offcanvas.getInstance(sidebar);
        if (offcanvas) {
            offcanvas.hide();
        }
        entries = readEntries();
        input.value = '';
        selected = 0;
        render();
        modal.show();
    }

    function go(index) {
        var hit = shown[index];
        if (hit) {
            window.location.assign(hit.entry.url);
        }
    }

    modalElement.addEventListener('shown.bs.modal', function () {
        input.focus();
    });

    // Bootstrap gibt den Fokus nur zurück, wenn über data-bs-toggle geöffnet wurde. Die
    // Suche öffnet programmatisch - ohne das landete der Fokus nach Esc auf <body>, und wer
    // gerade in einem Formularfeld stand, verlöre die Einfügemarke.
    modalElement.addEventListener('hidden.bs.modal', function () {
        if (returnFocus && returnFocus !== document.body && document.body.contains(returnFocus)) {
            returnFocus.focus();
        }
        returnFocus = null;
    });

    input.addEventListener('input', function () {
        selected = 0;
        render();
    });

    input.addEventListener('keydown', function (event) {
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            if (shown.length === 0) {
                return;
            }
            var step = event.key === 'ArrowDown' ? 1 : -1;
            selected = (selected + step + shown.length) % shown.length;
            render();
        } else if (event.key === 'Enter') {
            event.preventDefault();
            go(selected);
        }
    });

    list.addEventListener('click', function (event) {
        var item = event.target.closest('[role="option"]');
        if (item) {
            go(Number(item.getAttribute('data-index')));
        }
    });

    document.querySelectorAll('[data-nav-search-open]').forEach(function (button) {
        button.addEventListener('click', open);
    });

    document.addEventListener('keydown', function (event) {
        var isShortcut = (event.ctrlKey || event.metaKey) && !event.altKey && !event.shiftKey
            && event.key.toLowerCase() === 'k';
        if (!isShortcut) {
            return;
        }
        if (modalElement.classList.contains('show')) {
            event.preventDefault();
            modal.hide();
            return;
        }
        if (document.querySelector('.modal.show')) {
            return;
        }
        event.preventDefault();
        open();
    });
});
