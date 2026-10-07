<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\RoleController;
use App\Services\RolePermissionCatalog;
use PHPUnit\Framework\TestCase;

/**
 * UI wiring for the voice-group-scoped project assignment right
 * (can_assign_own_voice_group_to_project): the roles screen must expose it in
 * the matrix and in both modals, and the flag builder must persist it.
 */
class RoleAssignOwnVoiceGroupProjectUiFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testBuildPermissionFlagsIncludesAssignOwnVoiceGroupProject(): void
    {
        $flags = RoleController::buildPermissionFlags(['can_assign_own_voice_group_to_project' => '1']);
        $this->assertSame(1, $flags['can_assign_own_voice_group_to_project']);

        $flagsOff = RoleController::buildPermissionFlags([]);
        $this->assertSame(0, $flagsOff['can_assign_own_voice_group_to_project']);
    }

    public function testRolesTemplateOffersCheckboxInBothModals(): void
    {
        // Die Bearbeiten-Buttons mit den data-*-Attributen stehen im Übersichts-Partial.
        $template = file_get_contents(dirname(__DIR__) . '/../templates/roles/index.twig')
            . file_get_contents(dirname(__DIR__) . '/../templates/roles/_overview.twig');
        $this->assertIsString($template);
        $this->assertStringContainsString('id="can_assign_own_voice_group_to_project"', $template);
        $this->assertStringContainsString('id="edit_can_assign_own_voice_group_to_project"', $template);
        $this->assertStringContainsString('name="can_assign_own_voice_group_to_project"', $template);
        $this->assertStringContainsString('data-assign-own-voice-group-project="', $template);
    }

    public function testCatalogListsAssignOwnVoiceGroupProjectPermission(): void
    {
        // Die Rollenübersicht zeigt jedes Recht aus dem Katalog; ohne Eintrag sähe niemand, wer es hält.
        $this->assertContains(
            ['key' => 'can_assign_own_voice_group_to_project', 'label' => 'Eigene Stimmgruppe ins Projekt zuweisen'],
            array_merge(...array_column(RolePermissionCatalog::groupsForModules([]), 'permissions'))
        );
    }

    public function testRolesJsPopulatesAssignOwnVoiceGroupProjectOnEdit(): void
    {
        $js = file_get_contents(dirname(__DIR__) . '/../public/js/roles.js');
        $this->assertIsString($js);
        $this->assertStringContainsString('data-assign-own-voice-group-project', $js);
        $this->assertStringContainsString('edit_can_assign_own_voice_group_to_project', $js);
    }
}
