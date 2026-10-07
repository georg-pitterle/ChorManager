<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Role;
use App\Services\RoleOverview;
use PHPUnit\Framework\TestCase;

final class RoleOverviewTest extends TestCase
{
    private const GROUPS = [
        ['label' => 'A', 'permissions' => [
            ['key' => 'can_manage_users', 'label' => 'Mitgliederverwaltung erlauben'],
            ['key' => 'can_manage_roles', 'label' => 'Rollen verwalten'],
        ]],
        ['label' => 'B', 'permissions' => [
            ['key' => 'can_manage_backups', 'label' => 'Backup-Verwaltung'],
        ]],
    ];

    /** @param array<string,int> $flags */
    private function role(int $id, array $flags): Role
    {
        $role = new Role();
        $role->forceFill(['id' => $id, 'name' => 'Rolle ' . $id] + $flags);

        return $role;
    }

    public function testCountsGrantedPermissionsAndHoldersInRoleOrder(): void
    {
        $roles = [
            $this->role(7, ['can_manage_users' => 1, 'can_manage_roles' => 1, 'can_manage_backups' => 0]),
            $this->role(3, ['can_manage_users' => 1, 'can_manage_roles' => 0, 'can_manage_backups' => 0]),
        ];

        $overview = RoleOverview::build($roles, self::GROUPS);

        $this->assertSame(3, $overview['total']);
        $this->assertSame([7 => 2, 3 => 1], $overview['granted_counts']);
        $this->assertSame([7 => 67, 3 => 33], $overview['share_percent']);
        $this->assertSame([7, 3], $overview['holders']['can_manage_users']);
        $this->assertSame([7], $overview['holders']['can_manage_roles']);
    }

    public function testPermissionWithoutHolderHasEmptyList(): void
    {
        $overview = RoleOverview::build([$this->role(1, ['can_manage_users' => 0])], self::GROUPS);

        $this->assertSame([], $overview['holders']['can_manage_backups']);
        $this->assertSame([1 => 0], $overview['granted_counts']);
        $this->assertSame([1 => 0], $overview['share_percent']);
    }

    public function testHiddenPermissionDoesNotCount(): void
    {
        $role = $this->role(1, ['can_manage_users' => 1, 'can_manage_files' => 1]);

        $overview = RoleOverview::build([$role], self::GROUPS);

        $this->assertSame([1 => 1], $overview['granted_counts']);
        $this->assertArrayNotHasKey('can_manage_files', $overview['holders']);
    }

    public function testNoVisiblePermissionsGivesZeroShareInsteadOfDivisionByZero(): void
    {
        $overview = RoleOverview::build([$this->role(1, [])], []);

        $this->assertSame(0, $overview['total']);
        $this->assertSame([1 => 0], $overview['share_percent']);
    }
}
