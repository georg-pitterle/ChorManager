<?php

declare(strict_types=1);

namespace Tests\Unit\Logging;

use App\Logging\FatalErrorLogger;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

/**
 * Ein Fatal Error landet als JSON-Zeile im Log.
 *
 * Anlass: Das Repertoire lief im Live-Betrieb ins Speicherlimit und antwortete
 * mit einem leeren 500 - im Log stand nichts. Ein Fatal Error ist keine
 * Ausnahme; Slims ErrorMiddleware sieht ihn nie, und PHP-FPM schreibt ihn,
 * wenn überhaupt, als Klartext neben die strukturierten Zeilen.
 */
final class FatalErrorLoggerTest extends TestCase
{
    private string $memoryLimit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->memoryLimit = (string) ini_get('memory_limit');
    }

    protected function tearDown(): void
    {
        ini_set('memory_limit', $this->memoryLimit);
        parent::tearDown();
    }

    public function testAFatalErrorIsLoggedAsCriticalWithEventAndLocation(): void
    {
        $handler = new TestHandler();

        (new FatalErrorLogger(new Logger('test', [$handler])))->handleShutdown([
            'type' => E_ERROR,
            'message' => 'Allowed memory size of 134217728 bytes exhausted (tried to allocate 41943040 bytes)',
            'file' => '/var/www/html/vendor/illuminate/database/Connection.php',
            'line' => 412,
        ]);

        $records = $handler->getRecords();
        $this->assertCount(1, $records);
        $this->assertSame('CRITICAL', $records[0]->level->getName());
        $this->assertSame('php.fatal_error', $records[0]->context['event']);
        $this->assertSame('E_ERROR', $records[0]->context['error_type']);
        $this->assertStringContainsString('Allowed memory size', $records[0]->context['error_message']);
        $this->assertSame('/var/www/html/vendor/illuminate/database/Connection.php', $records[0]->context['file']);
        $this->assertSame(412, $records[0]->context['line']);
    }

    public function testAnOrdinaryShutdownLogsNothing(): void
    {
        $handler = new TestHandler();

        (new FatalErrorLogger(new Logger('test', [$handler])))->handleShutdown(null);

        $this->assertSame([], $handler->getRecords());
    }

    public function testAWarningLeftBehindIsNoFatalError(): void
    {
        $handler = new TestHandler();

        (new FatalErrorLogger(new Logger('test', [$handler])))->handleShutdown([
            'type' => E_WARNING,
            'message' => 'Undefined array key "x"',
            'file' => '/var/www/html/src/Foo.php',
            'line' => 3,
        ]);

        $this->assertSame([], $handler->getRecords(), 'Eine Warnung hat ihr eigenes Log; sie bricht nichts ab.');
    }

    public function testRunningOutOfMemoryLeavesRoomToWriteTheLogLine(): void
    {
        $tight = memory_get_usage(true) + 1024 * 1024;
        ini_set('memory_limit', (string) $tight);

        (new FatalErrorLogger(new Logger('test', [new TestHandler()])))->handleShutdown([
            'type' => E_ERROR,
            'message' => 'Allowed memory size of ' . $tight . ' bytes exhausted (tried to allocate 41943040 bytes)',
            'file' => '/var/www/html/src/Foo.php',
            'line' => 3,
        ]);

        $this->assertGreaterThanOrEqual(
            memory_get_usage(true) + 8 * 1024 * 1024,
            (int) ini_get('memory_limit'),
            'Am erschöpften Limit scheitert schon das Formatieren der Logzeile.'
        );
    }

    public function testAnUnlimitedMemoryLimitStaysUnlimited(): void
    {
        ini_set('memory_limit', '-1');

        (new FatalErrorLogger(new Logger('test', [new TestHandler()])))->handleShutdown([
            'type' => E_ERROR,
            'message' => 'Allowed memory size of 1 bytes exhausted',
            'file' => '/var/www/html/src/Foo.php',
            'line' => 3,
        ]);

        $this->assertSame('-1', ini_get('memory_limit'));
    }

    public function testAGenerousLimitIsNeverLowered(): void
    {
        $generous = memory_get_usage(true) + 512 * 1024 * 1024;
        ini_set('memory_limit', (string) $generous);

        (new FatalErrorLogger(new Logger('test', [new TestHandler()])))->handleShutdown([
            'type' => E_ERROR,
            'message' => 'Maximum execution time of 30 seconds exceeded',
            'file' => '/var/www/html/src/Foo.php',
            'line' => 3,
        ]);

        $this->assertSame((string) $generous, ini_get('memory_limit'));
    }
}
