<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

final class BackupDependencyWiringFeatureTest extends TestCase
{
    public function testSettingsExposeBackupConfiguration(): void
    {
        $content = file_get_contents(dirname(__DIR__, 2) . '/src/Settings.php');

        $this->assertIsString($content);
        $this->assertStringContainsString("'backup' =>", $content);
        $this->assertStringContainsString('BACKUP_DIR', $content);
        $this->assertStringContainsString('BACKUP_MAX_MANUAL', $content);
        $this->assertStringContainsString('BACKUP_MAX_AUTO', $content);
    }

    public function testDependenciesWireBackupServiceAndDumpRunner(): void
    {
        $content = file_get_contents(dirname(__DIR__, 2) . '/src/Dependencies.php');

        $this->assertIsString($content);
        $this->assertStringContainsString('DumpRunnerInterface::class', $content);
        $this->assertStringContainsString('MysqldumpRunner', $content);
        $this->assertStringContainsString('BackupService::class', $content);
        $this->assertStringContainsString('BackupController::class', $content);
    }
}
