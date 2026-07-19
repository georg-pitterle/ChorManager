<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AttendanceScopeService;
use App\Models\User;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

class AttendanceScopeServiceFeatureTest extends TestCase
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

    public function testAdminManagesAllActiveUsers(): void
    {
        $_SESSION['can_manage_users'] = true;

        $service = new AttendanceScopeService();
        $ids = $service->getManageableUserIds();

        $this->assertSame(User::where('is_active', 1)->count(), count($ids));
        $this->assertTrue($service->canManageOthers());
    }

    public function testVoiceGroupRepManagesOnlyOwnGroups(): void
    {
        $rep = User::where('is_active', 1)
            ->whereHas('voiceGroups')
            ->firstOrFail();
        $groupIds = $rep->voiceGroups->pluck('id')->map(fn ($id) => (int) $id)->all();

        $_SESSION['can_manage_users'] = false;
        $_SESSION['role_level'] = 50;
        $_SESSION['voice_group_ids'] = $groupIds;

        $service = new AttendanceScopeService();
        $ids = $service->getManageableUserIds();

        $expected = User::whereHas('voiceGroups', function ($q) use ($groupIds) {
            $q->whereIn('voice_group_id', $groupIds);
        })->where('is_active', 1)->pluck('id')->map(fn ($id) => (int) $id)->all();

        sort($ids);
        sort($expected);
        $this->assertSame($expected, $ids);
        $this->assertTrue($service->canManageOthers());
    }

    public function testVoiceGroupRepBelowManageOthersThresholdStillScopedByVoiceGroup(): void
    {
        $rep = User::where('is_active', 1)
            ->whereHas('voiceGroups')
            ->firstOrFail();
        $groupIds = $rep->voiceGroups->pluck('id')->map(fn ($id) => (int) $id)->all();

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
