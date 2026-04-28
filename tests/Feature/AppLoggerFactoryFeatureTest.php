<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Logging\AppLoggerFactory;
use PHPUnit\Framework\TestCase;

class AppLoggerFactoryFeatureTest extends TestCase
{
    private string $logFile;

    protected function setUp(): void
    {
        $this->logFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cm_logger_' . uniqid('', true) . '.log';
    }

    protected function tearDown(): void
    {
        if (is_file($this->logFile)) {
            @unlink($this->logFile);
        }
    }

    public function testWritesSingleLineJsonLogWithTimestampAndDefaults(): void
    {
        $logger = AppLoggerFactory::create([
            'channel' => 'chormanager',
            'service' => 'chormanager',
            'environment' => 'test',
            'stream' => $this->logFile,
            'level' => 'INFO',
        ]);

        $logger->info('Queue processed', ['event' => 'mail_queue.process.completed', 'sent' => 3]);

        $lines = file($this->logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertIsArray($lines);
        $this->assertCount(1, $lines);

        $payload = json_decode($lines[0], true);
        $this->assertIsArray($payload);
        $this->assertSame('Queue processed', $payload['message'] ?? null);
        $this->assertSame('INFO', $payload['level_name'] ?? null);
        $this->assertSame('mail_queue.process.completed', $payload['context']['event'] ?? null);
        $this->assertSame(3, $payload['context']['sent'] ?? null);
        $this->assertArrayHasKey('datetime', $payload);
        $this->assertSame('chormanager', $payload['extra']['service'] ?? null);
        $this->assertSame('test', $payload['extra']['env'] ?? null);
    }

    public function testInvalidLogLevelFallsBackToInfoLevel(): void
    {
        $logger = AppLoggerFactory::create([
            'stream' => $this->logFile,
            'level' => 'NOT_A_LEVEL',
        ]);

        $logger->debug('debug line should be filtered', ['event' => 'debug.filtered']);
        $logger->info('info line should be written', ['event' => 'info.written']);

        $lines = file($this->logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertIsArray($lines);
        $this->assertCount(1, $lines);

        $payload = json_decode($lines[0], true);
        $this->assertIsArray($payload);
        $this->assertSame('info line should be written', $payload['message'] ?? null);
    }
}
