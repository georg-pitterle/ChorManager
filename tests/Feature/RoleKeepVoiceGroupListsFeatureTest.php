<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\RoleController;
use App\Models\Role;
use App\Services\RolePermissionCatalog;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Das Recht "Stimmgruppen-Listen führen" (can_keep_voice_group_lists).
 *
 * Es ist bewusst ein eigenes Recht und nicht "Eigene Stimmgruppe verwalten":
 * Jenes halten auch Admin, Vorstand, Kassier und Chorleitung, und die Listen
 * sollen nur die Stimmvertretungen sehen. Deshalb bekommt es auch keine
 * bestehende Rolle von selbst - weder per Migration noch bei der Einrichtung.
 */
final class RoleKeepVoiceGroupListsFeatureTest extends TestCase
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

    public function testBuildPermissionFlagsIncludesKeepVoiceGroupLists(): void
    {
        $flags = RoleController::buildPermissionFlags(['can_keep_voice_group_lists' => '1']);
        $this->assertSame(1, $flags['can_keep_voice_group_lists']);

        $flagsOff = RoleController::buildPermissionFlags([]);
        $this->assertSame(0, $flagsOff['can_keep_voice_group_lists']);
    }

    public function testRolesTemplateOffersCheckboxInBothModals(): void
    {
        $template = file_get_contents(dirname(__DIR__) . '/../templates/roles/index.twig')
            . file_get_contents(dirname(__DIR__) . '/../templates/roles/_overview.twig');
        $this->assertIsString($template);
        $this->assertStringContainsString('id="can_keep_voice_group_lists"', $template);
        $this->assertStringContainsString('id="edit_can_keep_voice_group_lists"', $template);
        $this->assertStringContainsString('name="can_keep_voice_group_lists"', $template);
        $this->assertStringContainsString('data-keep-voice-group-lists="', $template);
    }

    public function testCatalogListsTheRight(): void
    {
        $this->assertContains(
            ['key' => 'can_keep_voice_group_lists', 'label' => 'Stimmgruppen-Listen führen'],
            array_merge(...array_column(RolePermissionCatalog::groupsForModules([]), 'permissions'))
        );
    }

    public function testRolesJsPopulatesTheRightOnEdit(): void
    {
        $js = file_get_contents(dirname(__DIR__) . '/../public/js/roles.js');
        $this->assertIsString($js);
        $this->assertStringContainsString('data-keep-voice-group-lists', $js);
        $this->assertStringContainsString('edit_can_keep_voice_group_lists', $js);
    }

    public function testANewRoleWithoutTheFieldDoesNotHoldTheRight(): void
    {
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();

        $role = Role::create(['name' => 'Ohne Listenrecht ' . bin2hex(random_bytes(4)), 'hierarchy_level' => 10]);

        $this->assertFalse((bool) $role->fresh()?->can_keep_voice_group_lists);
    }

    public function testSetupDoesNotGrantTheRightToTheFirstAdmin(): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/../src/Controllers/AuthController.php');
        $this->assertIsString($source);
        $this->assertStringNotContainsString('can_keep_voice_group_lists', $source);
    }

    public function testOnlyTheRepresentativeSeedRolesHoldTheRight(): void
    {
        $seed = file_get_contents(dirname(__DIR__) . '/../src/Services/DevSeedService.php');
        $this->assertIsString($seed);
        $this->assertSame(2, substr_count($seed, "'can_keep_voice_group_lists' => 1"));
        $this->assertSame(5, substr_count($seed, "'can_keep_voice_group_lists' => 0"));
    }
}
