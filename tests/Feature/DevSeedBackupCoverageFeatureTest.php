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
        $this->assertStringContainsString("'can_manage_backups' => 1,", $content);
        $this->assertStringContainsString("'backups' => 0,", $content);
        $this->assertStringContainsString('function seedBackups', $content);
        $this->assertStringContainsString('BackupService', $content);
    }
}
