<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\RoleController;
use App\Models\Role;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

final class RoleChangeLoggingTest extends TestCase
{
    use TestHttpHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        $_SESSION = ['role_level' => 100];
    }

    public function testCreateRoleIsLogged(): void
    {
        [$logger, $handler] = $this->logger();
        $controller = $this->controller($logger);

        $roleName = 'Logging Create Role ' . bin2hex(random_bytes(4));

        try {
            $controller->create(
                $this->makeRequest('POST', '/roles', ['name' => $roleName, 'hierarchy_level' => '10']),
                $this->makeResponse()
            );

            $record = $this->recordFor($handler, 'role.created');
            $this->assertNotNull($record);
            $this->assertSame($roleName, $record->context['role_name']);
            $this->assertIsInt($record->context['role_id']);
        } finally {
            Role::where('name', $roleName)->delete();
        }
    }

    public function testUpdateRoleLogsPermissionDiff(): void
    {
        [$logger, $handler] = $this->logger();
        $controller = $this->controller($logger);

        $role = Role::create([
            'name' => 'Logging Update Role ' . bin2hex(random_bytes(4)),
            'hierarchy_level' => 10,
            'can_manage_roles' => 0,
            'can_manage_events' => 1,
        ]);

        try {
            $controller->update(
                $this->makeRequest('POST', '/roles/' . $role->id, [
                    'name' => $role->name,
                    'hierarchy_level' => '10',
                    'can_manage_roles' => '1',
                ]),
                $this->makeResponse(),
                ['id' => (string) $role->id]
            );

            $record = $this->recordFor($handler, 'role.updated');
            $this->assertNotNull($record);
            $this->assertSame((int) $role->id, $record->context['role_id']);
            $this->assertSame($role->name, $record->context['role_name']);
            $this->assertSame(['can_manage_roles'], $record->context['granted']);
            $this->assertSame(['can_manage_events'], $record->context['revoked']);
        } finally {
            Role::where('id', $role->id)->delete();
        }
    }

    public function testDeleteRoleIsLogged(): void
    {
        [$logger, $handler] = $this->logger();
        $controller = $this->controller($logger);

        $role = Role::create([
            'name' => 'Logging Delete Role ' . bin2hex(random_bytes(4)),
            'hierarchy_level' => 10,
        ]);
        $roleId = (int) $role->id;
        $roleName = $role->name;

        $controller->delete(
            $this->makeRequest('POST', '/roles/' . $roleId . '/delete'),
            $this->makeResponse(),
            ['id' => (string) $roleId]
        );

        $record = $this->recordFor($handler, 'role.deleted');
        $this->assertNotNull($record);
        $this->assertSame($roleId, $record->context['role_id']);
        $this->assertSame($roleName, $record->context['role_name']);
    }

    private function controller(Logger $logger): RoleController
    {
        return new RoleController($this->createStub(Twig::class), [], $logger);
    }

    public function testPermissionDiffListsGrantedAndRevoked(): void
    {
        $before = [
            'can_manage_users' => true,
            'can_manage_roles' => false,
            'can_manage_events' => true,
        ];
        $after = [
            'can_manage_users' => true,
            'can_manage_roles' => true,
            'can_manage_events' => false,
        ];

        $diff = RoleController::permissionDiff($before, $after);

        $this->assertSame(['can_manage_roles'], $diff['granted']);
        $this->assertSame(['can_manage_events'], $diff['revoked']);
    }

    public function testUnchangedPermissionsProduceEmptyDiff(): void
    {
        $flags = ['can_manage_users' => true, 'can_manage_roles' => false];

        $diff = RoleController::permissionDiff($flags, $flags);

        $this->assertSame([], $diff['granted']);
        $this->assertSame([], $diff['revoked']);
    }
}
