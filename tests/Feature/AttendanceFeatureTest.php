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

        $navBuilder = file_get_contents(dirname(__DIR__) . '/../src/Navigation/NavigationBuilder.php');
        $this->assertIsString($navBuilder);

        // Slice out the '/attendance' entry itself so the gate assertion below can only pass
        // on its own visibility closure, not merely on 'can_manage_attendance' appearing
        // somewhere else in the file entirely unrelated to this entry's own gate.
        $attendanceEntry = $this->extractNavEntry($navBuilder, '/attendance');
        $this->assertStringContainsString('can_manage_attendance', $attendanceEntry);

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

    /**
     * Slices the given NavigationBuilder source down to the single nav entry whose 'url' =>
     * matches exactly, from the nearest preceding 'label' => up to (but excluding) the next
     * entry's 'label' =>. Anchoring on 'url' rather than 'label' is deliberate: several nav
     * labels (Termine, Anmeldungen, Newsletter) are reused across two distinct entries, but
     * every entry's url is unique, so this cannot mis-slice into the wrong entry.
     */
    private function extractNavEntry(string $content, string $url): string
    {
        $urlNeedle = "'url' => '{$url}'";
        $urlPos = strpos($content, $urlNeedle);
        $this->assertNotFalse($urlPos, "Nav entry with url '{$url}' not found in NavigationBuilder.");

        $labelPos = strrpos(substr($content, 0, $urlPos), "'label' =>");
        $this->assertNotFalse($labelPos, "No preceding label found for nav entry '{$url}'.");

        $nextLabelPos = strpos($content, "'label' =>", $urlPos);
        $this->assertNotFalse($nextLabelPos, "No following nav entry found after '{$url}'.");

        return substr($content, $labelPos, $nextLabelPos - $labelPos);
    }
}
