import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const { isUntouched } = require('../../public/js/sponsorship-form.js');

// Ein Vorschlag (Projektzeitraum, Paketvorgaben) überschreibt nie eine eigene Eingabe. Als
// unberührt gilt ein leeres Feld oder eines, das noch den letzten Vorschlag trägt. Der Betrag
// einer gespeicherten Anfrage steht als "0.00" im Bearbeiten-Dialog - seit das Paket erst bei
// der Zusage wählbar ist, ist genau das der Regelfall, und der Mindestbetrag kam nie an.

test('leeres Feld ist unberührt', () => {
    assert.equal(isUntouched('', '', false), true);
});

test('Feld mit dem letzten Vorschlag ist unberührt', () => {
    assert.equal(isUntouched('2500.00', '2500.00', true), true);
});

test('eigene Eingabe ist berührt', () => {
    assert.equal(isUntouched('1800', '2500.00', true), false);
    assert.equal(isUntouched('Logo im Heft', '', false), false);
});

test('Betrag null zählt beim Betrag als leer', () => {
    assert.equal(isUntouched('0.00', '', true), true);
    assert.equal(isUntouched('0', '', true), true);
});

test('null zählt nur beim Betrag als leer, nicht bei Textfeldern', () => {
    assert.equal(isUntouched('0', '', false), false);
});
