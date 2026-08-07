// Aktionen/URLs, die der aggressive Crawler NIE ausloesen darf,
// weil sie den Lauf oder die Umgebung unrettbar zerstoeren.
export const DENY_PATTERNS = [
    /logout|abmelden|ausloggen/i,
    /backups?\/(restore|delete)|wiederherstell|einspielen/i,
    /rotate.*key|key.*rotation|schluessel.*rotier/i,
    /users?\/(deactivate|delete)\/1\b/i, // Admin-Account (id 1) nicht deaktivieren/loeschen
    /reset.*db|db.*reset|datenbank.*zuruecksetzen/i,
];

export function isDenied(actionText, href) {
    const haystack = `${actionText || ''} ${href || ''}`;
    return DENY_PATTERNS.some((re) => re.test(haystack));
}
