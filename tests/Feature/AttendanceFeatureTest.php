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
        $migrationPath = dirname(__DIR__) . '/../db/migrations/20260314130000_initial.php';
        $migrationContent = file_get_contents($migrationPath);

        $this->assertIsString($migrationContent);
        $this->assertStringContainsString('can_manage_attendance tinyint(1) NOT NULL DEFAULT 0', $migrationContent);
        $this->assertStringContainsString("(4,'Stimmvertretung', 50, 0,0,1, 0,0, 0,0,0,0,1)", $migrationContent);
        $this->assertStringContainsString("(6,'Mitglied',         0, 0,0,0, 0,0, 0,0,0,0,0)", $migrationContent);

        $controllerContent = file_get_contents(dirname(__DIR__) . '/../src/Controllers/AttendanceController.php');
        $this->assertIsString($controllerContent);
        $this->assertStringContainsString('$event = Event::find($eventId);', $controllerContent);
        $this->assertStringContainsString('if (!$this->canAccessAttendanceEvent($event)) {', $controllerContent);
        $this->assertStringContainsString('$allowedUserIds = $this->scopeService->getManageableUserIds();', $controllerContent);
        $this->assertStringContainsString('AttendanceScopeService', $controllerContent);
        $this->assertStringContainsString('private function canAccessAttendanceEvent(Event $event): bool', $controllerContent);
    }
}
