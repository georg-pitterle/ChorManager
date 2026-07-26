<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\RoleController;
use App\Models\Role;
use PHPUnit\Framework\TestCase;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

class RoleDisabledModulePermissionFeatureTest extends TestCase
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

    public function testContainerInjectsModuleSettingsIntoController(): void
    {
        // Without an explicit definition PHP-DI autowires the controller with the empty
        // default, which would leave every module gate switched off on real requests.
        Bootstrap::setupTestDatabase();

        $containerBuilder = new \DI\ContainerBuilder();

        $settings = require dirname(__DIR__, 2) . '/src/Settings.php';
        $settings($containerBuilder);

        $dependencies = require dirname(__DIR__, 2) . '/src/Dependencies.php';
        $dependencies($containerBuilder);

        $controller = $containerBuilder->build()->get(RoleController::class);

        $property = new \ReflectionProperty(RoleController::class, 'settings');
        $injected = $property->getValue($controller);

        $this->assertIsArray($injected);
        $this->assertArrayHasKey('modules', $injected);
        $this->assertArrayHasKey('sheet_archive', $injected['modules']);
    }

    public function testDisabledModuleKeepsExistingPermission(): void
    {
        $flags = RoleController::buildPermissionFlags(
            [],
            ['sheet_archive' => false, 'sponsoring' => false, 'newsletter' => false],
            ['can_manage_sheet_archive' => 1, 'can_manage_sponsoring' => 1, 'can_manage_newsletters' => 1]
        );

        $this->assertSame(1, $flags['can_manage_sheet_archive']);
        $this->assertSame(1, $flags['can_manage_sponsoring']);
        $this->assertSame(1, $flags['can_manage_newsletters']);
    }

    public function testDisabledModuleWithoutExistingValueStaysZero(): void
    {
        $flags = RoleController::buildPermissionFlags([], ['sheet_archive' => false]);

        $this->assertSame(0, $flags['can_manage_sheet_archive']);
    }

    public function testDisabledModuleIgnoresSubmittedValue(): void
    {
        // The checkbox is not rendered while the module is off, so any value for it can only
        // come from a forged request and must not grant the right.
        $flags = RoleController::buildPermissionFlags(
            ['can_manage_sheet_archive' => '1'],
            ['sheet_archive' => false],
            ['can_manage_sheet_archive' => 0]
        );

        $this->assertSame(0, $flags['can_manage_sheet_archive']);
    }

    public function testEnabledModuleStillClearsUncheckedPermission(): void
    {
        $flags = RoleController::buildPermissionFlags(
            [],
            ['sheet_archive' => true],
            ['can_manage_sheet_archive' => 1]
        );

        $this->assertSame(0, $flags['can_manage_sheet_archive']);
    }

    public function testDisabledFinanceModuleKeepsBothFinancePermissions(): void
    {
        $flags = RoleController::buildPermissionFlags(
            [],
            ['finance' => false, 'budget' => false, 'tasks' => false],
            [
                'can_read_finances' => 1,
                'can_manage_finances' => 1,
                'can_manage_budget' => 1,
                'can_manage_tasks' => 1,
            ]
        );

        $this->assertSame(1, $flags['can_read_finances']);
        $this->assertSame(1, $flags['can_manage_finances']);
        $this->assertSame(1, $flags['can_manage_budget']);
        $this->assertSame(1, $flags['can_manage_tasks']);
    }

    public function testUngatedPermissionsAreUnaffectedByModuleFlags(): void
    {
        $flags = RoleController::buildPermissionFlags(
            ['can_manage_song_library' => '1'],
            ['sheet_archive' => false],
            ['can_manage_mail_queue' => 1]
        );

        $this->assertSame(1, $flags['can_manage_song_library']);
        $this->assertSame(0, $flags['can_manage_mail_queue']);
    }

    public function testUpdateKeepsSheetArchivePermissionWhenModuleIsDisabled(): void
    {
        Bootstrap::setupTestDatabase();
        $_SESSION = ['role_level' => 100];

        $role = Role::create([
            'name' => 'Archiv Modul Aus ' . bin2hex(random_bytes(4)),
            'hierarchy_level' => 10,
            'can_manage_sheet_archive' => 1,
        ]);

        $controller = new RoleController(
            $this->createStub(Twig::class),
            ['modules' => ['sheet_archive' => false]]
        );

        $result = $controller->update(
            $this->makeRequest('POST', '/roles/' . $role->id, [
                'name' => $role->name,
                'hierarchy_level' => '10',
            ]),
            $this->makeResponse(),
            ['id' => (string) $role->id]
        );

        $this->assertRedirect($result, '/roles');

        $updated = Role::find($role->id);
        $this->assertSame(1, (int) $updated->can_manage_sheet_archive);

        $updated->delete();
    }

    public function testUpdateClearsSheetArchivePermissionWhenModuleIsEnabled(): void
    {
        Bootstrap::setupTestDatabase();
        $_SESSION = ['role_level' => 100];

        $role = Role::create([
            'name' => 'Archiv Modul An ' . bin2hex(random_bytes(4)),
            'hierarchy_level' => 10,
            'can_manage_sheet_archive' => 1,
        ]);

        $controller = new RoleController(
            $this->createStub(Twig::class),
            ['modules' => ['sheet_archive' => true]]
        );

        $result = $controller->update(
            $this->makeRequest('POST', '/roles/' . $role->id, [
                'name' => $role->name,
                'hierarchy_level' => '10',
            ]),
            $this->makeResponse(),
            ['id' => (string) $role->id]
        );

        $this->assertRedirect($result, '/roles');

        $updated = Role::find($role->id);
        $this->assertSame(0, (int) $updated->can_manage_sheet_archive);

        $updated->delete();
    }

    public function testCreateDoesNotGrantPermissionOfDisabledModule(): void
    {
        Bootstrap::setupTestDatabase();
        $_SESSION = ['role_level' => 100];

        $controller = new RoleController(
            $this->createStub(Twig::class),
            ['modules' => ['sheet_archive' => false]]
        );

        $roleName = 'Archiv Neu Modul Aus ' . bin2hex(random_bytes(4));
        $result = $controller->create(
            $this->makeRequest('POST', '/roles', [
                'name' => $roleName,
                'hierarchy_level' => '10',
                'can_manage_sheet_archive' => '1',
            ]),
            $this->makeResponse()
        );

        $this->assertRedirect($result, '/roles');

        $role = Role::where('name', $roleName)->first();
        $this->assertNotNull($role);
        $this->assertSame(0, (int) $role->can_manage_sheet_archive);

        $role->delete();
    }
}
