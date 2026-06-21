<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\RememberLogin;
use App\Services\BackupLimitReachedException;
use App\Services\BackupService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Unit\Bootstrap;
use Tests\Unit\Services\Fakes\FakeDumpRunner;

final class BackupServiceTest extends TestCase
{
    private string $backupDir;
    private FakeDumpRunner $dumpRunner;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();

        $this->backupDir = sys_get_temp_dir() . '/chormanager_backup_test_' . bin2hex(random_bytes(4));
        $this->dumpRunner = new FakeDumpRunner();
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

    private function makeService(int $maxManual = 5, int $maxAuto = 5): BackupService
    {
        return new BackupService(
            $this->dumpRunner,
            new NullLogger(),
            $this->backupDir,
            $maxManual,
            $maxAuto,
            true,
            'chormanager_test',
            'test-version'
        );
    }

    public function testCreateManualBackupWritesDataAndMetadataFiles(): void
    {
        $service = $this->makeService();

        $metadata = $service->create(BackupService::TYPE_MANUAL, 7);

        $this->assertSame('manual', $metadata['type']);
        $this->assertSame(7, $metadata['created_by']);
        $this->assertFileExists($this->backupDir . '/' . $metadata['id'] . '.sql.gz');
        $this->assertFileExists($this->backupDir . '/' . $metadata['id'] . '.json');
        $this->assertSame(1, $this->dumpRunner->dumpCallCount);
    }

    public function testManualBackupBlocksWhenLimitReached(): void
    {
        $service = $this->makeService(maxManual: 1);

        $service->create(BackupService::TYPE_MANUAL, 1);

        $this->expectException(BackupLimitReachedException::class);
        $service->create(BackupService::TYPE_MANUAL, 1);
    }

    public function testAutoBackupRotatesOldestWhenLimitReached(): void
    {
        $service = $this->makeService(maxAuto: 2);

        $first = $service->create(BackupService::TYPE_AUTO, null);
        usleep(1100000);
        $second = $service->create(BackupService::TYPE_AUTO, null);
        usleep(1100000);
        $third = $service->create(BackupService::TYPE_AUTO, null);

        $remainingIds = array_column($service->list(), 'id');

        $this->assertCount(2, $remainingIds);
        $this->assertNotContains($first['id'], $remainingIds);
        $this->assertContains($second['id'], $remainingIds);
        $this->assertContains($third['id'], $remainingIds);
    }

    public function testListReturnsEntriesSortedNewestFirst(): void
    {
        $service = $this->makeService();

        $first = $service->create(BackupService::TYPE_MANUAL, 1);
        usleep(1100000);
        $second = $service->create(BackupService::TYPE_MANUAL, 1);

        $entries = $service->list();

        $this->assertSame($second['id'], $entries[0]['id']);
        $this->assertSame($first['id'], $entries[1]['id']);
    }

    public function testDeleteRemovesDataAndMetadataFiles(): void
    {
        $service = $this->makeService();
        $metadata = $service->create(BackupService::TYPE_MANUAL, 1);

        $service->delete($metadata['id']);

        $this->assertFileDoesNotExist($this->backupDir . '/' . $metadata['id'] . '.sql.gz');
        $this->assertFileDoesNotExist($this->backupDir . '/' . $metadata['id'] . '.json');
        $this->assertCount(0, $service->list());
    }

    public function testRestoreVerifiesChecksumAndInvokesDumpRunner(): void
    {
        $service = $this->makeService();
        $metadata = $service->create(BackupService::TYPE_MANUAL, 1);

        $service->restore($metadata['id']);

        $this->assertSame(1, $this->dumpRunner->restoreCallCount);
        $this->assertSame(
            $this->backupDir . '/' . $metadata['id'] . '.sql.gz',
            $this->dumpRunner->lastRestoredPath
        );
    }

    public function testRestoreInvalidatesAllRememberLoginTokens(): void
    {
        RememberLogin::create([
            'user_id' => 1,
            'selector' => bin2hex(random_bytes(9)),
            'token_hash' => password_hash('irrelevant', PASSWORD_DEFAULT),
            'expires_at' => date('Y-m-d H:i:s', time() + 86400),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $service = $this->makeService();
        $metadata = $service->create(BackupService::TYPE_MANUAL, 1);

        $service->restore($metadata['id']);

        $this->assertSame(0, RememberLogin::count());
    }

    public function testRestoreThrowsWhenChecksumMismatches(): void
    {
        $service = $this->makeService();
        $metadata = $service->create(BackupService::TYPE_MANUAL, 1);

        file_put_contents($this->backupDir . '/' . $metadata['id'] . '.sql.gz', 'tampered content');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('integrity check failed');
        $service->restore($metadata['id']);
    }

    public function testRestoreRejectsInvalidId(): void
    {
        $service = $this->makeService();

        $this->expectException(\InvalidArgumentException::class);
        $service->restore('../../etc/passwd');
    }

    public function testGetFileReturnsPathFilenameAndSize(): void
    {
        $service = $this->makeService();
        $metadata = $service->create(BackupService::TYPE_MANUAL, 1);

        $file = $service->getFile($metadata['id']);

        $this->assertSame($this->backupDir . '/' . $metadata['id'] . '.sql.gz', $file['path']);
        $this->assertSame($metadata['id'] . '.sql.gz', $file['filename']);
        $this->assertSame($metadata['size'], $file['size']);
    }
}
