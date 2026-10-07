<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Role;
use App\Services\RolePermissionCatalog;
use PHPUnit\Framework\TestCase;

final class RolePermissionCatalogTest extends TestCase
{
    private const ALL_MODULES = [
        'finance' => true,
        'budget' => true,
        'sponsoring' => true,
        'newsletter' => true,
        'sheet_archive' => true,
        'tasks' => true,
        'files' => true,
    ];

    public function testCatalogCoversExactlyTheRolePermissions(): void
    {
        $keys = RolePermissionCatalog::keys();
        sort($keys);
        $expected = Role::PERMISSIONS;
        sort($expected);

        $this->assertSame($expected, $keys, 'Jedes Recht aus Role::PERMISSIONS braucht genau einen Katalogeintrag');
    }

    public function testModuleGatesMatchPreviousControllerMap(): void
    {
        $this->assertSame([
            'can_read_finances' => 'finance',
            'can_manage_finances' => 'finance',
            'can_manage_budget' => 'budget',
            'can_manage_sponsoring' => 'sponsoring',
            'can_create_own_sponsorships' => 'sponsoring',
            'can_manage_sheet_archive' => 'sheet_archive',
            'can_manage_newsletters' => 'newsletter',
            'can_manage_tasks' => 'tasks',
            'can_manage_files' => 'files',
        ], RolePermissionCatalog::moduleGates());
    }

    public function testAllModulesOnShowsGroupsAndLabelsInMatrixOrder(): void
    {
        $groups = RolePermissionCatalog::groupsForModules(self::ALL_MODULES);

        $this->assertSame(
            ['Mitgliederverwaltung', 'Finanzen', 'Sponsoring & Repertoire', 'Kommunikation & Planung', 'Stammdaten & System'],
            array_column($groups, 'label')
        );
        $this->assertSame(
            ['key' => 'can_manage_events', 'label' => 'Termine verwalten'],
            $groups[0]['permissions'][3]
        );
        $this->assertCount(22, array_merge(...array_column($groups, 'permissions')));
    }

    public function testMissingModuleFlagHidesPermission(): void
    {
        $groups = RolePermissionCatalog::groupsForModules(['files' => false] + self::ALL_MODULES);
        $keys = array_column(array_merge(...array_column($groups, 'permissions')), 'key');

        $this->assertNotContains('can_manage_files', $keys);
        $this->assertContains('can_manage_mail_queue', $keys);
    }

    public function testGroupWithoutVisiblePermissionDisappears(): void
    {
        $groups = RolePermissionCatalog::groupsForModules(['finance' => false, 'budget' => false] + self::ALL_MODULES);

        $this->assertNotContains('Finanzen', array_column($groups, 'label'));
    }

    public function testEmptyModuleListKeepsUngatedPermissions(): void
    {
        $keys = array_column(
            array_merge(...array_column(RolePermissionCatalog::groupsForModules([]), 'permissions')),
            'key'
        );

        $this->assertCount(13, $keys);
        $this->assertContains('can_manage_song_library', $keys);
        $this->assertNotContains('can_read_finances', $keys);
    }
}
