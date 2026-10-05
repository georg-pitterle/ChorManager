import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const { actionFor, hostMessage } = require('../../public/js/office-editor-messages.js');

const office = 'https://office.example.test';

test('Frame_Ready von Collabora heißt: bereit für Host_PostmessageReady', () => {
    const data = JSON.stringify({ MessageId: 'App_LoadingStatus', Values: { Status: 'Frame_Ready' } });
    assert.equal(actionFor(office, data, office), 'ready');
});

test('UI_Close heißt: Editor schließen', () => {
    assert.equal(actionFor(office, JSON.stringify({ MessageId: 'UI_Close' }), office), 'close');
});

test('Nachrichten fremder Herkunft werden ignoriert', () => {
    assert.equal(actionFor('https://evil.example.test', JSON.stringify({ MessageId: 'UI_Close' }), office), null);
});

test('Kaputte oder unbekannte Daten werden ignoriert', () => {
    assert.equal(actionFor(office, '{kaputt', office), null);
    assert.equal(actionFor(office, null, office), null);
    assert.equal(actionFor(office, JSON.stringify({ MessageId: 'Doc_ModifiedStatus' }), office), null);
});

test('Ohne bekannten Office-Ursprung wird nichts angenommen', () => {
    assert.equal(actionFor('', JSON.stringify({ MessageId: 'UI_Close' }), ''), null);
});

test('Host-Nachricht ist JSON mit MessageId', () => {
    assert.equal(JSON.parse(hostMessage('Host_PostmessageReady')).MessageId, 'Host_PostmessageReady');
});
