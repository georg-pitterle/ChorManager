// Deterministische Testdaten fuer Bootstrap. Reihenfolge = kanonische SATB-Ordnung.
export const ADMIN = {
    firstName: 'Admin',
    lastName: 'Test',
    email: 'admin@chor.local',
    password: 'TestPass1234!',
};

export const VOICE_GROUPS = ['Sopran', 'Alt', 'Tenor', 'Bass'];
// Die Untergruppen sind produktseitig per Migration geseedet
// (db/migrations/20260314130000_initial.php), nicht vom Testcode angelegt.
export const SUB_VOICES = ['Sopran 1', 'Sopran 2', 'Alt 1', 'Alt 2', 'Tenor 1', 'Tenor 2', 'Bass 1', 'Bass 2'];

// Ein Mitglied je Untergruppe (SATB x 2), deutsche Namen mit echten Umlauten.
export const MEMBERS = [
    { firstName: 'Anna', lastName: 'Bäcker', email: 'anna.baecker@chor.local', group: 'Sopran', sub: 'Sopran 1' },
    { firstName: 'Sofia', lastName: 'Möller', email: 'sofia.moeller@chor.local', group: 'Sopran', sub: 'Sopran 2' },
    { firstName: 'Lena', lastName: 'Schröder', email: 'lena.schroeder@chor.local', group: 'Alt', sub: 'Alt 1' },
    { firstName: 'Klara', lastName: 'Günther', email: 'klara.guenther@chor.local', group: 'Alt', sub: 'Alt 2' },
    { firstName: 'Jonas', lastName: 'Färber', email: 'jonas.faerber@chor.local', group: 'Tenor', sub: 'Tenor 1' },
    { firstName: 'Paul', lastName: 'Löwe', email: 'paul.loewe@chor.local', group: 'Tenor', sub: 'Tenor 2' },
    { firstName: 'Max', lastName: 'Kühn', email: 'max.kuehn@chor.local', group: 'Bass', sub: 'Bass 1' },
    { firstName: 'Erik', lastName: 'Bäumer', email: 'erik.baeumer@chor.local', group: 'Bass', sub: 'Bass 2' },
];

export const PROJECT = {
    name: 'Sommerkonzert 2026',
    description: 'Automatisch angelegtes E2E-Testprojekt.',
    startDate: '2026-06-01',
    endDate: '2026-07-31',
};
