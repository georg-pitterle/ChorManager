<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Role;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

final class RoleBackupPermissionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
    }

    public function testCanManageBackupsIsMassAssignableAndPersists(): void
    {
        $role = Role::create([
            'name' => 'Backup Test Role ' . bin2hex(random_bytes(4)),
            'hierarchy_level' => 10,
            'can_manage_backups' => 1,
        ]);

        $fresh = Role::find($role->id);

        $this->assertSame(1, (int) $fresh->can_manage_backups);

        $fresh->delete();
    }
}
