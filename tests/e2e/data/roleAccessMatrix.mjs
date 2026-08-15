// Repräsentative, IMMER registrierte (nicht modul-gegatete) geschützte GET-Routen und das je
// Route von RoleMiddleware geforderte Recht (ODER-Semantik über die aufgeführten Spalten).
// Verifiziert gegen src/Routes.php + src/Middleware/RoleMiddleware.php. Modul-gegatete Bereiche
// (Finanzen, Aufgaben, Sponsoring, Budget, Newsletter) sind bewusst NICHT enthalten - sie liefern
// je nach FEATURE_*-Env 404 und würden den Test umgebungsabhängig machen.
export const PROTECTED_ROUTES = [
    { path: '/users', requires: ['can_manage_users', 'can_manage_own_voice_group'] },
    { path: '/attendance', requires: ['can_manage_attendance', 'can_manage_attendance_all'] },
    { path: '/event-types', requires: ['can_manage_events'] },
    { path: '/roles', requires: ['can_manage_roles'] },
    { path: '/settings', requires: ['can_manage_master_data'] },
    { path: '/projects/members', requires: ['can_manage_project_members', 'can_assign_own_voice_group_to_project'] },
    { path: '/song-library', requires: ['can_manage_song_library'] },
    { path: '/admin/mail-queue', requires: ['can_manage_mail_queue'] },
    { path: '/backups', requires: ['can_manage_backups'] },
];

// Ein Passwort für alle Test-Mitglieder (wird nach dem Anlegen direkt in der DB gesetzt, siehe
// steps/authz.mjs - das UI-Formular vergibt kein Passwort).
export const MEMBER_PASSWORD = 'AuthzPass1234!';

// Je geseedeter Rolle genau ein Mitglied mit GENAU dieser einen Rolle. Stimmgruppe/Untergruppe
// sind für den Autorisierungstest irrelevant, werden aber vom createMember-Baustein verlangt -
// deshalb einheitlich Sopran 1. Namen deutsch mit echten Umlauten.
export const ROLE_MEMBERS = [
    { firstName: 'Rolle', lastName: 'Admin', email: 'role.admin@chor.local', role: 'Admin', group: 'Sopran', sub: 'Sopran 1' },
    { firstName: 'Rolle', lastName: 'Vorstand', email: 'role.vorstand@chor.local', role: 'Vorstand', group: 'Sopran', sub: 'Sopran 1' },
    { firstName: 'Rolle', lastName: 'Chorleitung', email: 'role.chorleitung@chor.local', role: 'Chorleitung', group: 'Sopran', sub: 'Sopran 1' },
    { firstName: 'Rolle', lastName: 'Stimmvertretung', email: 'role.stimmvertretung@chor.local', role: 'Stimmvertretung', group: 'Sopran', sub: 'Sopran 1' },
    { firstName: 'Rolle', lastName: 'Ersatzvertretung', email: 'role.ersatzvertretung@chor.local', role: 'Ersatzvertretung', group: 'Sopran', sub: 'Sopran 1' },
    { firstName: 'Rolle', lastName: 'Mitglied', email: 'role.mitglied@chor.local', role: 'Mitglied', group: 'Sopran', sub: 'Sopran 1' },
];
