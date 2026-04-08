<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\ProjectController;
use PHPUnit\Framework\TestCase;
use Slim\Views\Twig;

class ProjectFeatureTest extends TestCase
{
    use TestHttpHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
    }

    public function testProjectStructureExists(): void
    {
        $this->assertTrue(class_exists(ProjectController::class));
        $this->assertTrue(method_exists(ProjectController::class, 'index'));
        $this->assertTrue(method_exists(ProjectController::class, 'create'));
        $this->assertTrue(method_exists(ProjectController::class, 'update'));
        $this->assertTrue(method_exists(ProjectController::class, 'showMembers'));
        $this->assertTrue(method_exists(ProjectController::class, 'addMember'));
        $this->assertTrue(method_exists(ProjectController::class, 'removeMember'));

        $routesContent = file_get_contents(dirname(__DIR__) . '/../src/Routes.php');
        $this->assertIsString($routesContent);
        $this->assertStringContainsString("'/projects'", $routesContent);
        $this->assertStringContainsString("'/projects/{id:[0-9]+}/update'", $routesContent);
        $this->assertStringContainsString("'/{id:[0-9]+}/members'", $routesContent);

        $this->assertTrue(file_exists(dirname(__DIR__) . '/../templates/projects/index.twig'));
        $this->assertTrue(file_exists(dirname(__DIR__) . '/../templates/projects/members.twig'));
    }

    public function testUpdateRejectsEmptyProjectNameBeforeDatabaseAccess(): void
    {
        $twig = $this->createMock(Twig::class);
        $projectQuery = $this->createMock(\App\Queries\ProjectQuery::class);
        $projectPersistence = $this->createMock(\App\Persistence\ProjectPersistence::class);
        $controller = new ProjectController($twig, $projectQuery, $projectPersistence);

        $request = $this->makeRequest('POST', '/projects/1/update', ['name' => '   ']);
        $response = $this->makeResponse();

        $result = $controller->update($request, $response, ['id' => '1']);

        $this->assertRedirect($result, '/projects');
        $this->assertSame('Geben Sie einen Namen für das Projekt ein.', $_SESSION['error']);
    }

    public function testProjectsTemplateProvidesEditAction(): void
    {
        $templateContent = file_get_contents(dirname(__DIR__) . '/../templates/projects/index.twig');

        $this->assertIsString($templateContent);
        $this->assertStringContainsString('dropdown-toggle', $templateContent);
        $this->assertStringContainsString('dropdown-menu dropdown-menu-end', $templateContent);
        $this->assertStringContainsString('Bearbeiten', $templateContent);
        $this->assertStringContainsString('/projects/{{ project.id }}/update', $templateContent);
    }
}
