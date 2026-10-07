<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Storage;

use App\Services\Storage\BackupUsageProvider;
use PHPUnit\Framework\TestCase;

final class BackupUsageProviderTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/backups-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/files/ab', 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function put(string $relative, int $bytes): void
    {
        file_put_contents($this->dir . '/' . $relative, str_repeat('x', $bytes));
    }

    public function testDumpsAreSplitByTypeAndCountedPerBackup(): void
    {
        $manual = 'backup_manual_20261001T100000Z_0123abcd';
        $auto1 = 'backup_auto_20261002T030000Z_89abcdef';
        $auto2 = 'backup_auto_20261003T030000Z_deadbeef';
        $this->put($manual . '.sql.gz', 1000);
        $this->put($manual . '.json', 10);
        $this->put($manual . '.files.json', 5);
        $this->put($auto1 . '.sql.gz', 200);
        $this->put($auto1 . '.json', 10);
        $this->put($auto2 . '.sql', 300);
        $this->put($auto2 . '.json', 10);
        $this->put('files/ab/abcdef', 4000);
        $this->put('notiz.txt', 7);

        $node = (new BackupUsageProvider($this->dir))->usage();

        $this->assertSame(1015, $node->child('backups.dumps.manual')?->bytes);
        $this->assertSame(1, $node->child('backups.dumps.manual')?->count);
        $this->assertSame(520, $node->child('backups.dumps.auto')?->bytes);
        $this->assertSame(2, $node->child('backups.dumps.auto')?->count);
        $this->assertSame(4000, $node->child('backups.file_pool')?->bytes);
        $this->assertSame(1, $node->child('backups.file_pool')?->count);
        $this->assertSame(7, $node->child('backups.other')?->bytes);
        $this->assertSame(5542, $node->bytes);
    }

    public function testMissingDirectoryIsZeroWithoutError(): void
    {
        $node = (new BackupUsageProvider($this->dir . '/fehlt'))->usage();

        $this->assertSame(0, $node->bytes);
        $this->assertNull($node->error);
        $this->assertNull($node->child('backups.other'));
    }
}
