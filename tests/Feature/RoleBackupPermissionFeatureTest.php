<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\RoleController;
use PHPUnit\Framework\TestCase;

final class RoleBackupPermissionFeatureTest extends TestCase
{
    public function testBuildPermissionFlagsMapsBackupCheckboxPresence(): void
    {
        $withFlag = RoleController::buildPermissionFlags(['can_manage_backups' => '1']);
        $this->assertSame(1, $withFlag['can_manage_backups']);

        $withoutFlag = RoleController::buildPermissionFlags([]);
        $this->assertSame(0, $withoutFlag['can_manage_backups']);
    }

    public function testRolesTemplateExposesBackupCheckboxAndTableColumn(): void
    {
        $templateContent = file_get_contents(dirname(__DIR__) . '/../templates/roles/index.twig');

        $this->assertIsString($templateContent);
        $this->assertStringContainsString('name="can_manage_backups"', $templateContent);
        $this->assertStringContainsString('role.can_manage_backups', $templateContent);
        $this->assertStringContainsString('data-backups=', $templateContent);
    }
}
