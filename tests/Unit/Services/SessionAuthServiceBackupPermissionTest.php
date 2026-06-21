<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Role;
use App\Models\User;
use App\Services\SessionAuthService;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

final class SessionAuthServiceBackupPermissionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        $_SESSION = [];
    }

    public function testSetAuthenticatedUserExposesBackupPermissionFromRole(): void
    {
        $role = Role::create([
            'name' => 'Backup Manager ' . bin2hex(random_bytes(4)),
            'hierarchy_level' => 10,
            'can_manage_backups' => 1,
        ]);

        $user = User::create([
            'first_name' => 'Backup',
            'last_name' => 'Tester',
            'email' => 'backup.tester.' . bin2hex(random_bytes(4)) . '@example.test',
            'password' => password_hash('test123', PASSWORD_DEFAULT),
            'is_active' => 1,
        ]);
        $user->roles()->attach($role->id);
        $user->load('roles', 'voiceGroups');

        (new SessionAuthService())->setAuthenticatedUser($user);

        $this->assertTrue($_SESSION['can_manage_backups']);

        $user->delete();
        $role->delete();
    }

    public function testSetAuthenticatedUserSetsAuthEpochOnceAndDoesNotOverwriteIt(): void
    {
        $role = Role::create([
            'name' => 'Plain Member ' . bin2hex(random_bytes(4)),
            'hierarchy_level' => 10,
        ]);

        $user = User::create([
            'first_name' => 'Epoch',
            'last_name' => 'Tester',
            'email' => 'epoch.tester.' . bin2hex(random_bytes(4)) . '@example.test',
            'password' => password_hash('test123', PASSWORD_DEFAULT),
            'is_active' => 1,
        ]);
        $user->roles()->attach($role->id);
        $user->load('roles', 'voiceGroups');

        $service = new SessionAuthService();
        $service->setAuthenticatedUser($user);
        $firstEpoch = $_SESSION['auth_epoch'];

        sleep(1);
        $service->setAuthenticatedUser($user);

        $this->assertSame($firstEpoch, $_SESSION['auth_epoch']);

        $user->delete();
        $role->delete();
    }
}
