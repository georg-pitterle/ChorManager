// Hält den CSRF-Token einer offenen Seite auf dem Stand des Servers.
//
// Eine Seite bekommt ihren Token beim Laden: im meta-Element, in den Formularen, und manches
// Skript merkt ihn sich zusätzlich in einer Variablen (newsletters-edit.js etwa). Verfällt die
// Sitzung, während die Seite offen liegt, baut die nächste Anfrage sie per Remember-Me still neu
// auf und dreht dabei den Token. Die Seite erfuhr davon nichts; ihr nächstes Speichern oder
// Versenden lief in "Ungültiger CSRF-Token".
//
// Jetzt nennt jede Antwort den gültigen Token im Kopf X-CSRF-Token (CsrfMiddleware). Dieses
// Skript übernimmt ihn in Seite und Formulare und setzt ihn in jeden ausgehenden Aufruf, der
// einen Token mitschickt - gleich, welchen Stand sich der Aufrufer einmal gemerkt hat. Einen
// Aufruf ohne Token lässt es, wie er ist.
(function (root) {
    'use strict';

    const HEADER = 'X-CSRF-Token';
    const RETRY_HEADER = 'X-CSRF-Retry';
    const FIELD = '_csrf';

    function currentToken(doc) {
        const meta = doc && doc.querySelector('meta[name="csrf-token"]');

        return meta ? meta.getAttribute('content') || '' : '';
    }

    function applyToken(doc, token) {
        if (!doc || !token) {
            return;
        }

        const meta = doc.querySelector('meta[name="csrf-token"]');
        if (meta) {
            meta.setAttribute('content', token);
        }

        doc.querySelectorAll('input[name="' + FIELD + '"]').forEach(function (field) {
            field.value = token;
        });
    }

    function tokenFromResponse(response) {
        if (!response || !response.headers || typeof response.headers.get !== 'function') {
            return '';
        }

        return response.headers.get(HEADER) || '';
    }

    function withCurrentHeader(headers, token) {
        if (typeof Headers !== 'undefined' && headers instanceof Headers) {
            if (!headers.has(HEADER)) {
                return headers;
            }

            const copy = new Headers(headers);
            copy.set(HEADER, token);

            return copy;
        }

        if (Array.isArray(headers)) {
            return headers.map(function (entry) {
                return String(entry[0]).toLowerCase() === HEADER.toLowerCase() ? [entry[0], token] : entry;
            });
        }

        if (headers && typeof headers === 'object') {
            const key = Object.keys(headers).find(function (name) {
                return name.toLowerCase() === HEADER.toLowerCase();
            });
            if (key === undefined) {
                return headers;
            }

            const copy = Object.assign({}, headers);
            copy[key] = token;

            return copy;
        }

        return headers;
    }

    function withCurrentBody(body, token) {
        if (typeof FormData !== 'undefined' && body instanceof FormData && body.has(FIELD)) {
            body.set(FIELD, token);
        }

        return body;
    }

    function withCurrentToken(init, token) {
        if (!init || !token) {
            return init;
        }

        const next = Object.assign({}, init);
        if (init.headers !== undefined) {
            next.headers = withCurrentHeader(init.headers, token);
        }
        if (init.body !== undefined) {
            next.body = withCurrentBody(init.body, token);
        }

        return next;
    }

    function carriesToken(init) {
        if (!init) {
            return false;
        }

        const headers = init.headers;
        if (typeof Headers !== 'undefined' && headers instanceof Headers) {
            if (headers.has(HEADER)) {
                return true;
            }
        } else if (Array.isArray(headers)) {
            if (headers.some(function (entry) { return String(entry[0]).toLowerCase() === HEADER.toLowerCase(); })) {
                return true;
            }
        } else if (headers && typeof headers === 'object') {
            if (Object.keys(headers).some(function (name) { return name.toLowerCase() === HEADER.toLowerCase(); })) {
                return true;
            }
        }

        return typeof FormData !== 'undefined' && init.body instanceof FormData && init.body.has(FIELD);
    }

    // Ist nach dem Verfallen der Sitzung ein POST die erste Anfrage, baut der Server die
    // Sitzung per Remember-Me auf, lehnt den POST aber ab: Sein Token gehört zur alten
    // Sitzung. Die Antwort trägt den neuen Token und X-CSRF-Retry (CsrfMiddleware).
    function asksForRetry(response, init) {
        return Boolean(response)
            && response.status === 403
            && Boolean(response.headers)
            && typeof response.headers.get === 'function'
            && response.headers.get(RETRY_HEADER) === '1'
            && carriesToken(init);
    }

    function install(win) {
        const doc = win.document;

        if (typeof win.fetch === 'function') {
            const originalFetch = win.fetch;
            const send = function (self, resource, init) {
                return originalFetch.call(self, resource, withCurrentToken(init, currentToken(doc)))
                    .then(function (response) {
                        const token = tokenFromResponse(response);
                        if (token && token !== currentToken(doc)) {
                            applyToken(doc, token);
                        }

                        return response;
                    });
            };

            win.fetch = function (resource, init) {
                const self = this;

                // Genau eine Wiederholung: Bittet auch die zweite Antwort darum, stimmt
                // etwas anderes nicht, und die Ablehnung geht an den Aufrufer.
                return send(self, resource, init).then(function (response) {
                    return asksForRetry(response, init) ? send(self, resource, init) : response;
                });
            };
        }

        // Das Freigeben der Newsletter-Sperre beim Verlassen läuft über sendBeacon.
        const nav = win.navigator;
        if (nav && typeof nav.sendBeacon === 'function') {
            const originalBeacon = nav.sendBeacon.bind(nav);
            nav.sendBeacon = function (url, data) {
                const token = currentToken(doc);

                return originalBeacon(url, token ? withCurrentBody(data, token) : data);
            };
        }
    }

    const api = {
        withCurrentToken: withCurrentToken,
        tokenFromResponse: tokenFromResponse,
        applyToken: applyToken,
        currentToken: currentToken,
        install: install,
    };

    root.ChorCsrf = api;
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    }

    if (typeof window !== 'undefined' && window.document && !window.__chorCsrfSyncInstalled) {
        window.__chorCsrfSyncInstalled = true;
        install(window);
    }
})(typeof window !== 'undefined' ? window : globalThis);
