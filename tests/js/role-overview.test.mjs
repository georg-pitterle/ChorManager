import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const {
    normalizeQuery,
    matchPermissions,
    autoExpandKeys,
    resolveInitialRoleId,
} = require('../../public/js/role-overview.js');

const permissions = [
    { key: 'can_manage_budget', label: 'Budget verwalten' },
    { key: 'can_manage_own_voice_group', label: 'Eigene Stimmgruppe verwalten' },
    { key: 'can_assign_own_voice_group_to_project', label: 'Eigene Stimmgruppe ins Projekt zuweisen' },
    { key: 'can_manage_tasks', label: 'Projektplanung (Aufgaben)' },
    { key: 'can_manage_files', label: 'Dateiverwaltung verwalten' },
    { key: 'can_manage_users', label: 'Mitgliederverwaltung erlauben' },
];

test('normalizeQuery: Leerzeichen, Groß/klein und Umlaute spielen keine Rolle', () => {
    assert.equal(normalizeQuery('  Führ '), 'fuhr');
    assert.equal(normalizeQuery('STIMMGRUPPE'), 'stimmgruppe');
    assert.equal(normalizeQuery('   '), '');
});

test('leere Suche liefert alle Rechte in Originalreihenfolge', () => {
    assert.deepEqual(matchPermissions(permissions, '  '), permissions.map((p) => p.key));
});

test('Suche trifft Teilwörter im Label', () => {
    assert.deepEqual(matchPermissions(permissions, 'stimmgruppe'), [
        'can_manage_own_voice_group',
        'can_assign_own_voice_group_to_project',
    ]);
    assert.deepEqual(matchPermissions(permissions, 'verwalt'), [
        'can_manage_budget',
        'can_manage_own_voice_group',
        'can_manage_files',
        'can_manage_users',
    ]);
    assert.deepEqual(matchPermissions(permissions, 'gibtsnicht'), []);
});

test('höchstens drei Treffer klappen automatisch auf, leere Suche nie', () => {
    assert.deepEqual(autoExpandKeys(['a', 'b', 'c'], 'x'), ['a', 'b', 'c']);
    assert.deepEqual(autoExpandKeys(['a', 'b', 'c', 'd'], 'x'), []);
    assert.deepEqual(autoExpandKeys(['a'], '   '), []);
    assert.deepEqual(autoExpandKeys([], 'x'), []);
});

test('Startrolle: Hash vor gemerkter Rolle vor erster Rolle', () => {
    const ids = ['1', '5', '9'];
    assert.equal(resolveInitialRoleId(ids, '#role-9', '5'), '9');
    assert.equal(resolveInitialRoleId(ids, '', '5'), '5');
    assert.equal(resolveInitialRoleId(ids, '', null), '1');
});

test('Startrolle: veralteter Hash oder gemerkte gelöschte Rolle fallen auf die erste zurück', () => {
    const ids = ['1', '5'];
    assert.equal(resolveInitialRoleId(ids, '#role-42', '77'), '1');
    assert.equal(resolveInitialRoleId(ids, '#role-abc', null), '1');
    assert.equal(resolveInitialRoleId(ids, '#other', '5'), '5');
    assert.equal(resolveInitialRoleId([], '#role-1', '1'), null);
});
