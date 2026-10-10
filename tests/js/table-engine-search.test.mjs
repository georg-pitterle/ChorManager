import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const {
    parseSearchTokens,
    textMatchesQuery,
    isPlaceholderRow,
    formatResultCount,
    countActiveFilters,
    shouldShowPageControls,
    isEmptyCellText,
} = require('../../public/js/table-engine.js');

test('Suche: Leerzeichen innerhalb der Eingabe bleiben erhalten und trennen Wörter', () => {
    assert.deepEqual(parseSearchTokens('Clara Fi'), ['clara', 'fi']);
    assert.deepEqual(parseSearchTokens('  Clara   Fischer  '), ['clara', 'fischer']);
});

test('Suche: leere oder nur aus Leerzeichen bestehende Eingabe liefert keine Tokens', () => {
    assert.deepEqual(parseSearchTokens(''), []);
    assert.deepEqual(parseSearchTokens('   '), []);
    assert.deepEqual(parseSearchTokens(null), []);
});

test('Suche: Vor- und Nachname in beliebiger Reihenfolge treffen die Zeile', () => {
    const text = 'Clara Fischer Sopran 1 clara@chor.local';
    assert.equal(textMatchesQuery(text, 'Clara Fi'), true);
    assert.equal(textMatchesQuery(text, 'fischer clara'), true);
    assert.equal(textMatchesQuery(text, 'clara müller'), false);
});

test('Suche: Groß- und Kleinschreibung spielt keine Rolle, leere Suche trifft alles', () => {
    assert.equal(textMatchesQuery('Ave Verum Corpus', 'AVE verum'), true);
    assert.equal(textMatchesQuery('Ave Verum Corpus', ''), true);
    assert.equal(textMatchesQuery('Ave Verum Corpus', '   '), true);
});

test('Platzhalterzeile: einzelne gedämpfte Zelle mit colspan ist ein Leerzustand', () => {
    const placeholder = {
        children: [{ colSpan: 5, classList: { contains: (name) => name === 'text-muted' } }],
    };
    assert.equal(isPlaceholderRow(placeholder), true);
});

test('Platzhalterzeile: normale Datenzeilen und Detailzeilen zählen nicht', () => {
    const noMuted = { colSpan: 8, classList: { contains: () => false } };
    const plain = { colSpan: 1, classList: { contains: () => true } };
    assert.equal(isPlaceholderRow({ children: [noMuted] }), false);
    assert.equal(isPlaceholderRow({ children: [plain, plain] }), false);
    assert.equal(isPlaceholderRow({ children: [plain] }), false);
});

test('Trefferzahl: alle Zeilen sichtbar nennt nur die Gesamtzahl', () => {
    assert.equal(formatResultCount(81, 81), '81 Einträge');
    assert.equal(formatResultCount(1, 1), '1 Eintrag');
});

test('Trefferzahl: gefiltert nennt Treffer von Gesamt', () => {
    assert.equal(formatResultCount(12, 81), '12 von 81 Einträgen');
    assert.equal(formatResultCount(1, 81), '1 von 81 Einträgen');
    assert.equal(formatResultCount(0, 81), '0 von 81 Einträgen');
});

test('Filterzahl: zählt nur belegte Plugin-Filter', () => {
    assert.equal(countActiveFilters({}), 0);
    assert.equal(countActiveFilters(null), 0);
    assert.equal(countActiveFilters({ usersManage: { role: '', voice: '' } }), 0);
    assert.equal(countActiveFilters({ usersManage: { role: 'admin', voice: '' } }), 1);
    assert.equal(countActiveFilters({ usersManage: { role: 'admin' }, usersGroup: { group: 'alt' } }), 2);
});

test('Blätterleiste: erst bei mehr als einer Seite sichtbar', () => {
    assert.equal(shouldShowPageControls(1), false);
    assert.equal(shouldShowPageControls(0), false);
    assert.equal(shouldShowPageControls(2), true);
});

test('Leere Zellen: Strich und Gedankenstrich gelten als leer, Inhalt nicht', () => {
    assert.equal(isEmptyCellText(''), true);
    assert.equal(isEmptyCellText('  '), true);
    assert.equal(isEmptyCellText('-'), true);
    assert.equal(isEmptyCellText('–'), true);
    assert.equal(isEmptyCellText(' — '), true);
    assert.equal(isEmptyCellText('Nein'), false);
    assert.equal(isEmptyCellText('0'), false);
    assert.equal(isEmptyCellText('- 12,50 €'), false);
});
