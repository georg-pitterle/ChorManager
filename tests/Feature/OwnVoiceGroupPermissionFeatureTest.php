<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\SessionAuthService;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

class OwnVoiceGroupPermissionFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        Bootstrap::setupTestDatabase();
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testMigrationBackfillsExistingRolesByHierarchyLevel(): void
    {
        $rolesAtOrAboveThreshold = Role::where('hierarchy_level', '>=', 40)->get();
        $this->assertGreaterThan(0, $rolesAtOrAboveThreshold->count());
        foreach ($rolesAtOrAboveThreshold as $role) {
            $this->assertSame(
                1,
                (int) $role->can_manage_own_voice_group,
                'Role "' . $role->name . '" (level ' . $role->hierarchy_level . ') should have been backfilled to 1'
            );
        }

        $memberRole = Role::where('name', 'Mitglied')->first();
        $this->assertNotNull($memberRole);
        $this->assertLessThan(40, $memberRole->hierarchy_level);
        $this->assertSame(0, (int) $memberRole->can_manage_own_voice_group);
    }

    public function testRoleColumnExistsAndIsFillable(): void
    {
        $role = Role::create([
            'name' => 'VG Rep Test ' . bin2hex(random_bytes(4)),
            'hierarchy_level' => 45,
            'can_manage_own_voice_group' => 1,
        ]);

        $fresh = Role::find($role->id);
        $this->assertSame(1, (int) $fresh->can_manage_own_voice_group);

        $role->delete();
    }

    public function testSessionReceivesFlagFromRole(): void
    {
        $role = Role::create([
            'name' => 'VG Rep Session ' . bin2hex(random_bytes(4)),
            'hierarchy_level' => 10,
            'can_manage_own_voice_group' => 1,
        ]);
        $user = User::create([
            'first_name' => 'Vera',
            'last_name' => 'Tretung',
            'email' => 'vera.' . bin2hex(random_bytes(4)) . '@example.test',
            'password' => password_hash('x', PASSWORD_DEFAULT),
            'is_active' => 1,
        ]);
        $user->roles()->attach($role->id);

        (new SessionAuthService())->setAuthenticatedUser($user);

        $this->assertTrue($_SESSION['can_manage_own_voice_group']);

        $user->roles()->detach();
        $user->delete();
        $role->delete();
    }

    public function testSessionFlagFalseForPlainMember(): void
    {
        $role = Role::create([
            'name' => 'Plain Member ' . bin2hex(random_bytes(4)),
            'hierarchy_level' => 0,
            'can_manage_own_voice_group' => 0,
        ]);
        $user = User::create([
            'first_name' => 'Mit',
            'last_name' => 'Glied',
            'email' => 'mit.' . bin2hex(random_bytes(4)) . '@example.test',
            'password' => password_hash('x', PASSWORD_DEFAULT),
            'is_active' => 1,
        ]);
        $user->roles()->attach($role->id);

        (new SessionAuthService())->setAuthenticatedUser($user);

        $this->assertFalse($_SESSION['can_manage_own_voice_group']);

        $user->roles()->detach();
        $user->delete();
        $role->delete();
    }
}
