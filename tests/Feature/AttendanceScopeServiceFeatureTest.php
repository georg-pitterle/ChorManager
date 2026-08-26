<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Models\VoiceGroup;
use App\Services\AttendanceScopeService;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

class AttendanceScopeServiceFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        Bootstrap::setupTestDatabase();
        Bootstrap::getCapsule()?->connection()->beginTransaction();
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $connection = Bootstrap::getCapsule()?->connection();
        if ($connection !== null && $connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        $_SESSION = [];
    }

    private function createUser(bool $active = true): User
    {
        $suffix = bin2hex(random_bytes(6));

        return User::create([
            'email' => "attendance_scope_{$suffix}@example.test",
            'password' => password_hash('secret', PASSWORD_BCRYPT),
            'first_name' => 'Test',
            'last_name' => 'Person',
            'is_active' => $active ? 1 : 0,
        ]);
    }

    private function createVoiceGroup(): VoiceGroup
    {
        return VoiceGroup::create(['name' => 'Testgruppe ' . bin2hex(random_bytes(4))]);
    }

    private function attachToVoiceGroup(User $user, VoiceGroup $voiceGroup): void
    {
        $user->voiceGroups()->attach($voiceGroup->id);
    }

    public function testAttendanceAllManagesAllActiveUsers(): void
    {
        // Zwei selbst angelegte aktive Personen, damit die Zusicherung nicht
        // trivial 0 gegen 0 prueft, wenn keine Fremddaten vorhanden sind.
        $this->createUser();
        $this->createUser();

        $_SESSION['can_manage_attendance_all'] = true;

        $service = new AttendanceScopeService();
        $ids = $service->getManageableUserIds();

        $this->assertSame(User::where('is_active', 1)->count(), count($ids));
        $this->assertTrue($service->canManageOthers());
    }

    public function testVoiceGroupRepManagesOnlyOwnGroups(): void
    {
        $ownGroup = $this->createVoiceGroup();
        $foreignGroup = $this->createVoiceGroup();

        $rep = $this->createUser();
        $this->attachToVoiceGroup($rep, $ownGroup);
        $peer = $this->createUser();
        $this->attachToVoiceGroup($peer, $ownGroup);
        $outsider = $this->createUser();
        $this->attachToVoiceGroup($outsider, $foreignGroup);

        $groupIds = [(int) $ownGroup->id];

        $_SESSION['can_manage_attendance_all'] = false;
        $_SESSION['role_level'] = 50;
        $_SESSION['can_manage_own_voice_group'] = true;
        $_SESSION['voice_group_ids'] = $groupIds;

        $service = new AttendanceScopeService();
        $ids = $service->getManageableUserIds();

        $expected = User::whereHas('voiceGroups', function ($q) use ($groupIds) {
            $q->whereIn('voice_group_id', $groupIds);
        })->where('is_active', 1)->pluck('id')->map(fn ($id) => (int) $id)->all();

        sort($ids);
        sort($expected);
        $this->assertSame($expected, $ids);
        $this->assertContains((int) $rep->id, $ids);
        $this->assertContains((int) $peer->id, $ids);
        $this->assertNotContains((int) $outsider->id, $ids, 'Eine fremde Stimmgruppe darf nicht verwaltbar sein.');
        $this->assertTrue($service->canManageOthers());
    }

    public function testVoiceGroupRepBelowManageOthersThresholdStillScopedByVoiceGroup(): void
    {
        $ownGroup = $this->createVoiceGroup();
        $foreignGroup = $this->createVoiceGroup();

        $rep = $this->createUser();
        $this->attachToVoiceGroup($rep, $ownGroup);
        $peer = $this->createUser();
        $this->attachToVoiceGroup($peer, $ownGroup);
        $outsider = $this->createUser();
        $this->attachToVoiceGroup($outsider, $foreignGroup);

        $groupIds = [(int) $ownGroup->id];

        $_SESSION['can_manage_users'] = false;
        $_SESSION['role_level'] = 10;
        $_SESSION['voice_group_ids'] = $groupIds;

        $service = new AttendanceScopeService();
        $ids = $service->getManageableUserIds();

        $expected = User::whereHas('voiceGroups', function ($q) use ($groupIds) {
            $q->whereIn('voice_group_id', $groupIds);
        })->where('is_active', 1)->pluck('id')->map(fn ($id) => (int) $id)->all();

        sort($ids);
        sort($expected);
        $this->assertNotSame([], $ids);
        $this->assertSame($expected, $ids);
        $this->assertNotContains((int) $outsider->id, $ids);
        $this->assertFalse($service->canManageOthers());
    }

    public function testPlainMemberManagesNobody(): void
    {
        $_SESSION['can_manage_users'] = false;
        $_SESSION['role_level'] = 0;
        $_SESSION['voice_group_ids'] = [];

        $service = new AttendanceScopeService();

        $this->assertSame([], $service->getManageableUserIds());
        $this->assertFalse($service->canManageOthers());
    }

    public function testAttendanceControllerUsesService(): void
    {
        $controller = file_get_contents(dirname(__DIR__) . '/../src/Controllers/AttendanceController.php');
        $this->assertIsString($controller);
        $this->assertStringContainsString('AttendanceScopeService', $controller);
        $this->assertStringNotContainsString('private function getManageableUserIds', $controller);
    }
}
