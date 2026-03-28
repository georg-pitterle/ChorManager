<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\EventTypeController;
use PHPUnit\Framework\TestCase;

class EventTypeFeatureTest extends TestCase
{
    public function testEventTypeStructureExists(): void
    {
        $this->assertTrue(class_exists(EventTypeController::class));
        $this->assertTrue(method_exists(EventTypeController::class, 'index'));
        $this->assertTrue(method_exists(EventTypeController::class, 'create'));
        $this->assertTrue(method_exists(EventTypeController::class, 'update'));
        $this->assertTrue(method_exists(EventTypeController::class, 'delete'));

        $routesContent = file_get_contents(dirname(__DIR__) . '/../src/Routes.php');
        $this->assertIsString($routesContent);
        $this->assertStringContainsString("'/event-types'", $routesContent);

        $this->assertTrue(file_exists(dirname(__DIR__) . '/../templates/settings/event_types.twig'));
    }
}
