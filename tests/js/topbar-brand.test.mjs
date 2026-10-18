import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const { nameOverflows, updateBrandName } = require('../../public/js/topbar-brand.js');

// Ein angeschnittener Chorname ("Chor-Manag…") sagt weniger als gar keiner: Passt
// er nicht ganz neben Logo und Symbole, steht nur das Logo da.

test('Der Name passt: er bleibt stehen', () => {
    assert.equal(nameOverflows({ scrollWidth: 180, clientWidth: 180 }), false);
});

test('Der Name ist breiter als sein Platz: er wird ausgeblendet', () => {
    assert.equal(nameOverflows({ scrollWidth: 322, clientWidth: 60 }), true);
});

test('Ein Pixel Rundung gilt nicht als Überlauf', () => {
    assert.equal(nameOverflows({ scrollWidth: 181, clientWidth: 180 }), false);
});

test('Gemessen wird ohne Ausblendung, damit der Name zurückkommt, sobald wieder Platz ist', () => {
    const classes = new Set(['is-overflowing']);
    const el = {
        classList: {
            add: (c) => classes.add(c),
            remove: (c) => classes.delete(c),
            toggle: (c, on) => (on ? classes.add(c) : classes.delete(c)),
        },
        // Ausgeblendet hätte das Element die Breite 0 - gemessen werden muss sichtbar.
        get scrollWidth() { return classes.has('is-overflowing') ? 0 : 150; },
        get clientWidth() { return classes.has('is-overflowing') ? 0 : 200; },
    };

    updateBrandName(el);

    assert.equal(classes.has('is-overflowing'), false);
});
