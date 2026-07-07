<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\ProjectController;
use App\Policies\ProjectMemberPolicy;
use Illuminate\Database\Eloquent\Collection;
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
        $this->assertTrue(method_exists(ProjectController::class, 'listForMembers'));

        $routesContent = file_get_contents(dirname(__DIR__) . '/../src/Routes.php');
        $this->assertIsString($routesContent);
        $this->assertStringContainsString("'/projects'", $routesContent);
        $this->assertStringContainsString("'/projects/{id:[0-9]+}/update'", $routesContent);
        $this->assertStringContainsString("'/members'", $routesContent);
        $this->assertStringContainsString("'/{id:[0-9]+}/members'", $routesContent);

        $this->assertTrue(file_exists(dirname(__DIR__) . '/../templates/projects/index.twig'));
        $this->assertTrue(file_exists(dirname(__DIR__) . '/../templates/projects/members.twig'));
        $this->assertTrue(file_exists(dirname(__DIR__) . '/../templates/projects/member_projects.twig'));
    }

    public function testListForMembersRendersMemberProjectsTemplate(): void
    {
        $projectOne = (object) ['id' => 1, 'name' => 'Alpha'];
        $projectTwo = (object) ['id' => 2, 'name' => 'Beta'];

        $twig = $this->createMock(Twig::class);
        $twig->expects($this->once())
            ->method('render')
            ->with(
                $this->isInstanceOf(\Psr\Http\Message\ResponseInterface::class),
                'projects/member_projects.twig',
                $this->callback(function (array $data): bool {
                    if (!isset($data['projects']) || !$data['projects'] instanceof Collection) {
                        return false;
                    }

                    $ids = $data['projects']->pluck('id')->all();
                    return $ids === [2];
                })
            )
            ->willReturnCallback(
                fn(\Psr\Http\Message\ResponseInterface $response) => $response->withStatus(200)
            );

        $projectQuery = $this->createMock(\App\Queries\ProjectQuery::class);
        $projectQuery->expects($this->once())
            ->method('getAllProjects')
            ->willReturn(new Collection([$projectOne, $projectTwo]));

        $projectPersistence = $this->createStub(\App\Persistence\ProjectPersistence::class);

        $policy = $this->createMock(ProjectMemberPolicy::class);
        $policy->expects($this->once())
            ->method('getAccessibleProjectIds')
            ->willReturn([2]);

        $controller = new ProjectController($twig, $projectQuery, $projectPersistence, $policy);
        $request = $this->makeRequest('GET', '/projects/members');
        $response = $this->makeResponse();

        $result = $controller->listForMembers($request, $response);

        $this->assertSame(200, $result->getStatusCode());
    }

    public function testUpdateRejectsEmptyProjectNameBeforeDatabaseAccess(): void
    {
        $twig = $this->createStub(Twig::class);
        $projectQuery = $this->createStub(\App\Queries\ProjectQuery::class);
        $projectPersistence = $this->createStub(\App\Persistence\ProjectPersistence::class);
        $policy = $this->createStub(ProjectMemberPolicy::class);
        $controller = new ProjectController($twig, $projectQuery, $projectPersistence, $policy);

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

    public function testProjectsTemplateFormatsEditDateFieldsForHtmlDateInput(): void
    {
        $templateContent = file_get_contents(dirname(__DIR__) . '/../templates/projects/index.twig');

        $this->assertIsString($templateContent);
        $this->assertStringContainsString('value="{{ project.start_date and project.start_date != "-" ? project.start_date|date("Y-m-d") : "" }}"', $templateContent);
        $this->assertStringContainsString('value="{{ project.end_date and project.end_date != "-" ? project.end_date|date("Y-m-d") : "" }}"', $templateContent);
    }

    public function testProjectsTemplateGuardsDateParsingForPlaceholderValues(): void
    {
        $templateContent = file_get_contents(dirname(__DIR__) . '/../templates/projects/index.twig');

        $this->assertIsString($templateContent);
        $this->assertStringContainsString('{% set has_start_date = project.start_date and project.start_date != "-" %}', $templateContent);
        $this->assertStringContainsString('{% set has_end_date = project.end_date and project.end_date != "-" %}', $templateContent);
        $this->assertStringContainsString('{{ has_start_date ? project.start_date|date("d.m.Y") : "-" }}', $templateContent);
        $this->assertStringContainsString('{{ has_end_date ? project.end_date|date("d.m.Y") : "-" }}', $templateContent);
    }

    public function testAreasNavigationContainsMemberProjectsCondition(): void
    {
        $templateContent = file_get_contents(dirname(__DIR__) . '/../templates/partials/navigation/areas.twig');

        $this->assertIsString($templateContent);
        $this->assertStringContainsString('session.can_manage_project_members', $templateContent);
        $this->assertStringContainsString('not session.can_manage_master_data', $templateContent);
        $this->assertStringContainsString('/projects/members', $templateContent);
        $this->assertStringContainsString('project_members', $templateContent);
    }
}
