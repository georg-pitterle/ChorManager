import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const { bannerMode, isIos, isDismissed, rememberDismissal, DISMISS_KEY } = require('../../public/js/install-banner.js');

// Die Leiste lädt zur Installation als Web-App ein. Sie erscheint nur, wenn der Browser
// eine Installation anbietet (Chromium: beforeinstallprompt) oder wenn es ein iPhone/iPad
// ist, wo es nur den Weg über "Teilen" gibt. Wer schon in der installierten App sitzt oder
// "Nicht mehr anzeigen" gewählt hat, sieht sie nie wieder.

const base = { standalone: false, dismissed: false, hasPrompt: false, ios: false };

test('Browser bietet Installation an: Leiste mit Installieren-Knopf', () => {
    assert.equal(bannerMode({ ...base, hasPrompt: true }), 'install');
});

test('iPhone/iPad ohne Installations-API: Leiste mit Anleitung', () => {
    assert.equal(bannerMode({ ...base, ios: true }), 'ios');
});

test('Weder Angebot noch iOS (z. B. Firefox): keine Leiste', () => {
    assert.equal(bannerMode(base), null);
});

test('Schon als App geöffnet: keine Leiste, auch wenn ein Angebot vorliegt', () => {
    assert.equal(bannerMode({ ...base, standalone: true, hasPrompt: true }), null);
    assert.equal(bannerMode({ ...base, standalone: true, ios: true }), null);
});

test('"Nicht mehr anzeigen" gewählt: keine Leiste', () => {
    assert.equal(bannerMode({ ...base, dismissed: true, hasPrompt: true }), null);
    assert.equal(bannerMode({ ...base, dismissed: true, ios: true }), null);
});

test('iOS-Erkennung: iPhone und iPod', () => {
    const ua = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Version/18.0 Mobile Safari/604.1';
    assert.equal(isIos(ua, 5), true);
    assert.equal(isIos('Mozilla/5.0 (iPod touch; CPU iPhone OS 15_0 like Mac OS X)', 5), true);
});

test('iOS-Erkennung: iPad meldet sich als Mac, hat aber Touch', () => {
    const ua = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 Version/18.0 Safari/605.1.15';
    assert.equal(isIos(ua, 5), true);
    assert.equal(isIos(ua, 0), false, 'Ein echter Mac ohne Touch ist kein iPad.');
});

test('iOS-Erkennung: Android und Windows sind kein iOS', () => {
    assert.equal(isIos('Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 Chrome/131.0 Mobile Safari/537.36', 5), false);
    assert.equal(isIos('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/131.0 Safari/537.36', 0), false);
});

function memoryStorage() {
    const data = new Map();
    return {
        getItem: (key) => (data.has(key) ? data.get(key) : null),
        setItem: (key, value) => data.set(key, String(value)),
    };
}

test('"Nicht mehr anzeigen" wird gemerkt', () => {
    const storage = memoryStorage();
    assert.equal(isDismissed(storage), false);

    rememberDismissal(storage);

    assert.equal(isDismissed(storage), true);
    assert.equal(storage.getItem(DISMISS_KEY), '1');
});

test('Gesperrter Speicher (privates Fenster) ist kein Fehler', () => {
    const blocked = {
        getItem: () => { throw new Error('SecurityError'); },
        setItem: () => { throw new Error('SecurityError'); },
    };

    assert.equal(isDismissed(blocked), false);
    assert.doesNotThrow(() => rememberDismissal(blocked));
    assert.equal(isDismissed(null), false);
});
