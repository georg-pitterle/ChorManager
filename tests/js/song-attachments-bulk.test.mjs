import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const { confirmText, buttonLabel } = require('../../public/js/song-attachments-bulk.js');

// Die Rückfrage nennt die Zahl: Wer 24 Anhänge löscht, soll vorher sehen, dass es 24 sind.

test('Rückfrage nennt die Zahl der Anhänge', () => {
    assert.equal(confirmText(24), '24 Anhänge wirklich löschen? Das lässt sich nicht rückgängig machen.');
});

test('Rückfrage steht bei einem Anhang im Singular', () => {
    assert.equal(confirmText(1), '1 Anhang wirklich löschen? Das lässt sich nicht rückgängig machen.');
});

test('Knopf zeigt die Auswahl nur, wenn etwas ausgewählt ist', () => {
    assert.equal(buttonLabel(0), 'Ausgewählte löschen');
    assert.equal(buttonLabel(3), 'Ausgewählte löschen (3)');
});
