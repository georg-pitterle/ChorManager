// Newsletter-Archiv: Leseliste mit Suche und einem Lesedialog, in dem man zum nächsten
// Newsletter weiterblättert, ohne den Dialog zu schließen.
document.addEventListener('DOMContentLoaded', function () {
    const list = document.getElementById('newsletterArchiveList');
    const modalElement = document.getElementById('newsletterReaderModal');

    if (!list || !modalElement || typeof bootstrap === 'undefined') {
        return;
    }

    const modal = bootstrap.Modal.getOrCreateInstance(modalElement);
    const titleElement = modalElement.querySelector('[data-reader-title]');
    const metaElement = modalElement.querySelector('[data-reader-meta]');
    const contentElement = modalElement.querySelector('[data-reader-content]');
    const positionElement = modalElement.querySelector('[data-reader-position]');
    const newerButton = modalElement.querySelector('[data-reader-step="newer"]');
    const olderButton = modalElement.querySelector('[data-reader-step="older"]');
    const searchInput = document.getElementById('newsletterArchiveSearch');
    const noMatchElement = document.querySelector('[data-archive-no-match]');

    let currentLink = null;
    // Blättert man schneller, als der Server antwortet, darf nur die letzte Antwort landen.
    let requestCounter = 0;

    // Geblättert wird in der Reihenfolge der Liste, und zwar nur durch das, was die Suche
    // gerade stehen lässt.
    function visibleLinks() {
        return Array.from(list.querySelectorAll('[data-newsletter-open]')).filter(function (link) {
            const item = link.closest('[data-archive-item]');
            return !item || !item.hidden;
        });
    }

    function updateNavigation() {
        const links = visibleLinks();
        const index = links.indexOf(currentLink);

        newerButton.disabled = index <= 0;
        olderButton.disabled = index < 0 || index >= links.length - 1;
        positionElement.textContent = index < 0 ? '' : (index + 1) + ' von ' + links.length;
    }

    function renderStatus(message) {
        const status = document.createElement('div');
        status.className = 'newsletter-reader__status';

        const spinner = document.createElement('span');
        spinner.className = 'spinner-border spinner-border-sm';
        spinner.setAttribute('aria-hidden', 'true');

        status.append(spinner, document.createTextNode(' ' + message));
        contentElement.replaceChildren(status);
    }

    function renderError(link) {
        const box = document.createElement('div');
        box.className = 'newsletter-reader__status newsletter-reader__status--error';

        const text = document.createElement('p');
        text.className = 'mb-3';
        text.textContent = 'Der Newsletter konnte nicht geladen werden. Vielleicht ist die Verbindung kurz weg.';

        const retry = document.createElement('button');
        retry.type = 'button';
        retry.className = 'btn btn-primary';
        retry.textContent = 'Erneut versuchen';
        retry.addEventListener('click', function () {
            show(link);
        });

        const pageLink = document.createElement('a');
        pageLink.className = 'btn btn-link';
        pageLink.href = link.getAttribute('href') || '#';
        pageLink.textContent = 'Als eigene Seite öffnen';

        const actions = document.createElement('div');
        actions.className = 'newsletter-reader__actions';
        actions.append(retry, pageLink);

        box.append(text, actions);
        contentElement.replaceChildren(box);
    }

    async function show(link) {
        currentLink = link;
        titleElement.textContent = link.dataset.newsletterSubject || 'Newsletter';
        metaElement.textContent = link.dataset.newsletterMeta || '';
        updateNavigation();
        renderStatus('Newsletter wird geladen …');

        const requestId = ++requestCounter;

        try {
            const response = await fetch(link.dataset.newsletterUrl || '', {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });

            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }

            const html = await response.text();
            if (requestId !== requestCounter) {
                return;
            }

            const parsed = new DOMParser().parseFromString(html, 'text/html');
            const reader = parsed.querySelector('[data-newsletter-reader]');
            if (!reader) {
                throw new Error('Lesebereich fehlt in der Antwort');
            }

            contentElement.replaceChildren(document.importNode(reader, true));
        } catch (error) {
            if (requestId !== requestCounter) {
                return;
            }

            console.error('Newsletter-Archiv: Laden fehlgeschlagen', error);
            renderError(link);
        }
    }

    function step(direction) {
        const links = visibleLinks();
        const index = links.indexOf(currentLink);
        const target = links[index + (direction === 'older' ? 1 : -1)];

        if (index >= 0 && target) {
            show(target);
        }
    }

    list.addEventListener('click', function (event) {
        const link = event.target.closest('[data-newsletter-open]');
        // Mit Strg-, Cmd- oder Mittelklick bleibt es beim normalen Link auf die eigene Seite.
        if (!link || event.ctrlKey || event.metaKey || event.shiftKey || event.button !== 0) {
            return;
        }

        event.preventDefault();
        show(link);
        modal.show();
    });

    newerButton.addEventListener('click', function () {
        step('newer');
    });

    olderButton.addEventListener('click', function () {
        step('older');
    });

    modalElement.addEventListener('keydown', function (event) {
        if (event.altKey || event.ctrlKey || event.metaKey) {
            return;
        }

        if (event.key === 'ArrowLeft') {
            event.preventDefault();
            step('newer');
        } else if (event.key === 'ArrowRight') {
            event.preventDefault();
            step('older');
        }
    });

    modalElement.addEventListener('hidden.bs.modal', function () {
        requestCounter++;
        contentElement.replaceChildren();

        if (currentLink) {
            currentLink.focus();
        }
    });

    if (searchInput) {
        searchInput.addEventListener('input', function () {
            const query = searchInput.value.trim().toLowerCase();
            let visibleCount = 0;

            list.querySelectorAll('[data-archive-month]').forEach(function (month) {
                let monthVisible = 0;

                month.querySelectorAll('[data-archive-item]').forEach(function (item) {
                    const matches = query === '' || (item.dataset.search || '').includes(query);
                    item.hidden = !matches;
                    if (matches) {
                        monthVisible++;
                    }
                });

                month.hidden = monthVisible === 0;
                visibleCount += monthVisible;
            });

            if (noMatchElement) {
                noMatchElement.hidden = visibleCount > 0;
            }
        });
    }
});
