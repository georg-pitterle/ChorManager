import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const { helpTopicMatches } = require('../../public/js/help-index.js');

test('leere Eingabe zeigt jedes Thema', () => {
    assert.equal(helpTopicMatches('Anmeldungen (Zu-/Absagen) Termine absage', '   '), true);
});

test('findet Stichwörter des Menüpunkts, ohne Rücksicht auf Groß/klein und Umlaute', () => {
    const text = 'Anmeldungen (Zu-/Absagen) Termine zusage absage teilnahme';
    assert.equal(helpTopicMatches(text, 'Absage'), true);
    assert.equal(helpTopicMatches('Probenmaterial Noten & Dateien übedateien', 'ubedateien'), true);
});

test('mehrere Wörter müssen alle vorkommen, in beliebiger Reihenfolge', () => {
    const text = 'Newsletter-Vorlagen verwalten Kommunikation newsletter vorlagen';
    assert.equal(helpTopicMatches(text, 'vorlagen newsletter'), true);
    assert.equal(helpTopicMatches(text, 'vorlagen budget'), false);
});

test('kein Treffer, wenn das Wort fehlt', () => {
    assert.equal(helpTopicMatches('Rollen und Rechte Administration', 'kassa'), false);
});
