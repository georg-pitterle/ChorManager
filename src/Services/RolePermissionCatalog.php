<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Alle Rollenrechte mit Anzeigename, Gruppe und dem Modul, an dem sie hängen.
 *
 * Vorher stand diese Zuordnung als ausgeschriebene Matrixzeilen im Template und
 * als Modul-Liste im RoleController. Die Rollenübersicht und das Ausblenden
 * abgeschalteter Module lesen jetzt beide hier.
 */
final class RolePermissionCatalog
{
    /**
     * @var list<array{label: string, permissions: list<array{key: string, label: string, module: ?string}>}>
     */
    private const GROUPS = [
        ['label' => 'Mitgliederverwaltung', 'permissions' => [
            ['key' => 'can_manage_users', 'label' => 'Mitgliederverwaltung erlauben', 'module' => null],
            ['key' => 'can_manage_roles', 'label' => 'Rollen verwalten', 'module' => null],
            ['key' => 'can_edit_users', 'label' => 'Mitglieder editieren erlauben', 'module' => null],
            ['key' => 'can_manage_events', 'label' => 'Termine verwalten', 'module' => null],
            [
                'key' => 'can_manage_attendance_all',
                'label' => 'Anwesenheit/Anmeldung verwalten (alle Mitglieder)',
                'module' => null,
            ],
            ['key' => 'can_manage_project_members', 'label' => 'Projektmitglieder verwalten', 'module' => null],
            [
                'key' => 'can_assign_own_voice_group_to_project',
                'label' => 'Eigene Stimmgruppe ins Projekt zuweisen',
                'module' => null,
            ],
            ['key' => 'can_manage_own_voice_group', 'label' => 'Eigene Stimmgruppe verwalten', 'module' => null],
        ]],
        ['label' => 'Finanzen', 'permissions' => [
            ['key' => 'can_read_finances', 'label' => 'Finanzen nur lesen', 'module' => 'finance'],
            ['key' => 'can_manage_finances', 'label' => 'Finanzen lesen und schreiben', 'module' => 'finance'],
            ['key' => 'can_manage_budget', 'label' => 'Budget verwalten', 'module' => 'budget'],
        ]],
        ['label' => 'Sponsoring & Repertoire', 'permissions' => [
            ['key' => 'can_manage_sponsoring', 'label' => 'Sponsoring verwalten', 'module' => 'sponsoring'],
            [
                'key' => 'can_create_own_sponsorships',
                'label' => 'Eigene Sponsoring-Vereinbarungen erfassen',
                'module' => 'sponsoring',
            ],
            ['key' => 'can_manage_song_library', 'label' => 'Repertoire verwalten', 'module' => null],
            ['key' => 'can_manage_sheet_archive', 'label' => 'Notenarchiv verwalten', 'module' => 'sheet_archive'],
        ]],
        ['label' => 'Kommunikation & Planung', 'permissions' => [
            ['key' => 'can_manage_newsletters', 'label' => 'Newsletter verwalten', 'module' => 'newsletter'],
            ['key' => 'can_manage_mail_queue', 'label' => 'Mailversand verwalten', 'module' => null],
            ['key' => 'can_manage_tasks', 'label' => 'Projektplanung (Aufgaben)', 'module' => 'tasks'],
            ['key' => 'can_manage_files', 'label' => 'Dateiverwaltung verwalten', 'module' => 'files'],
        ]],
        ['label' => 'Stammdaten & System', 'permissions' => [
            ['key' => 'can_manage_master_data', 'label' => 'Stammdaten verwalten', 'module' => null],
            ['key' => 'can_manage_backups', 'label' => 'Backup-Verwaltung', 'module' => null],
            ['key' => 'can_manage_storage', 'label' => 'Speicherplatz-Verwaltung', 'module' => null],
        ]],
    ];

    /**
     * Gruppen mit den Rechten, deren Modul aktiv ist. Ein fehlender Modulschlüssel
     * gilt wie in Twig (`settings.modules.x`) als ausgeschaltet; leere Gruppen fallen weg.
     *
     * @param array<string,mixed> $modules
     * @return list<array{label: string, permissions: list<array{key: string, label: string}>}>
     */
    public static function groupsForModules(array $modules): array
    {
        $groups = [];
        foreach (self::GROUPS as $group) {
            $permissions = [];
            foreach ($group['permissions'] as $permission) {
                if ($permission['module'] !== null && !(bool) ($modules[$permission['module']] ?? false)) {
                    continue;
                }
                $permissions[] = ['key' => $permission['key'], 'label' => $permission['label']];
            }
            if ($permissions !== []) {
                $groups[] = ['label' => $group['label'], 'permissions' => $permissions];
            }
        }

        return $groups;
    }

    /**
     * @return array<string,string> Recht => Modul, nur für modulgebundene Rechte
     */
    public static function moduleGates(): array
    {
        $gates = [];
        foreach (self::GROUPS as $group) {
            foreach ($group['permissions'] as $permission) {
                if ($permission['module'] !== null) {
                    $gates[$permission['key']] = $permission['module'];
                }
            }
        }

        return $gates;
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        $keys = [];
        foreach (self::GROUPS as $group) {
            foreach ($group['permissions'] as $permission) {
                $keys[] = $permission['key'];
            }
        }

        return $keys;
    }
}
