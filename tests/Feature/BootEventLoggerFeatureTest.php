<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Logging\BootEventLogger;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class BootEventLoggerFeatureTest extends TestCase
{
    private string $logFile;

    protected function setUp(): void
    {
        $this->logFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cm_boot_' . uniqid('', true) . '.log';
        putenv('APP_LOG_STREAM=' . $this->logFile);
        unset($_ENV['APP_LOG_STREAM'], $_SERVER['APP_LOG_STREAM']);
    }

    protected function tearDown(): void
    {
        putenv('APP_LOG_STREAM');
        putenv('APP_LOG_LEVEL');
        unset($_ENV['APP_LOG_LEVEL'], $_SERVER['APP_LOG_LEVEL']);

        if (is_file($this->logFile)) {
            @unlink($this->logFile);
        }
    }

    public function testStartEventIsWrittenAsSingleJsonLineWithEventContext(): void
    {
        BootEventLogger::create()->log('app.boot.started');

        $payload = $this->readSingleLine();

        $this->assertSame('app.boot.started', $payload['context']['event'] ?? null);
        $this->assertSame('INFO', $payload['level_name'] ?? null);
        $this->assertSame('chormanager', $payload['channel'] ?? null);
        $this->assertSame('chormanager', $payload['extra']['service'] ?? null);
        $this->assertNotSame('', (string) ($payload['message'] ?? ''));
    }

    public function testMigrationFailureIsLoggedAsCriticalWithExitCode(): void
    {
        BootEventLogger::create()->log('app.boot.migration_failed', 3);

        $payload = $this->readSingleLine();

        $this->assertSame('app.boot.migration_failed', $payload['context']['event'] ?? null);
        $this->assertSame('CRITICAL', $payload['level_name'] ?? null);
        $this->assertSame(3, $payload['context']['exit_code'] ?? null);
    }

    public function testDatabaseWaitTimeoutIsLoggedAsCritical(): void
    {
        BootEventLogger::create()->log('app.boot.db_wait_timeout');

        $payload = $this->readSingleLine();

        $this->assertSame('app.boot.db_wait_timeout', $payload['context']['event'] ?? null);
        $this->assertSame('CRITICAL', $payload['level_name'] ?? null);
    }

    /**
     * Die Boot-Events sind das Signal der Crash-Loop-Alarmierung. Ein restriktives
     * APP_LOG_LEVEL darf sie deshalb nicht wegfiltern.
     */
    public function testBootEventsSurviveARestrictiveLogLevel(): void
    {
        putenv('APP_LOG_LEVEL=ERROR');

        BootEventLogger::create()->log('app.boot.completed');

        $payload = $this->readSingleLine();

        $this->assertSame('app.boot.completed', $payload['context']['event'] ?? null);
        $this->assertSame('INFO', $payload['level_name'] ?? null);
    }

    public function testUnknownEventIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        BootEventLogger::create()->log('app.boot.not_a_real_event');
    }

    public function testEventCatalogueIsExposedForTheEntrypoint(): void
    {
        $events = BootEventLogger::events();

        $this->assertSame(
            [
                'app.boot.started',
                'app.boot.db_wait_timeout',
                'app.boot.migration_failed',
                'app.boot.completed',
            ],
            $events
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function readSingleLine(): array
    {
        $lines = file($this->logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertIsArray($lines);
        $this->assertCount(1, $lines);

        $payload = json_decode($lines[0], true);
        $this->assertIsArray($payload);

        return $payload;
    }
}
