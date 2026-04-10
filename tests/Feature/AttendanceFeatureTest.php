<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\AttendanceController;
use PHPUnit\Framework\TestCase;

class AttendanceFeatureTest extends TestCase
{
    public function testAttendanceStructureExists(): void
    {
        $this->assertTrue(class_exists(AttendanceController::class));
        $this->assertTrue(method_exists(AttendanceController::class, 'show'));
        $this->assertTrue(method_exists(AttendanceController::class, 'save'));

        $routesContent = file_get_contents(dirname(__DIR__) . '/../src/Routes.php');
        $this->assertIsString($routesContent);
        $this->assertStringContainsString("'/attendance'", $routesContent);
        $this->assertStringContainsString("'/attendance/{event_id:[0-9]+}'", $routesContent);
        $this->assertStringContainsString('function (RouteCollectorProxy $attendanceGroup)', $routesContent);
        $this->assertStringContainsString('->add(new RoleMiddleware(', $routesContent);

        $this->assertTrue(file_exists(dirname(__DIR__) . '/../templates/attendance/show.twig'));

        $attendanceTemplate = file_get_contents(dirname(__DIR__) . '/../templates/attendance/show.twig');
        $this->assertIsString($attendanceTemplate);
        $this->assertStringContainsString('attendance-status-group', $attendanceTemplate);

        $eventsNavTemplate = file_get_contents(dirname(__DIR__) . '/../templates/partials/navigation/events.twig');
        $this->assertIsString($eventsNavTemplate);
        $this->assertStringContainsString('can_manage_attendance', $eventsNavTemplate);
        $this->assertStringContainsString('href="/attendance"', $eventsNavTemplate);

        $dashboardTemplate = file_get_contents(dirname(__DIR__) . '/../templates/dashboard/index.twig');
        $this->assertIsString($dashboardTemplate);
        $this->assertStringContainsString('can_manage_attendance', $dashboardTemplate);
        $this->assertStringContainsString('href="/attendance"', $dashboardTemplate);

        $eventsTemplate = file_get_contents(dirname(__DIR__) . '/../templates/events/index.twig');
        $this->assertIsString($eventsTemplate);
        $this->assertStringContainsString('can_manage_attendance', $eventsTemplate);
        $this->assertStringContainsString('href="/attendance/{{ event.id }}"', $eventsTemplate);
    }

    public function testAttendancePermissionMigrationExists(): void
    {
        $migrationPath = dirname(__DIR__) . '/../db/migrations/20260411103000_add_attendance_management_permission.php';
        $migrationContent = file_get_contents($migrationPath);

        $this->assertIsString($migrationContent);
        $this->assertStringContainsString('ADD COLUMN can_manage_attendance', $migrationContent);
        $this->assertStringContainsString("UPDATE roles SET can_manage_attendance = 1", $migrationContent);
    }
}
