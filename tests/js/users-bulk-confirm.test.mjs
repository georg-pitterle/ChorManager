import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const { confirmTexts } = require('../../public/js/users-bulk-confirm.js');

// Die Rückfragen beginnen mit der Zahl derer, die es wirklich trifft. "80 Mitglieder
// archivieren? 26 davon werden übersprungen" nannte zuerst die größere, falsche Zahl -
// genau im heikelsten Moment.

test('Archivieren: nennt zuerst die tatsächlich Betroffenen, dann die Übersprungenen', () => {
    const texts = confirmTexts({ count: 80, offPage: 0, notArchivable: 26, alreadyInvited: 0 });

    assert.equal(
        texts.archive,
        '54 Mitglieder archivieren? 26 weitere werden übersprungen (eigenes Konto oder erweiterte Rolle). '
            + 'Archivierte können sich nicht mehr anmelden; im Archiv lassen sie sich jederzeit wiederherstellen.'
    );
    assert.equal(texts.archivable, 54);
});

test('Archivieren: ohne Übersprungene kein Nebensatz, Einzahl richtig', () => {
    const texts = confirmTexts({ count: 1, offPage: 0, notArchivable: 0, alreadyInvited: 0 });

    assert.equal(
        texts.archive,
        '1 Mitglied archivieren? '
            + 'Archivierte können sich nicht mehr anmelden; im Archiv lassen sie sich jederzeit wiederherstellen.'
    );
});

test('Einladen: nennt zuerst, wer eine Einladung bekommt, dann wer übersprungen wird', () => {
    const texts = confirmTexts({ count: 80, offPage: 0, notArchivable: 0, alreadyInvited: 16, usesApp: 56 });

    assert.equal(
        texts.invite,
        'Einladung an 24 Mitglieder senden? 56 weitere nutzen die App schon und werden übersprungen. '
            + '16 davon haben schon eine bekommen; ihr alter Link verliert seine Gültigkeit.'
    );
    assert.equal(texts.invitable, 24);
});

test('Einladen: eine einzelne frühere Einladung in Einzahl', () => {
    const texts = confirmTexts({ count: 5, offPage: 0, notArchivable: 0, alreadyInvited: 1, usesApp: 1 });

    assert.equal(
        texts.invite,
        'Einladung an 4 Mitglieder senden? 1 weiteres nutzt die App schon und wird übersprungen. '
            + '1 davon hat schon eine bekommen; ihr alter Link verliert seine Gültigkeit.'
    );
});

test('Wen es nicht trifft, für den gibt es nichts zu bestätigen', () => {
    const texts = confirmTexts({ count: 3, offPage: 0, notArchivable: 3, alreadyInvited: 0, usesApp: 3 });

    assert.equal(texts.archivable, 0);
    assert.equal(texts.invitable, 0);
});

test('Auswahl über mehrere Seiten wird in beiden Texten genannt', () => {
    const texts = confirmTexts({ count: 40, offPage: 15, notArchivable: 0, alreadyInvited: 1 });

    assert.match(texts.archive, /^40 Mitglieder \(davon 15 auf anderen Seiten\) archivieren\?/);
    assert.match(texts.invite, /^Einladung an 40 Mitglieder \(davon 15 auf anderen Seiten\) senden\? 1 davon hat schon eine bekommen/);
});
