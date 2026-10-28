<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Commands\CreateBackupCommand;
use App\Services\BackupLock;
use App\Services\BackupLockedException;
use App\Services\BackupService;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Unit\Services\Fakes\FakeDumpRunner;

final class CreateBackupCommandFeatureTest extends TestCase
{
    private string $backupDir;

    /** @var resource|null */
    private $foreignLock = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->backupDir = sys_get_temp_dir() . '/chormanager_backup_cli_test_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (is_resource($this->foreignLock)) {
            flock($this->foreignLock, LOCK_UN);
            fclose($this->foreignLock);
            $this->foreignLock = null;
        }
        // scandir statt glob: Die Sperrdatei beginnt mit einem Punkt.
        if (is_dir($this->backupDir)) {
            foreach (array_diff(scandir($this->backupDir) ?: [], ['.', '..']) as $entry) {
                unlink($this->backupDir . '/' . $entry);
            }
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

    public function testSuccessIsLoggedAsJsonAndNotPrintedAtNormalVerbosity(): void
    {
        $handler = new TestHandler();
        $logger = new Logger('test');
        $logger->pushHandler($handler);

        $command = new CreateBackupCommand($this->fakeService('backup_auto_20260101T000000Z_deadbeef'), $logger);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $this->assertStringNotContainsString('Backup created', $tester->getDisplay());

        $records = $handler->getRecords();
        $this->assertCount(1, $records);
        $record = $records[0];
        $this->assertSame(Level::Info, $record->level);
        $this->assertSame('backup.create.completed', $record->context['event']);
        $this->assertSame('backup_auto_20260101T000000Z_deadbeef', $record->context['id']);
        $this->assertSame('auto', $record->context['type']);
    }

    public function testSuccessIsPrintedWhenVerbose(): void
    {
        $handler = new TestHandler();
        $logger = new Logger('test');
        $logger->pushHandler($handler);

        $command = new CreateBackupCommand($this->fakeService('backup_auto_20260101T000000Z_deadbeef'), $logger);
        $tester = new CommandTester($command);

        $tester->execute([], ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]);

        $this->assertStringContainsString('Backup created', $tester->getDisplay());
    }

    /**
     * Trifft der nächtliche Lauf auf ein gerade laufendes Backup von Hand, ist das
     * kein Fehler, sondern Gleichzeitigkeit. Als `error` protokolliert stünde es
     * morgen in der Loganalyse wie ein Defekt; der Rückgabewert bleibt aber
     * FAILURE, weil in dieser Nacht eben kein Auto-Backup entstanden ist.
     */
    public function testLockedRunIsLoggedAsWarningAndNotAsError(): void
    {
        $handler = new TestHandler();
        $logger = new Logger('test');
        $logger->pushHandler($handler);

        $command = new CreateBackupCommand($this->lockedService(), $logger);
        $exitCode = (new CommandTester($command))->execute([]);

        $this->assertSame(Command::FAILURE, $exitCode);

        $records = $handler->getRecords();
        $this->assertCount(1, $records);
        $this->assertSame(Level::Warning, $records[0]->level);
        $this->assertSame('backup.create.skipped', $records[0]->context['event']);
    }

    /**
     * Ein Dienst, dessen Sperre schon jemand anderes hält.
     */
    private function lockedService(): BackupService
    {
        if (!is_dir($this->backupDir)) {
            mkdir($this->backupDir, 0750, true);
        }
        $handle = fopen($this->backupDir . '/' . BackupLock::LOCK_FILE, 'c');
        $this->assertIsResource($handle);
        $this->assertTrue(flock($handle, LOCK_EX | LOCK_NB));
        $this->foreignLock = $handle;

        return new BackupService(
            new FakeDumpRunner(),
            new NullLogger(),
            $this->backupDir,
            5,
            5,
            true,
            'chormanager_test',
            'test-version',
            null,
            new \App\Services\SessionInvalidationService(),
            null,
            null,
            [],
            null,
            new BackupLock($this->backupDir)
        );
    }

    private function fakeService(string $id): BackupService
    {
        return new class ($id) extends BackupService {
            public function __construct(private readonly string $id)
            {
            }

            public function create(string $type, ?int $userId = null): array
            {
                return ['id' => $this->id, 'type' => $type];
            }
        };
    }
}
