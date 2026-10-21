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
use Psr\Log\AbstractLogger;
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

    private function command(bool $enabled, ?AbstractLogger $logger = null): CommandTester
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

        return new CommandTester(new PurgeFileTrashCommand($trash, $this->storage(), $enabled, $logger ?? new NullLogger()));
    }

    public function testPurgesExpiredFoldersAndReports(): void
    {
        $root = $this->createFolder('Wurzel');
        $old = $this->createFolder('Alt', $root);
        Carbon::setTestNow(Carbon::now()->subDays(40));
        $old->delete();
        Carbon::setTestNow();

        $logger = new class extends AbstractLogger {
            /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };
        $tester = $this->command(true, $logger);
        $tester->execute([]);

        $this->assertNull(FileFolder::withTrashed()->find($old->id));
        $this->assertSame('', $tester->getDisplay(), 'Ergebnis steht im strukturierten Log, nicht als Klartext.');
        $completed = array_values(array_filter(
            $logger->records,
            static fn (array $r): bool => ($r['context']['event'] ?? '') === 'files.trash.purge_completed'
        ));
        $this->assertCount(1, $completed);
        $this->assertSame('info', $completed[0]['level']);
        $this->assertSame(1, $completed[0]['context']['folders']);
        $this->assertSame(0, $completed[0]['context']['files']);
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
