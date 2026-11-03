<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\RoleController;
use App\Middleware\RoleMiddleware;
use App\Models\Role;
use App\Services\RolePermissionCatalog;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Das Recht "Umfragen verwalten" (can_manage_polls).
 *
 * Bestehende Rollen bekommen es nicht nachgetragen; nur die Admin-Rolle der
 * Ersteinrichtung hält es von Anfang an.
 */
final class RoleManagePollsFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $connection = Capsule::connection();
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        $_SESSION = [];
        parent::tearDown();
    }

    public function testBuildPermissionFlagsIncludesManagePolls(): void
    {
        $this->assertSame(1, RoleController::buildPermissionFlags(['can_manage_polls' => '1'])['can_manage_polls']);
        $this->assertSame(0, RoleController::buildPermissionFlags([])['can_manage_polls']);
    }

    public function testAnInactiveModuleKeepsTheStoredValue(): void
    {
        $flags = RoleController::buildPermissionFlags([], ['polls' => false], ['can_manage_polls' => 1]);

        $this->assertSame(1, $flags['can_manage_polls']);
    }

    public function testRolesTemplateOffersCheckboxInBothModals(): void
    {
        $template = file_get_contents(dirname(__DIR__) . '/../templates/roles/index.twig')
            . file_get_contents(dirname(__DIR__) . '/../templates/roles/_overview.twig');
        $this->assertStringContainsString('id="can_manage_polls"', $template);
        $this->assertStringContainsString('id="edit_can_manage_polls"', $template);
        $this->assertStringContainsString('name="can_manage_polls"', $template);
        $this->assertStringContainsString('data-polls="', $template);
    }

    public function testCatalogListsTheRightOnlyWithTheModule(): void
    {
        $entry = ['key' => 'can_manage_polls', 'label' => 'Umfragen verwalten'];

        $this->assertContains(
            $entry,
            array_merge(...array_column(RolePermissionCatalog::groupsForModules(['polls' => true]), 'permissions'))
        );
        $this->assertNotContains(
            $entry,
            array_merge(...array_column(RolePermissionCatalog::groupsForModules([]), 'permissions'))
        );
    }

    public function testRolesJsPopulatesTheRightOnEdit(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__) . '/../public/js/roles.js');
        $this->assertStringContainsString('data-polls', $js);
        $this->assertStringContainsString('edit_can_manage_polls', $js);
    }

    public function testTheGateRejectsWithoutTheRight(): void
    {
        $gates = (new \ReflectionClass(RoleMiddleware::class))->getConstant('GATES');
        $this->assertSame(['can_manage_polls'], $gates['requiresPollManagement']['permissions'] ?? null);
    }

    public function testANewRoleWithoutTheFieldDoesNotHoldTheRight(): void
    {
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();

        $role = Role::create(['name' => 'Ohne Umfragerecht ' . bin2hex(random_bytes(4)), 'hierarchy_level' => 10]);

        $this->assertFalse((bool) $role->fresh()?->can_manage_polls);
    }

    public function testSetupGrantsTheRightToTheFirstAdmin(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__) . '/../src/Controllers/AuthController.php');
        $this->assertStringContainsString("'can_manage_polls' => 1", $source);
    }
}
