import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const { progressLabel, cellState, noteChanged, progressPercent } = require('../../public/js/voice-group-lists.js');

// Häkchen und Notizen speichern ohne Neuladen. Was die Zelle danach zeigt, kommt aus der
// Antwort des Servers - und geht etwas schief, bleibt der alte Stand stehen, mit Meldung.

const previous = { checked: false, note: null, tooltip: null };

test('Fortschritt: erledigt / gesamt', () => {
    assert.equal(progressLabel({ done: 18, total: 30 }), '18 / 30');
});

test('Erfolg übernimmt Häkchen, Tooltip und Notiz der Antwort', () => {
    const state = cellState(
        { ok: true, checked: true, note: null, tooltip: 'Abgehakt von Anna Alt am 26.10.2026' },
        previous
    );

    assert.deepEqual(state, {
        checked: true,
        note: null,
        tooltip: 'Abgehakt von Anna Alt am 26.10.2026',
        error: null,
    });
});

test('Fehler behält den alten Stand und nennt die Meldung', () => {
    const before = { checked: true, note: null, tooltip: 'Abgehakt von Anna Alt am 01.10.2026' };
    const state = cellState({ ok: false, error: 'Die Liste ist archiviert und nur lesbar.' }, before);

    assert.deepEqual(state, { ...before, error: 'Die Liste ist archiviert und nur lesbar.' });
});

test('Kaputte Antwort behält den alten Stand mit Standardmeldung', () => {
    assert.deepEqual(cellState(null, previous), {
        ...previous,
        error: 'Speichern fehlgeschlagen. Bitte erneut versuchen.',
    });
    assert.equal(cellState({ ok: false }, previous).error, 'Speichern fehlgeschlagen. Bitte erneut versuchen.');
});

// Eine Notiz speichert beim Verlassen des Feldes. Ob es etwas zu speichern gibt, entscheidet
// derselbe Vergleich, der den Speichern-Knopf zeigt und vor dem Verlassen der Seite warnt.
test('Notiz: nur Leerzeichen am Rand sind keine Änderung', () => {
    assert.equal(noteChanged('  M ', 'M'), false);
    assert.equal(noteChanged('L', 'M'), true);
    assert.equal(noteChanged('', 'M'), true);
    assert.equal(noteChanged('', null), false);
});

test('Fortschrittsbalken: Prozent gerundet, leere Liste ist 0', () => {
    assert.equal(progressPercent({ done: 1, total: 3 }), 33);
    assert.equal(progressPercent({ done: 0, total: 0 }), 0);
    assert.equal(progressPercent({ done: 24, total: 24 }), 100);
});
