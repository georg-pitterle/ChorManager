<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\RoleController;
use App\Models\Role;
use PHPUnit\Framework\TestCase;

class RoleFilesPermissionFeatureTest extends TestCase
{
    private function template(): string
    {
        $template = file_get_contents(dirname(__DIR__) . '/../templates/roles/index.twig');
        $this->assertIsString($template);

        return $template;
    }

    public function testPermissionIsKnownToRoleModel(): void
    {
        $this->assertContains('can_manage_files', Role::PERMISSIONS);
    }

    public function testSettingsExposeFilesModuleFlag(): void
    {
        $builder = new \DI\ContainerBuilder();
        $settings = require dirname(__DIR__, 2) . '/src/Settings.php';
        $settings($builder);
        $values = $builder->build()->get('settings');

        $this->assertArrayHasKey('files', $values['modules']);
        $this->assertArrayHasKey('files', $values);
        $this->assertSame(30, $values['files']['trash_days']);
    }

    public function testDisabledModuleIgnoresSubmittedValue(): void
    {
        $flags = RoleController::buildPermissionFlags(
            ['can_manage_files' => '1'],
            ['files' => false],
            ['can_manage_files' => 0]
        );

        $this->assertSame(0, $flags['can_manage_files']);
    }

    public function testEnabledModuleTakesSubmittedValue(): void
    {
        $flags = RoleController::buildPermissionFlags(['can_manage_files' => '1'], ['files' => true]);

        $this->assertSame(1, $flags['can_manage_files']);
    }

    public function testPermissionMatrixRowIsGatedByModuleFlag(): void
    {
        $pattern = '#\{% if settings\.modules\.files %\}\s*'
            . '<tr>\s*'
            . '<th scope="row" class="roles-matrix-label">Dateiverwaltung verwalten</th>#s';

        $this->assertMatchesRegularExpression($pattern, $this->template());
    }

    public function testCreateAndEditCheckboxesAreGatedByModuleFlag(): void
    {
        foreach (['can_manage_files', 'edit_can_manage_files'] as $id) {
            $pattern = '#\{% if settings\.modules\.files %\}'
                . '(?:(?!\{% endif %\}).)*'
                . 'id="' . $id . '"#s';

            $this->assertMatchesRegularExpression($pattern, $this->template(), $id);
        }
    }
}
