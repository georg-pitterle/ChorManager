<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\EvaluationController;
use PHPUnit\Framework\TestCase;

class EvaluationFeatureTest extends TestCase
{
    public function testEvaluationStructureExists(): void
    {
        $this->assertTrue(class_exists(EvaluationController::class));
        $this->assertTrue(method_exists(EvaluationController::class, 'index'));
        $this->assertTrue(method_exists(EvaluationController::class, 'projectMembers'));

        $controllerContent = file_get_contents(dirname(__DIR__) . '/../src/Controllers/EvaluationController.php');
        $this->assertIsString($controllerContent);
        $this->assertStringContainsString('TableQueryParams::from', $controllerContent);

        $routesContent = file_get_contents(dirname(__DIR__) . '/../src/Routes.php');
        $this->assertIsString($routesContent);
        $this->assertStringContainsString("'/evaluations'", $routesContent);
        $this->assertStringContainsString("'/evaluations/project-members'", $routesContent);

        $this->assertTrue(file_exists(dirname(__DIR__) . '/../templates/evaluations/index.twig'));
        $this->assertTrue(file_exists(dirname(__DIR__) . '/../templates/evaluations/project_members.twig'));
    }
}
