<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Commands\PurgeFileTrashCommand;
use App\Models\FileFolder;
use App\Services\Files\FileAccessService;
use App\Services\Files\FileFolderService;
use App\Services\Files\FileQuotaService;
use App\Services\Files\FileService;
use App\Services\Files\FileStorageRegistry;
use App\Services\Files\FileTrashService;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Tester\CommandTester;

class PurgeFileTrashCommandFeatureTest extends TestCase
{
    use FileFixtures;

    protected function setUp(): void
    {
        $this->setUpFileFixtures();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->tearDownFileFixtures();
    }

    private function command(bool $enabled): CommandTester
    {
        $access = new FileAccessService();
        $quota = new FileQuotaService($access, 0);
        $registry = new FileStorageRegistry($this->storage());
        $files = new FileService(
            $access,
            new FileFolderService($access, $quota, new NullLogger()),
            $quota,
            $registry,
            new NullLogger(),
            1 << 20,
            5
        );
        $trash = new FileTrashService($access, $files, $registry, new NullLogger(), 30);

        return new CommandTester(new PurgeFileTrashCommand($trash, $this->storage(), $enabled));
    }

    public function testPurgesExpiredFoldersAndReports(): void
    {
        $root = $this->createFolder('Wurzel');
        $old = $this->createFolder('Alt', $root);
        Carbon::setTestNow(Carbon::now()->subDays(40));
        $old->delete();
        Carbon::setTestNow();

        $tester = $this->command(true);
        $tester->execute([]);

        $this->assertStringContainsString('1 Ordner', $tester->getDisplay());
        $this->assertNull(FileFolder::withTrashed()->find($old->id));
    }

    public function testDryRunAndDisabledModuleChangeNothing(): void
    {
        $root = $this->createFolder('Wurzel');
        $old = $this->createFolder('Alt', $root);
        Carbon::setTestNow(Carbon::now()->subDays(40));
        $old->delete();
        Carbon::setTestNow();

        $this->command(true)->execute(['--dry-run' => true, '--orphans' => true]);
        $this->command(false)->execute([]);

        $this->assertNotNull(FileFolder::withTrashed()->find($old->id));
    }
}
