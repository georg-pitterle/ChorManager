import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const { saveFeedback } = require('../../public/js/newsletters-edit.js');

// Der Editor speichert alle 30 Sekunden still im Hintergrund. Wer lange schreibt und dann auf
// "Speichern" klickt, hat seinen Stand meist schon gesichert - der Klick brach dann wortlos ab,
// und es sah aus, als täte der Knopf nichts. Ein Klick bekommt jetzt immer eine Antwort.

test('Newsletter speichern: Klick ohne Änderung seit dem letzten Sichern meldet trotzdem Erfolg', () => {
    assert.deepEqual(saveFeedback('unchanged', true), { type: 'success', message: 'Newsletter gespeichert' });
});

test('Newsletter speichern: misslungener Klick meldet einen Fehler', () => {
    assert.deepEqual(saveFeedback('failed', true), { type: 'danger', message: 'Fehler beim Speichern' });
});

test('Newsletter speichern: das Speichern im Hintergrund bleibt still, auch wenn es misslingt', () => {
    assert.equal(saveFeedback('unchanged', false), null);
    assert.equal(saveFeedback('failed', false), null);
});
