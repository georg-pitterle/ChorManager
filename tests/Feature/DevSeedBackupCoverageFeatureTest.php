<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

final class DevSeedBackupCoverageFeatureTest extends TestCase
{
    public function testDevSeedServiceSeedsBackupPermissionAndExampleBackup(): void
    {
        $content = file_get_contents(dirname(__DIR__, 2) . '/src/Services/DevSeedService.php');

        $this->assertIsString($content);
        // Das Backup-Recht hält der Seed-Admin wie jedes Recht - siehe DevSeedAdminRoleFeatureTest.
        $this->assertStringContainsString('array_fill_keys(Role::PERMISSIONS, 1)', $content);
        $this->assertStringContainsString("'backups' => 0,", $content);
        $this->assertStringContainsString('function seedBackups', $content);
        $this->assertStringContainsString('BackupService', $content);
    }
}
