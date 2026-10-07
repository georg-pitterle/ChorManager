<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Storage;

use App\Services\Storage\StorageUsageNode;
use App\Services\Storage\StorageUsageProvider;
use App\Services\Storage\StorageUsageService;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

final class StorageUsageServiceTest extends TestCase
{
    private string $cache = '';
    private int $now = 1_800_000_000;
    private int $calls = 0;
    private TestHandler $log;

    protected function setUp(): void
    {
        $this->cache = sys_get_temp_dir() . '/storage-cache-' . bin2hex(random_bytes(6)) . '/summary.json';
        $this->calls = 0;
        $this->log = new TestHandler();
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg(dirname($this->cache)));
    }

    public function countCall(): void
    {
        $this->calls++;
    }

    private function provider(string $key, int $bytes, bool $throws = false): StorageUsageProvider
    {
        return new class ($key, $bytes, $throws, $this) implements StorageUsageProvider {
            public function __construct(
                private readonly string $key,
                private readonly int $bytes,
                private readonly bool $throws,
                private readonly StorageUsageServiceTest $test
            ) {
            }

            public function key(): string
            {
                return $this->key;
            }

            public function label(): string
            {
                return 'Bereich ' . $this->key;
            }

            public function usage(): StorageUsageNode
            {
                $this->test->countCall();
                if ($this->throws) {
                    throw new \RuntimeException('kaputt');
                }

                return new StorageUsageNode($this->key, $this->label(), $this->bytes);
            }
        };
    }

    /**
     * @param list<StorageUsageProvider> $providers
     */
    private function service(array $providers): StorageUsageService
    {
        $logger = new Logger('test');
        $logger->pushHandler($this->log);

        return new StorageUsageService($providers, $this->cache, $logger, fn (): int => $this->now);
    }

    /**
     * @return list<string|null>
     */
    private function events(): array
    {
        return array_map(
            static fn (LogRecord $record): ?string => $record->context['event'] ?? null,
            $this->log->getRecords()
        );
    }

    public function testTotalIsSumOfAreasAndCollectWritesTheCache(): void
    {
        $report = $this->service([$this->provider('a', 100), $this->provider('b', 23)])->collect();

        $this->assertSame(123, $report->totalBytes());
        $this->assertSame(100, $report->area('a')?->bytes);
        $this->assertFileExists($this->cache);
    }

    public function testAThrowingAreaBecomesAnErrorNodeAndIsLogged(): void
    {
        $report = $this->service([$this->provider('a', 100), $this->provider('b', 999, true)])->collect();

        $this->assertSame(100, $report->totalBytes());
        $this->assertSame('nicht ermittelbar', $report->area('b')?->error);
        $this->assertSame('Bereich b', $report->area('b')?->label);
        $records = array_values(array_filter(
            $this->log->getRecords(),
            static fn (LogRecord $record): bool => ($record->context['event'] ?? null) === 'storage.usage_failed'
        ));
        $this->assertCount(1, $records);
        $this->assertSame('b', $records[0]->context['provider']);
        $this->assertInstanceOf(\RuntimeException::class, $records[0]->context['exception']);
    }

    public function testFreshCacheIsUsedWithoutRecomputing(): void
    {
        $service = $this->service([$this->provider('a', 100)]);
        $service->collect();
        $this->calls = 0;
        $this->now += 3599;

        $summary = $service->summary();

        $this->assertSame(0, $this->calls);
        $this->assertSame(100, $summary->totalBytes);
        $this->assertSame([['key' => 'a', 'label' => 'Bereich a', 'bytes' => 100, 'failed' => false]], $summary->areas);
    }

    public function testStaleCacheIsRecomputed(): void
    {
        $service = $this->service([$this->provider('a', 100)]);
        $service->collect();
        $this->calls = 0;
        $this->now += 3600;

        $service->summary();

        $this->assertSame(1, $this->calls);
    }

    /**
     * Ein Zeitstempel in der Zukunft (verstellte Uhr) darf nicht als frisch gelten -
     * sonst bliebe die Kachel beliebig lange auf dem alten Stand.
     */
    public function testCacheFromTheFutureIsRecomputed(): void
    {
        $service = $this->service([$this->provider('a', 100)]);
        $service->collect();
        $this->calls = 0;
        $this->now -= 60;

        $service->summary();

        $this->assertSame(1, $this->calls);
    }

    public function testBrokenCacheCountsAsMissing(): void
    {
        mkdir(dirname($this->cache), 0777, true);
        file_put_contents($this->cache, '{kaputt');

        $summary = $this->service([$this->provider('a', 5)])->summary();

        $this->assertSame(1, $this->calls);
        $this->assertSame(5, $summary->totalBytes);
    }

    public function testFailedAreaIsFlaggedInTheSummary(): void
    {
        $summary = $this->service([$this->provider('a', 5), $this->provider('b', 1, true)])->summary();

        $this->assertTrue($summary->areas[1]['failed']);
        $this->assertFalse($summary->areas[0]['failed']);
    }

    /**
     * Ein kurzer Aussetzer darf die Kachel nicht eine Stunde lang auf "nicht
     * ermittelbar" festhalten: Eine Messung mit Fehler kommt nicht in den Cache.
     */
    public function testMeasurementWithAFailedAreaIsNotCached(): void
    {
        $this->service([$this->provider('a', 5), $this->provider('b', 1, true)])->collect();

        $this->assertFileDoesNotExist($this->cache);

        $this->calls = 0;
        $this->service([$this->provider('a', 5), $this->provider('b', 1)])->summary();
        $this->assertSame(2, $this->calls, 'Die nächste Kachel misst neu.');
    }

    public function testUnwritableCacheIsLoggedButResultReturned(): void
    {
        $blocker = sys_get_temp_dir() . '/storage-blocker-' . bin2hex(random_bytes(6));
        file_put_contents($blocker, 'x');
        $this->cache = $blocker . '/sub/summary.json';

        try {
            $report = $this->service([$this->provider('a', 7)])->collect();
        } finally {
            unlink($blocker);
        }

        $this->assertSame(7, $report->totalBytes());
        $this->assertContains('storage.summary_cache_write_failed', $this->events());
    }
}
