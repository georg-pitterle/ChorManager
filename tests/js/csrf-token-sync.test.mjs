import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const { withCurrentToken, tokenFromResponse, applyToken, install } = require('../../public/js/csrf-token-sync.js');

// Eine offene Seite merkt sich ihren Token beim Laden. Dreht der Server ihn später - etwa weil
// die Sitzung verfiel und per Remember-Me neu entstand -, schickten Editor und Liste weiter den
// alten mit und liefen in "Ungültiger CSRF-Token". Ausgehende Aufrufe bekommen deshalb den
// zuletzt bekannten Token, gleich was der Aufrufer sich einmal gemerkt hat.

test('Kopfzeile als Objekt: der mitgegebene Token wird durch den aktuellen ersetzt', () => {
    const init = { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': 'alt' } };

    const next = withCurrentToken(init, 'neu');

    assert.equal(next.headers['X-CSRF-Token'], 'neu');
    assert.equal(next.headers['X-Requested-With'], 'XMLHttpRequest');
    assert.equal(init.headers['X-CSRF-Token'], 'alt', 'Das Objekt des Aufrufers bleibt unverändert.');
});

test('Kopfzeile als Headers-Objekt: der Token wird ersetzt', () => {
    const next = withCurrentToken({ headers: new Headers({ 'x-csrf-token': 'alt' }) }, 'neu');

    assert.equal(next.headers.get('X-CSRF-Token'), 'neu');
});

test('Formulardaten: das Feld _csrf bekommt den aktuellen Token', () => {
    const body = new FormData();
    body.set('_csrf', 'alt');
    body.set('title', 'Probe');

    const next = withCurrentToken({ method: 'POST', body: body }, 'neu');

    assert.equal(next.body.get('_csrf'), 'neu');
    assert.equal(next.body.get('title'), 'Probe');
});

test('Ein Aufruf ohne Token bekommt keinen untergeschoben', () => {
    const body = new FormData();
    body.set('title', 'Probe');
    const init = { method: 'POST', headers: { Accept: 'application/json' }, body: body };

    const next = withCurrentToken(init, 'neu');

    assert.equal(next.headers['X-CSRF-Token'], undefined);
    assert.equal(next.body.has('_csrf'), false);
});

test('Ohne bekannten Token bleibt der Aufruf, wie er ist', () => {
    const init = { headers: { 'X-CSRF-Token': 'alt' } };

    assert.equal(withCurrentToken(init, ''), init);
    assert.equal(withCurrentToken(undefined, 'neu'), undefined);
});

test('Der Token aus der Antwort wird aus dem Kopf gelesen', () => {
    assert.equal(tokenFromResponse({ headers: new Headers({ 'X-CSRF-Token': 'neu' }) }), 'neu');
    assert.equal(tokenFromResponse({ headers: new Headers() }), '');
    assert.equal(tokenFromResponse(null), '');
});

test('Ein neuer Token landet im meta-Element und in jedem _csrf-Feld der Seite', () => {
    const meta = { content: 'alt', setAttribute(name, value) { this[name] = value; } };
    const fields = [{ value: 'alt' }, { value: 'alt' }];
    const doc = {
        querySelector: (selector) => (selector === 'meta[name="csrf-token"]' ? meta : null),
        querySelectorAll: (selector) => (selector === 'input[name="_csrf"]' ? fields : []),
    };

    applyToken(doc, 'neu');

    assert.equal(meta.content, 'neu');
    assert.deepEqual(fields.map((field) => field.value), ['neu', 'neu']);
});

// Ist nach dem Verfallen der Sitzung die erste Anfrage ein POST, baut der Server die Sitzung per
// Remember-Me auf, lehnt den POST aber ab - sein Token gehört zur alten Sitzung. Die Antwort trägt
// den neuen Token und X-CSRF-Retry; die Seite wiederholt den Aufruf genau einmal.

function fakeWindow(responses) {
    const meta = { content: 'alt', getAttribute(name) { return this[name]; }, setAttribute(name, value) { this[name] = value; } };
    const calls = [];
    const win = {
        document: {
            querySelector: (selector) => (selector === 'meta[name="csrf-token"]' ? meta : null),
            querySelectorAll: () => [],
        },
        fetch: (resource, init) => {
            calls.push({ resource, init });
            return Promise.resolve(responses.shift());
        },
    };

    return { win, calls, meta };
}

function response(status, headers = {}) {
    return { status, ok: status >= 200 && status < 300, headers: new Headers(headers) };
}

test('Nach dem stillen Neuaufbau wird der abgelehnte Aufruf einmal mit dem neuen Token wiederholt', async () => {
    const { win, calls, meta } = fakeWindow([
        response(403, { 'X-CSRF-Retry': '1', 'X-CSRF-Token': 'neu' }),
        response(200, { 'X-CSRF-Token': 'neu' }),
    ]);
    install(win);

    const body = new FormData();
    body.set('_csrf', 'alt');
    const result = await win.fetch('/newsletters/26', { method: 'POST', body, headers: { 'X-CSRF-Token': 'alt' } });

    assert.equal(result.status, 200);
    assert.equal(calls.length, 2);
    assert.equal(calls[1].init.headers['X-CSRF-Token'], 'neu');
    assert.equal(calls[1].init.body.get('_csrf'), 'neu');
    assert.equal(meta.content, 'neu');
});

test('Wiederholt wird höchstens einmal', async () => {
    const { win, calls } = fakeWindow([
        response(403, { 'X-CSRF-Retry': '1', 'X-CSRF-Token': 'neu' }),
        response(403, { 'X-CSRF-Retry': '1', 'X-CSRF-Token': 'neuer' }),
    ]);
    install(win);

    const result = await win.fetch('/newsletters/26', { method: 'POST', headers: { 'X-CSRF-Token': 'alt' } });

    assert.equal(result.status, 403);
    assert.equal(calls.length, 2);
});

test('Ein Aufruf ohne Token wird nicht wiederholt', async () => {
    const { win, calls } = fakeWindow([response(403, { 'X-CSRF-Retry': '1', 'X-CSRF-Token': 'neu' })]);
    install(win);

    await win.fetch('/irgendwo', { method: 'POST' });

    assert.equal(calls.length, 1);
});

test('Eine gewöhnliche Ablehnung ohne X-CSRF-Retry wird nicht wiederholt', async () => {
    const { win, calls } = fakeWindow([response(403, { 'X-CSRF-Token': 'alt' })]);
    install(win);

    await win.fetch('/newsletters/26', { method: 'POST', headers: { 'X-CSRF-Token': 'alt' } });

    assert.equal(calls.length, 1);
});
