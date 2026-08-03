<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Logging\RequestContext;
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

    /**
     * Pins the one-off migration: neue Spalte mit Default 0, Backfill auf 1 ab
     * hierarchy_level 40.
     *
     * Bewusst gegen die Migrationsdatei und das Schema geprüft, nicht gegen den
     * aktuellen Inhalt von `roles`: Rollenrechte sind zur Laufzeit frei
     * konfigurierbar (Rollen-Verwaltung), d. h. jede legitime Rechteänderung an
     * einer Rolle mit Level >= 40 würde eine Datenzeilen-Assertion brechen,
     * obwohl an der Migration nichts kaputt ist.
     */
    public function testMigrationBackfillsExistingRolesByHierarchyLevel(): void
    {
        $migrationPath = dirname(__DIR__, 2)
            . '/db/migrations/20260720120000_add_can_manage_own_voice_group_to_roles.php';

        $this->assertFileExists($migrationPath);

        $migration = file_get_contents($migrationPath);
        $this->assertIsString($migration);
        $this->assertStringContainsString(
            'ADD COLUMN can_manage_own_voice_group TINYINT(1) NOT NULL DEFAULT 0',
            $migration
        );
        $this->assertStringContainsString(
            'UPDATE roles SET can_manage_own_voice_group = 1 WHERE hierarchy_level >= 40;',
            $migration
        );
    }

    public function testRolesTableHasMigratedColumn(): void
    {
        $schema = Bootstrap::getCapsule()?->schema();

        $this->assertNotNull($schema);
        $this->assertTrue(
            $schema->hasColumn('roles', 'can_manage_own_voice_group'),
            'Die Migration muss die Spalte can_manage_own_voice_group auf roles angelegt haben.'
        );
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

        (new SessionAuthService(new \App\Services\NameFormatterService(), new RequestContext()))
            ->setAuthenticatedUser($user);

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

        (new SessionAuthService(new \App\Services\NameFormatterService(), new RequestContext()))
            ->setAuthenticatedUser($user);

        $this->assertFalse($_SESSION['can_manage_own_voice_group']);

        $user->roles()->detach();
        $user->delete();
        $role->delete();
    }
}
