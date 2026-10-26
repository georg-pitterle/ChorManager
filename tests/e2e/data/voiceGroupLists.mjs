// Fixtures für das Stimmgruppen-Listen-Szenario. Keine Rolle hält das Recht nach der
// Einrichtung (auch Admin nicht) - das Szenario legt sich eine eigene Rolle an.
export const KEEPER_ROLE = {
    name: 'Listenführung E2E',
    permissions: ['can_keep_voice_group_lists'],
};

export const LIST_PASSWORD = 'ListenPass1234!';

export const LIST_PROJECT = {
    name: 'Frühjahrskonzert Stimmgruppen-Listen',
    description: 'Projekt für das Stimmgruppen-Listen-Szenario.',
    startDate: '2027-03-01',
    endDate: '2027-05-31',
};

// Führt die Sopran-Liste; steht selbst im Projekt und damit in der Liste.
export const SOPRANO_KEEPER = {
    firstName: 'Lotte', lastName: 'Listenführerin', email: 'vgl.keeper.soprano@chor.local',
    role: KEEPER_ROLE.name, group: 'Sopran', sub: 'Sopran 1',
};

// Sopran im Projekt, ohne das Recht: steht in der Liste, sieht selbst keine Listen.
export const SOPRANO_IN_PROJECT = {
    firstName: 'Grete', lastName: 'Größlinger', email: 'vgl.soprano.project@chor.local',
    group: 'Sopran', sub: 'Sopran 2',
};

// Sopran, aber nicht im Projekt: fehlt in der Projektliste.
export const SOPRANO_OUTSIDE_PROJECT = {
    firstName: 'Jutta', lastName: 'Jägerstätter', email: 'vgl.soprano.outside@chor.local',
    group: 'Sopran', sub: 'Sopran 1',
};

// Alt im Projekt: andere Stimmgruppe, fehlt in der Sopran-Liste.
export const ALTO_IN_PROJECT = {
    firstName: 'Ottilie', lastName: 'Öhlinger', email: 'vgl.alto.project@chor.local',
    group: 'Alt', sub: 'Alt 1',
};

// Hat das Recht, aber für Alt: Die Sopran-Liste bleibt für sie unauffindbar (404).
export const ALTO_KEEPER = {
    firstName: 'Ursula', lastName: 'Überreiter', email: 'vgl.keeper.alto@chor.local',
    role: KEEPER_ROLE.name, group: 'Alt', sub: 'Alt 2',
};

export const LIST_TITLE = 'Beitrag Frühjahrskonzert';
export const TICK_COLUMN = { label: 'Beitrag bezahlt', type: 'checkbox' };
export const NOTE_COLUMN = { label: 'Kleidergröße', type: 'note' };
