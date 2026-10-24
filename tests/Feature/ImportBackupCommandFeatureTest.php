<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Commands\ImportBackupCommand;
use App\Services\BackupPackageService;
use App\Services\BackupService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Unit\Services\Fakes\FakeDumpRunner;

/**
 * Pakete aus einer anderen Installation werden nur über die Konsole übernommen.
 */
final class ImportBackupCommandFeatureTest extends TestCase
{
    use FileFixtures;

    private string $sourceDir;
    private string $targetDir;

    protected function setUp(): void
    {
        parent::setUp();
        $suffix = bin2hex(random_bytes(4));
        $this->sourceDir = sys_get_temp_dir() . '/chormanager_import_cli_source_' . $suffix;
        $this->targetDir = sys_get_temp_dir() . '/chormanager_import_cli_target_' . $suffix;
    }

    protected function tearDown(): void
    {
        self::removeDirectory($this->sourceDir);
        self::removeDirectory($this->targetDir);

        parent::tearDown();
    }

    private function backups(string $dir): BackupService
    {
        return new BackupService(new FakeDumpRunner(), new NullLogger(), $dir, 5, 5, true, 'chormanager_test', 'test-version');
    }

    private function packages(string $dir): BackupPackageService
    {
        return new BackupPackageService($this->backups($dir), $dir, null, new NullLogger());
    }

    private function tester(): CommandTester
    {
        $this->backups($this->targetDir);

        return new CommandTester(new ImportBackupCommand($this->packages($this->targetDir), new NullLogger()));
    }

    private function waitingPackage(): string
    {
        $meta = $this->backups($this->sourceDir)->create(BackupService::TYPE_MANUAL, null);
        $package = $this->packages($this->sourceDir)->build($meta['id']);
        $this->backups($this->targetDir);
        rename($package['path'], $this->targetDir . '/' . $meta['id'] . '.zip');

        return $meta['id'];
    }

    public function testImportsWaitingPackageAndReportsSuccess(): void
    {
        $id = $this->waitingPackage();
        $tester = $this->tester();

        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Übernommen: ' . $id, $tester->getDisplay());
        $this->assertCount(1, $this->backups($this->targetDir)->list());
    }

    public function testReportsFailedPackageWithReasonAndFailureExitCode(): void
    {
        $this->backups($this->targetDir);
        $id = 'backup_manual_20260101T000000Z_0badc0de';
        file_put_contents($this->targetDir . '/' . $id . '.zip', 'kein ZIP');
        $tester = $this->tester();

        $exitCode = $tester->execute([]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString(
            'Fehlgeschlagen: ' . $id . ' – Paket ist unvollständig oder beschädigt.',
            $tester->getDisplay()
        );
    }

    public function testReportsNothingToImport(): void
    {
        $tester = $this->tester();

        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Keine Pakete zum Übernehmen gefunden.', $tester->getDisplay());
    }

    public function testFailsWhenLockIsHeld(): void
    {
        $this->waitingPackage();
        $tester = $this->tester();
        $lock = fopen($this->targetDir . '/.import.lock', 'c');
        flock($lock, LOCK_EX);

        try {
            $exitCode = $tester->execute([]);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('Eine andere Übernahme läuft gerade.', $tester->getDisplay());
    }
}
