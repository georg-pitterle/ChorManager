<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Commands\CreateBackupCommand;
use App\Services\BackupService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Unit\Services\Fakes\FakeDumpRunner;

final class CreateBackupCommandFeatureTest extends TestCase
{
    private string $backupDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->backupDir = sys_get_temp_dir() . '/chormanager_backup_cli_test_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->backupDir . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->backupDir)) {
            rmdir($this->backupDir);
        }

        parent::tearDown();
    }

    private function makeTester(BackupService $service): CommandTester
    {
        $command = new CreateBackupCommand($service, new NullLogger());
        return new CommandTester($command);
    }

    private function makeService(): BackupService
    {
        return new BackupService(
            new FakeDumpRunner(),
            new NullLogger(),
            $this->backupDir,
            5,
            5,
            true,
            'chormanager_test',
            'test-version'
        );
    }

    public function testCreatesAutoBackupByDefault(): void
    {
        $service = $this->makeService();
        $tester = $this->makeTester($service);

        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $entries = $service->list();
        $this->assertCount(1, $entries);
        $this->assertSame('auto', $entries[0]['type']);
    }

    public function testCreatesManualBackupWhenTypeOptionGiven(): void
    {
        $service = $this->makeService();
        $tester = $this->makeTester($service);

        $exitCode = $tester->execute(['--type' => 'manual']);

        $this->assertSame(0, $exitCode);
        $entries = $service->list();
        $this->assertSame('manual', $entries[0]['type']);
    }

    public function testRejectsInvalidType(): void
    {
        $service = $this->makeService();
        $tester = $this->makeTester($service);

        $exitCode = $tester->execute(['--type' => 'bogus']);

        $this->assertSame(Command::INVALID, $exitCode);
        $this->assertCount(0, $service->list());
    }
}
