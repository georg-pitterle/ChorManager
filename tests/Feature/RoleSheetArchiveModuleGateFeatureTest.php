<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class RoleSheetArchiveModuleGateFeatureTest extends TestCase
{
    private function template(): string
    {
        $template = file_get_contents(dirname(__DIR__) . '/../templates/roles/index.twig');
        $this->assertIsString($template);

        return $template;
    }

    public function testPermissionMatrixRowIsGatedByModuleFlag(): void
    {
        $pattern = '#\{% if settings\.modules\.sheet_archive %\}\s*'
            . '<tr>\s*'
            . '<th scope="row">Notenarchiv verwalten</th>#s';

        $this->assertMatchesRegularExpression(
            $pattern,
            $this->template(),
            'Notenarchiv-Zeile der Rechte-Matrix muss hinter settings.modules.sheet_archive stehen'
        );
    }

    public function testCreateModalCheckboxIsGatedByModuleFlag(): void
    {
        $pattern = '#\{% if settings\.modules\.sheet_archive %\}'
            . '(?:(?!\{% endif %\}).)*'
            . 'id="can_manage_sheet_archive"#s';

        $this->assertMatchesRegularExpression(
            $pattern,
            $this->template(),
            'Notenarchiv-Checkbox im Anlegen-Modal muss hinter settings.modules.sheet_archive stehen'
        );
    }

    public function testEditModalCheckboxIsGatedByModuleFlag(): void
    {
        $pattern = '#\{% if settings\.modules\.sheet_archive %\}'
            . '(?:(?!\{% endif %\}).)*'
            . 'id="edit_can_manage_sheet_archive"#s';

        $this->assertMatchesRegularExpression(
            $pattern,
            $this->template(),
            'Notenarchiv-Checkbox im Bearbeiten-Modal muss hinter settings.modules.sheet_archive stehen'
        );
    }
}
