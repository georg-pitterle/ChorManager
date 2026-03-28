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

        $this->assertTrue(file_exists(dirname(__DIR__) . '/../templates/attendance/show.twig'));
    }
}
