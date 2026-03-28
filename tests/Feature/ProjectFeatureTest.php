<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\ProjectController;
use PHPUnit\Framework\TestCase;

class ProjectFeatureTest extends TestCase
{
    public function testProjectStructureExists(): void
    {
        $this->assertTrue(class_exists(ProjectController::class));
        $this->assertTrue(method_exists(ProjectController::class, 'index'));
        $this->assertTrue(method_exists(ProjectController::class, 'create'));
        $this->assertTrue(method_exists(ProjectController::class, 'showMembers'));
        $this->assertTrue(method_exists(ProjectController::class, 'addMember'));
        $this->assertTrue(method_exists(ProjectController::class, 'removeMember'));

        $routesContent = file_get_contents(dirname(__DIR__) . '/../src/Routes.php');
        $this->assertIsString($routesContent);
        $this->assertStringContainsString("'/projects'", $routesContent);
        $this->assertStringContainsString("'/{id:[0-9]+}/members'", $routesContent);

        $this->assertTrue(file_exists(dirname(__DIR__) . '/../templates/projects/index.twig'));
        $this->assertTrue(file_exists(dirname(__DIR__) . '/../templates/projects/members.twig'));
    }
}
