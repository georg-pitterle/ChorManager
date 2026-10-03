// Testdaten für das Szenario navigation-sidebar.e2e.test.mjs.

// Mitglied ohne Sonderrechte: Es darf über die Schnellsuche nur Seiten finden, die auch
// seine Seitenleiste zeigt.
export const PLAIN_MEMBER = {
    firstName: 'Leiste',
    lastName: 'Mitglied',
    email: 'nav.mitglied@chor.local', // naming:ascii
    role: 'Mitglied',
    group: 'Alt',
    sub: 'Alt 1',
};

export const PLAIN_MEMBER_PASSWORD = 'LeistePass1234!';
