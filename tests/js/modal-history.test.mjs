import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const { createModalHistory } = require('../../public/js/modal-history.js');

// Auf dem Telefon füllt die Anhang-Vorschau fast den ganzen Bildschirm und wirkt wie
// eine eigene Seite. Ein Mitglied schloss sie mit "Zurück" - und verließ damit die
// ganze Seite, weil das Modal keinen Eintrag im Verlauf hatte.

function fakeHistory() {
    const calls = [];
    return {
        calls,
        pushState(state) { calls.push(['push', state]); },
        back() { calls.push(['back']); },
    };
}

test('Öffnen legt genau einen Verlaufseintrag an, auch bei doppeltem Öffnen', () => {
    const history = fakeHistory();
    const modal = createModalHistory({ history, hide: () => {} });

    modal.opened();
    modal.opened();

    assert.deepEqual(history.calls.map((c) => c[0]), ['push']);
});

test('"Zurück" schließt das Modal und lässt den Verlauf in Ruhe', () => {
    const history = fakeHistory();
    let hidden = 0;
    const modal = createModalHistory({ history, hide: () => { hidden++; } });

    modal.opened();
    modal.popped();
    // Bootstrap meldet danach "geschlossen" - das darf kein zweites Zurück auslösen.
    modal.closed();

    assert.equal(hidden, 1);
    assert.deepEqual(history.calls.map((c) => c[0]), ['push']);
});

test('Schließen per Knopf nimmt den eigenen Eintrag wieder zurück', () => {
    const history = fakeHistory();
    let hidden = 0;
    const modal = createModalHistory({ history, hide: () => { hidden++; } });

    modal.opened();
    modal.closed();
    // Das Zurück erzeugt seinerseits ein popstate - das darf nichts mehr schließen.
    modal.popped();

    assert.equal(hidden, 0);
    assert.deepEqual(history.calls.map((c) => c[0]), ['push', 'back']);
});

test('Ein fremdes popstate bei geschlossenem Modal bleibt folgenlos', () => {
    const history = fakeHistory();
    let hidden = 0;
    const modal = createModalHistory({ history, hide: () => { hidden++; } });

    modal.popped();
    modal.closed();

    assert.equal(hidden, 0);
    assert.deepEqual(history.calls, []);
});

test('Nach dem Schließen legt erneutes Öffnen wieder einen Eintrag an', () => {
    const history = fakeHistory();
    const modal = createModalHistory({ history, hide: () => {} });

    modal.opened();
    modal.closed();
    modal.opened();

    assert.deepEqual(history.calls.map((c) => c[0]), ['push', 'back', 'push']);
});
