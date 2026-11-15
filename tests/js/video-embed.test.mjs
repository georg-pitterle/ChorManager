import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const { chooseVideo } = require('../../public/js/video-embed.js');

// Das Mitglieder-Video gibt es quer und im Hochformat. Auf schmalen Bildschirmen wird das
// Hochformat geladen, sonst das Querformat; fehlt eines, gilt das andere nicht als Ersatz
// für die falsche Seite, sondern das Querformat bleibt der Normalfall.

test('breiter Bildschirm: Querformat', () => {
    assert.deepEqual(chooseVideo({ videoId: 'aB3_dE-6gH9', videoPortraitId: 'Pp7_qQ-5rR3' }, false),
        { id: 'aB3_dE-6gH9', portrait: false });
});

test('schmaler Bildschirm mit Hochformat: Hochformat', () => {
    assert.deepEqual(chooseVideo({ videoId: 'aB3_dE-6gH9', videoPortraitId: 'Pp7_qQ-5rR3' }, true),
        { id: 'Pp7_qQ-5rR3', portrait: true });
});

test('schmaler Bildschirm ohne Hochformat: Querformat', () => {
    assert.deepEqual(chooseVideo({ videoId: 'aB3_dE-6gH9' }, true), { id: 'aB3_dE-6gH9', portrait: false });
});

test('kaputte Kennung wird nicht geladen', () => {
    assert.equal(chooseVideo({ videoId: '"><x' }, false), null);
    assert.deepEqual(chooseVideo({ videoId: 'aB3_dE-6gH9', videoPortraitId: 'kaputt' }, true),
        { id: 'aB3_dE-6gH9', portrait: false });
});
