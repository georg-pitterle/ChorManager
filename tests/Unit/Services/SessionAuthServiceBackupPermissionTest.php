<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Logging\RequestContext;
use App\Models\Role;
use App\Models\User;
use App\Services\SessionAuthService;
use App\Util\PasswordHasher;
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
            'password' => PasswordHasher::hash('test123'),
            'is_active' => 1,
        ]);
        $user->roles()->attach($role->id);
        $user->load('roles', 'voiceGroups');

        (new SessionAuthService(new \App\Services\NameFormatterService(), new RequestContext()))
            ->setAuthenticatedUser($user);

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
            'password' => PasswordHasher::hash('test123'),
            'is_active' => 1,
        ]);
        $user->roles()->attach($role->id);
        $user->load('roles', 'voiceGroups');

        $service = new SessionAuthService(new \App\Services\NameFormatterService(), new RequestContext());
        $service->setAuthenticatedUser($user);

        // Statt eine Sekunde verstreichen zu lassen, damit sich ein neu gesetzter
        // Zeitstempel vom ersten unterscheiden würde, bekommt die Sitzung einen
        // erkennbar alten Wert untergeschoben. Setzt der zweite Aufruf ihn neu, fällt
        // das damit sofort auf - und der Test wartet nicht.
        $_SESSION['auth_epoch'] = 1;

        $service->setAuthenticatedUser($user);

        $this->assertSame(1, $_SESSION['auth_epoch']);

        $user->delete();
        $role->delete();
    }
}
