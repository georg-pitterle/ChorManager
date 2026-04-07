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
        $this->assertSame(0, $flags['can_manage_project_members']);
        $this->assertSame(1, $flags['can_manage_finances']);
        $this->assertSame(0, $flags['can_manage_master_data']);
        $this->assertSame(0, $flags['can_manage_sponsoring']);
        $this->assertSame(0, $flags['can_manage_song_library']);
        $this->assertSame(0, $flags['can_manage_newsletters']);
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
        $this->assertStringContainsString('<td data-label="{{ role.name }}">', $templateContent);
    }
}
