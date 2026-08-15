const PHP_ERROR_MARKERS = [
    'Fatal error', 'Parse error', 'Uncaught Error', 'Uncaught Exception',
    'Stack trace:', 'Whoops\\', 'Whoops, looks like', 'PHP Warning', 'PHP Notice',
];

export function checkHtmlForPhpErrors(html) {
    for (const marker of PHP_ERROR_MARKERS) {
        if (html.includes(marker)) {
            return marker;
        }
    }
    return null;
}

// Zeichnet JEDEN console.error und JEDEN pageerror auf, ohne Status-basierte Filterung. Ein
// pauschaler Filter für "Failed to load resource ... status of 401/403" würde auch einen
// echten Autorisierungs-Bug verdecken (z. B. eine AJAX-Anfrage mit gültiger Admin-Session, die
// fälschlich 401/403 liefert) - gerade während der aggressiven Klick-Phase, die viele
// Hintergrund-Requests auslöst. Wer nach einem Lauf konkrete, tatsächlich harmlose
// 401/403-Konsolenmeldungen sieht, muss die genaue Ressourcen-URL identifizieren und sie im
// Aufrufer (crawl.e2e.test.mjs) explizit und mit Begründung ignorieren - nicht hier pauschal
// unterdrücken.
export function attachConsoleWatcher(page) {
    const errors = [];
    page.on('console', (msg) => {
        if (msg.type() === 'error') {
            errors.push(`console.error: ${msg.text()}`);
        }
    });
    page.on('pageerror', (err) => {
        errors.push(`pageerror: ${err.message}`);
    });
    return errors;
}

// Nur für GET-Navigation: 5xx immer Fehler; 4xx unerwartet (außer bewusste Auth-Fälle).
export function checkResponse(response) {
    if (!response) {
        return null;
    }
    const status = response.status();
    if (status >= 500) {
        return `HTTP ${status}`;
    }
    if (status >= 400 && status !== 401 && status !== 403) {
        return `HTTP ${status}`;
    }
    return null;
}

export async function collectInternalLinks(page) {
    return page.$$eval('a[href]', (as) => as
        .map((a) => a.getAttribute('href'))
        .filter((h) => h && h.startsWith('/') && !h.startsWith('//')));
}
