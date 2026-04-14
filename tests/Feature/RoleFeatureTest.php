<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\RoleController;
use PHPUnit\Framework\TestCase;
use Slim\Views\Twig;

class RoleFeatureTest extends TestCase
{
    use TestHttpHelpers;

    protected function setUp(): void
    {
        $_SESSION = [];
    }

    public function testRoleStructureExists(): void
    {
        $this->assertTrue(class_exists(RoleController::class));
        $this->assertTrue(method_exists(RoleController::class, 'index'));
        $this->assertTrue(method_exists(RoleController::class, 'create'));
        $this->assertTrue(method_exists(RoleController::class, 'update'));

        $routesContent = file_get_contents(dirname(__DIR__) . '/../src/Routes.php');
        $this->assertIsString($routesContent);
        $this->assertStringContainsString("'/roles'", $routesContent);

        $this->assertTrue(file_exists(dirname(__DIR__) . '/../templates/roles/index.twig'));
    }

    public function testBuildPermissionFlagsMapsCheckboxPresence(): void
    {
        $flags = RoleController::buildPermissionFlags([
            'can_manage_users' => '1',
            'can_manage_finances' => '1',
        ]);

        $this->assertSame(1, $flags['can_manage_users']);
        $this->assertSame(0, $flags['can_edit_users']);
        $this->assertSame(0, $flags['can_manage_attendance']);
        $this->assertSame(0, $flags['can_manage_project_members']);
        $this->assertSame(1, $flags['can_manage_finances']);
        $this->assertSame(0, $flags['can_manage_master_data']);
        $this->assertSame(0, $flags['can_manage_sponsoring']);
        $this->assertSame(0, $flags['can_manage_song_library']);
        $this->assertSame(0, $flags['can_manage_newsletters']);
        $this->assertSame(0, $flags['can_manage_tasks']);
    }

    public function testCreateRejectsEmptyRoleNameBeforeDatabaseAccess(): void
    {
        $twig = $this->createMock(Twig::class);
        $controller = new RoleController($twig);

        $request = $this->makeRequest('POST', '/roles', ['name' => '   ']);
        $response = $this->makeResponse();

        $result = $controller->create($request, $response);

        $this->assertRedirect($result, '/roles');
        $this->assertSame('Der Rollenname darf nicht leer sein.', $_SESSION['error']);
    }

    public function testRolesTemplateProvidesDataLabelsForCardView(): void
    {
        $templateContent = file_get_contents(dirname(__DIR__) . '/../templates/roles/index.twig');

        $this->assertIsString($templateContent);
        $this->assertStringContainsString('data-label="{{ role.name }}"', $templateContent);
        $this->assertStringContainsString('{{ role.active_users_count }}', $templateContent);
        $this->assertStringContainsString('text-body-secondary', $templateContent);
        $this->assertStringContainsString('class="text-center align-middle"', $templateContent);
        $this->assertStringContainsString('badge rounded-pill bg-success', $templateContent);
        $this->assertStringContainsString('badge rounded-pill bg-danger', $templateContent);
        $this->assertStringContainsString('bi bi-check-lg text-white', $templateContent);
        $this->assertStringContainsString('bi bi-x-lg text-white', $templateContent);
        $this->assertStringNotContainsString('text-light">Level {{ role.hierarchy_level }}', $templateContent);
        $this->assertStringNotContainsString('</i> Ja</span>', $templateContent);
        $this->assertStringNotContainsString('</i> Nein</span>', $templateContent);
    }

    public function testIndexLoadsActiveUserCountAliasForRoles(): void
    {
        $controllerContent = file_get_contents(dirname(__DIR__) . '/../src/Controllers/RoleController.php');

        $this->assertIsString($controllerContent);
        $this->assertStringContainsString("'users as active_users_count'", $controllerContent);
    }

    public function testDevSeedServiceIncludesTaskRolePermissionDefaults(): void
    {
        $seedContent = file_get_contents(dirname(__DIR__) . '/../src/Services/DevSeedService.php');

        $this->assertIsString($seedContent);
        $this->assertStringContainsString("'can_manage_tasks' => 1", $seedContent);
        $this->assertStringContainsString("'can_manage_tasks' => 0", $seedContent);
    }
}
