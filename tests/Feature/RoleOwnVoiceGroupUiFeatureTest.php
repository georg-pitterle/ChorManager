<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\RoleController;
use App\Models\Role;
use PHPUnit\Framework\TestCase;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

class RoleOwnVoiceGroupUiFeatureTest extends TestCase
{
    use TestHttpHelpers;

    protected function setUp(): void
    {
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testBuildPermissionFlagsIncludesOwnVoiceGroup(): void
    {
        $flags = RoleController::buildPermissionFlags(['can_manage_own_voice_group' => '1']);
        $this->assertSame(1, $flags['can_manage_own_voice_group']);

        $flagsOff = RoleController::buildPermissionFlags([]);
        $this->assertSame(0, $flagsOff['can_manage_own_voice_group']);
    }

    public function testRolesTemplateOffersOwnVoiceGroupCheckboxInBothModals(): void
    {
        $template = file_get_contents(dirname(__DIR__) . '/../templates/roles/index.twig');
        $this->assertIsString($template);
        $this->assertStringContainsString('id="can_manage_own_voice_group"', $template);
        $this->assertStringContainsString('id="edit_can_manage_own_voice_group"', $template);
        $this->assertStringContainsString('name="can_manage_own_voice_group"', $template);
        $this->assertStringContainsString('data-own-voice-group="', $template);
    }

    public function testRolesTemplateShowsOwnVoiceGroupInPermissionMatrixRow(): void
    {
        $template = file_get_contents(dirname(__DIR__) . '/../templates/roles/index.twig');
        $this->assertIsString($template);

        // The per-role ✓/✗ matrix row is distinct from the modal checkboxes asserted above:
        // an admin comparing roles must be able to see who holds this right without opening
        // the edit modal for every role.
        $rowPattern = '#<th scope="row">Eigene Stimmgruppe verwalten</th>\s*'
            . '\{% for role in roles %\}\s*'
            . '<td[^>]*>\s*'
            . '\{% if role\.can_manage_own_voice_group %\}#s';
        $this->assertMatchesRegularExpression(
            $rowPattern,
            $template,
            'permission matrix must have a dedicated row for Eigene Stimmgruppe verwalten'
        );
    }

    public function testRolesJsPopulatesOwnVoiceGroupOnEdit(): void
    {
        $js = file_get_contents(dirname(__DIR__) . '/../public/js/roles.js');
        $this->assertIsString($js);
        $this->assertStringContainsString("data-own-voice-group", $js);
        $this->assertStringContainsString('edit_can_manage_own_voice_group', $js);
    }

    public function testControllerCreatePersistsOwnVoiceGroupFlagWhenPresent(): void
    {
        Bootstrap::setupTestDatabase();
        $_SESSION = ['role_level' => 100];

        $controller = new RoleController($this->createStub(Twig::class));
        $roleName = 'VG UI Create On ' . bin2hex(random_bytes(4));

        $result = $controller->create(
            $this->makeRequest('POST', '/roles', [
                'name' => $roleName,
                'hierarchy_level' => '10',
                'can_manage_own_voice_group' => '1',
            ]),
            $this->makeResponse()
        );

        $this->assertRedirect($result, '/roles');

        $role = Role::where('name', $roleName)->first();
        $this->assertNotNull($role);
        $this->assertSame(1, (int) $role->can_manage_own_voice_group);

        $role->delete();
    }

    public function testControllerCreatePersistsOwnVoiceGroupFlagAsZeroWhenAbsent(): void
    {
        Bootstrap::setupTestDatabase();
        $_SESSION = ['role_level' => 100];

        $controller = new RoleController($this->createStub(Twig::class));
        $roleName = 'VG UI Create Off ' . bin2hex(random_bytes(4));

        $result = $controller->create(
            $this->makeRequest('POST', '/roles', [
                'name' => $roleName,
                'hierarchy_level' => '10',
            ]),
            $this->makeResponse()
        );

        $this->assertRedirect($result, '/roles');

        $role = Role::where('name', $roleName)->first();
        $this->assertNotNull($role);
        $this->assertSame(0, (int) $role->can_manage_own_voice_group);

        $role->delete();
    }

    public function testControllerUpdatePersistsOwnVoiceGroupFlag(): void
    {
        Bootstrap::setupTestDatabase();
        $_SESSION = ['role_level' => 100];

        $role = Role::create([
            'name' => 'VG UI Update ' . bin2hex(random_bytes(4)),
            'hierarchy_level' => 10,
            'can_manage_own_voice_group' => 0,
        ]);

        $controller = new RoleController($this->createStub(Twig::class));
        $result = $controller->update(
            $this->makeRequest('POST', '/roles/' . $role->id, [
                'name' => $role->name,
                'hierarchy_level' => '10',
                'can_manage_own_voice_group' => '1',
            ]),
            $this->makeResponse(),
            ['id' => (string) $role->id]
        );

        $this->assertRedirect($result, '/roles');

        $updated = Role::find($role->id);
        $this->assertSame(1, (int) $updated->can_manage_own_voice_group);

        $updated->delete();
    }
}
