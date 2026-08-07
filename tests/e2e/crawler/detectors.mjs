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
// pauschaler Filter fuer "Failed to load resource ... status of 401/403" wuerde auch einen
// echten Autorisierungs-Bug verdecken (z. B. eine AJAX-Anfrage mit gueltiger Admin-Session, die
// faelschlich 401/403 liefert) - gerade waehrend der aggressiven Klick-Phase, die viele
// Hintergrund-Requests ausloest. Wer nach einem Lauf konkrete, tatsaechlich harmlose
// 401/403-Konsolenmeldungen sieht, muss die genaue Ressourcen-URL identifizieren und sie im
// Aufrufer (crawl.e2e.test.mjs) explizit und mit Begruendung ignorieren - nicht hier pauschal
// unterdruecken.
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

// Nur fuer GET-Navigation: 5xx immer Fehler; 4xx unerwartet (ausser bewusste Auth-Faelle).
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
