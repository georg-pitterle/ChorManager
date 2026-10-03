import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const {
    normalize,
    rankNavigationEntries,
    readRecentUrls,
    resolveRecentEntries,
} = require('../../public/js/navigation-search-rank.js');

const entries = [
    { label: 'Start', url: '/dashboard', icon: 'bi-house', section: '', keywords: ['dashboard'] },
    { label: 'Kassa', url: '/finances', icon: 'bi-bank', section: 'Finanzen', keywords: ['kassabuch', 'buchung'] },
    { label: 'Budget', url: '/budget', icon: 'bi-calculator', section: 'Finanzen', keywords: ['planung'] },
    { label: 'Projektübersicht', url: '/evaluations/project-members', icon: 'bi-person-lines-fill', section: 'Mitglieder & Projekte', keywords: ['projektmitglieder'] },
    { label: 'Projekte', url: '/projects', icon: 'bi-folder-fill', section: 'Mitglieder & Projekte', keywords: ['planung'] },
];

test('normalize: Groß/klein und Akzente spielen keine Rolle', () => {
    assert.equal(normalize('  Projektübersicht '), 'projektubersicht');
    assert.equal(normalize('ÄÖÜ'), 'aou');
});

test('leere Eingabe liefert keine Treffer', () => {
    assert.deepEqual(rankNavigationEntries(entries, '   '), []);
});

test('Name am Anfang vor Name enthalten vor Abschnitt vor Stichwort', () => {
    const hits = rankNavigationEntries(entries, 'pro');
    assert.deepEqual(hits.map((h) => h.entry.url), ['/evaluations/project-members', '/projects']);

    const mixed = rankNavigationEntries(entries, 'planung');
    assert.deepEqual(mixed.map((h) => [h.entry.url, h.keyword]), [['/budget', 'planung'], ['/projects', 'planung']]);

    const order = rankNavigationEntries(
        [
            { label: 'Alpha', url: '/a', icon: '', section: 'Kasse', keywords: [] },
            { label: 'Beta', url: '/b', icon: '', section: '', keywords: ['kasse'] },
            { label: 'Kassenbericht', url: '/c', icon: '', section: '', keywords: [] },
            { label: 'Die Kasse', url: '/d', icon: '', section: '', keywords: [] },
        ],
        'kasse'
    );
    assert.deepEqual(order.map((h) => h.entry.url), ['/c', '/d', '/a', '/b']);
});

test('Stichwort-Treffer nennen das Stichwort, Namenstreffer nicht', () => {
    const [hit] = rankNavigationEntries(entries, 'kassab');
    assert.equal(hit.entry.url, '/finances');
    assert.equal(hit.keyword, 'kassabuch');

    const [byName] = rankNavigationEntries(entries, 'kas');
    assert.equal(byName.keyword, null);
});

test('Umlaute ohne Punkte finden', () => {
    const [hit] = rankNavigationEntries(entries, 'ubersicht');
    assert.equal(hit.entry.url, '/evaluations/project-members');
});

test('readRecentUrls verträgt kaputten Speicher', () => {
    assert.deepEqual(readRecentUrls(null), []);
    assert.deepEqual(readRecentUrls('{'), []);
    assert.deepEqual(readRecentUrls('{"a":1}'), []);
    assert.deepEqual(readRecentUrls('["/a", 3, null, "/b"]'), ['/a', '/b']);
});

test('resolveRecentEntries zeigt nur, was noch in der Leiste steht', () => {
    const resolved = resolveRecentEntries(entries, ['/roles', '/budget', '/finances']);
    assert.deepEqual(resolved.map((e) => e.url), ['/budget', '/finances']);
});
