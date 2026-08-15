// Aktionen/URLs, die der aggressive Crawler NIE auslösen darf,
// weil sie den Lauf oder die Umgebung unrettbar zerstören.
export const DENY_PATTERNS = [
    /logout|abmelden|ausloggen/i,
    /backups?\/(restore|delete)|wiederherstell|einspielen/i,
    /rotate.*key|key.*rotation|schlüssel.*rotier/i,
    /users?\/(deactivate|delete)\/1\b/i, // Admin-Account (id 1) nicht deaktivieren/löschen
    /reset.*db|db.*reset|datenbank.*zurücksetzen/i,
];

export function isDenied(actionText, href) {
    const haystack = `${actionText || ''} ${href || ''}`;
    return DENY_PATTERNS.some((re) => re.test(haystack));
}
