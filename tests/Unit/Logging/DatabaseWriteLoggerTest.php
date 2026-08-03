<?php

declare(strict_types=1);

namespace Tests\Unit\Logging;

use App\Logging\DatabaseWriteLogger;
use App\Logging\LogLevelResolver;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

final class DatabaseWriteLoggerTest extends TestCase
{
    public function testLogsInsertWhenEnabled(): void
    {
        [$logger, $handler] = $this->logger();
        $writeLogger = new DatabaseWriteLogger($logger, $this->resolver(true));

        $writeLogger->handle('insert into `users` (`email`) values (?)', 1.5);

        $this->assertTrue($handler->hasDebugRecords());
        $record = $handler->getRecords()[0];
        $this->assertSame('db.write', $record->context['event']);
        $this->assertSame('users', $record->context['table']);
    }

    public function testIgnoresSelect(): void
    {
        [$logger, $handler] = $this->logger();
        $writeLogger = new DatabaseWriteLogger($logger, $this->resolver(true));

        $writeLogger->handle('select * from `users` where `id` = ?', 0.4);

        $this->assertSame([], $handler->getRecords());
    }

    public function testIgnoresEverythingWhenDisabled(): void
    {
        [$logger, $handler] = $this->logger();
        $writeLogger = new DatabaseWriteLogger($logger, $this->resolver(false));

        $writeLogger->handle('insert into `users` (`email`) values (?)', 1.5);

        $this->assertSame([], $handler->getRecords());
    }

    public function testIgnoresAppSettingsToAvoidRecursion(): void
    {
        [$logger, $handler] = $this->logger();
        $writeLogger = new DatabaseWriteLogger($logger, $this->resolver(true));

        $writeLogger->handle('update `app_settings` set `setting_value` = ?', 0.9);

        $this->assertSame([], $handler->getRecords());
    }

    public function testNeverLogsBindings(): void
    {
        [$logger, $handler] = $this->logger();
        $writeLogger = new DatabaseWriteLogger($logger, $this->resolver(true));

        $writeLogger->handle('insert into `users` (`password`) values (?)', 1.0);

        $encoded = json_encode($handler->getRecords()[0]->context);
        $this->assertStringNotContainsString('bindings', (string) $encoded);
    }

    public function testSkipsWhenConfiguredLevelIsAboveDebugEvenWhenEnabled(): void
    {
        [$logger, $handler] = $this->logger();
        $writeLogger = new DatabaseWriteLogger($logger, $this->resolver(true, 'ERROR'));

        $writeLogger->handle('insert into `users` (`email`) values (?)', 1.5);

        $this->assertSame([], $handler->getRecords());
    }

    private function resolver(bool $enabled, string $level = 'DEBUG'): LogLevelResolver
    {
        return new LogLevelResolver(static fn (): array => [
            'log_db_writes' => $enabled ? '1' : '0',
            'log_level' => $level,
        ]);
    }

    /**
     * @return array{0: Logger, 1: TestHandler}
     */
    private function logger(): array
    {
        $handler = new TestHandler();
        $logger = new Logger('test');
        $logger->pushHandler($handler);

        return [$logger, $handler];
    }
}
