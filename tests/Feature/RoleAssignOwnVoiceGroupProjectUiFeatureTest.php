<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\RoleController;
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
        $template = file_get_contents(dirname(__DIR__) . '/../templates/roles/index.twig');
        $this->assertIsString($template);
        $this->assertStringContainsString('id="can_assign_own_voice_group_to_project"', $template);
        $this->assertStringContainsString('id="edit_can_assign_own_voice_group_to_project"', $template);
        $this->assertStringContainsString('name="can_assign_own_voice_group_to_project"', $template);
        $this->assertStringContainsString('data-assign-own-voice-group-project="', $template);
    }

    public function testRolesTemplateShowsAssignOwnVoiceGroupProjectMatrixRow(): void
    {
        $template = file_get_contents(dirname(__DIR__) . '/../templates/roles/index.twig');
        $this->assertIsString($template);

        $rowPattern = '#<th scope="row" class="roles-matrix-label">Eigene Stimmgruppe ins Projekt zuweisen</th>\s*'
            . '\{% for role in roles %\}\s*'
            . '<td[^>]*>\s*'
            . '\{% if role\.can_assign_own_voice_group_to_project %\}#s';
        $this->assertMatchesRegularExpression(
            $rowPattern,
            $template,
            'permission matrix must have a dedicated row for Eigene Stimmgruppe ins Projekt zuweisen'
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
