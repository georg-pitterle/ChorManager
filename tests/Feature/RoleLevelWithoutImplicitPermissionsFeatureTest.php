<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Models\VoiceGroup;
use App\Services\AttendanceScopeService;
use App\Services\NameFormatterService;
use App\Services\SessionAuthService;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Das Hierarchie-Level hat bisher ab 80 Rechte implizit mitgeliefert (Mitglieder-,
 * Projektmitglieder- und Aufgabenverwaltung) und zusaetzlich den Stimmgruppen-Scope
 * bei Anwesenheit/Anmeldung ausgehebelt. Das Level entscheidet ab jetzt nur noch
 * darueber, wessen Zuordnungen man aendern darf.
 */
final class RoleLevelWithoutImplicitPermissionsFeatureTest extends TestCase
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

    public function testHighLevelRoleWithoutFlagsGrantsNoPermissions(): void
    {
        $role = Role::create([
            'name' => 'Ehrenamt ohne Rechte ' . bin2hex(random_bytes(4)),
            'hierarchy_level' => 100,
        ]);

        $user = User::create([
            'first_name' => 'Ehren',
            'last_name' => 'Amt',
            'email' => 'ehren.amt.' . bin2hex(random_bytes(4)) . '@example.test',
            'password' => password_hash('test123', PASSWORD_DEFAULT),
            'is_active' => 1,
        ]);
        $user->roles()->attach($role->id);
        $user->load('roles', 'voiceGroups');

        (new SessionAuthService(new NameFormatterService()))->setAuthenticatedUser($user);

        $this->assertSame(100, $_SESSION['role_level']);
        $this->assertFalse($_SESSION['can_manage_users']);
        $this->assertFalse($_SESSION['can_manage_roles']);
        $this->assertFalse($_SESSION['can_edit_users']);
        $this->assertFalse($_SESSION['can_manage_project_members']);
        $this->assertFalse($_SESSION['can_manage_tasks']);
        $this->assertFalse($_SESSION['can_manage_attendance_all']);

        $user->roles()->detach();
        $user->delete();
        $role->delete();
    }

    public function testHighLevelDoesNotWidenAttendanceScope(): void
    {
        $groupIds = VoiceGroup::query()->orderBy('id')->limit(1)->pluck('id')
            ->map(static fn ($id): int => (int) $id)->all();
        $this->assertNotEmpty($groupIds, 'Seed-Daten mit mindestens einer Stimmgruppe erwartet.');

        $_SESSION['can_manage_attendance_all'] = false;
        $_SESSION['can_manage_own_voice_group'] = true;
        $_SESSION['role_level'] = 100;
        $_SESSION['voice_group_ids'] = $groupIds;

        $manageable = (new AttendanceScopeService())->getManageableUserIds();

        $expected = User::whereHas('voiceGroups', static function ($query) use ($groupIds): void {
            $query->whereIn('voice_group_id', $groupIds);
        })->where('is_active', 1)->pluck('id')->map(static fn ($id): int => (int) $id)->all();

        sort($manageable);
        sort($expected);
        $this->assertSame($expected, $manageable);
        $this->assertLessThan(User::where('is_active', 1)->count(), count($manageable));
    }

    public function testAttendanceAndEvaluationSourcesCarryNoLevelShortcut(): void
    {
        $files = [
            '/src/Services/SessionAuthService.php',
            '/src/Services/AttendanceScopeService.php',
            '/src/Controllers/AttendanceController.php',
            '/src/Controllers/EvaluationController.php',
        ];

        foreach ($files as $file) {
            $content = file_get_contents(dirname(__DIR__, 2) . $file);
            $this->assertIsString($content);
            foreach (['< 80', '>= 80', '>=80', '<80'] as $levelShortcut) {
                $this->assertStringNotContainsString(
                    $levelShortcut,
                    $content,
                    $file . ' darf keine Rechte mehr aus dem Hierarchie-Level ableiten.'
                );
            }
        }
    }
}
