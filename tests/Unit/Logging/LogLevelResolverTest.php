<?php

declare(strict_types=1);

namespace Tests\Unit\Logging;

use App\Logging\LogLevelResolver;
use Monolog\Level;
use PHPUnit\Framework\TestCase;

final class LogLevelResolverTest extends TestCase
{
    public function testReturnsLevelFromSettings(): void
    {
        $resolver = new LogLevelResolver(static fn (): array => ['log_level' => 'DEBUG']);

        $this->assertSame(Level::Debug, $resolver->level());
    }

    public function testFallsBackWhenReaderThrows(): void
    {
        $resolver = new LogLevelResolver(
            static function (): array {
                throw new \RuntimeException('database unavailable');
            },
            'WARNING'
        );

        $this->assertSame(Level::Warning, $resolver->level());
    }

    public function testFallsBackOnUnknownLevelName(): void
    {
        $resolver = new LogLevelResolver(static fn (): array => ['log_level' => 'TRACE'], 'NOTICE');

        $this->assertSame(Level::Notice, $resolver->level());
    }

    public function testReadsSettingsOnlyOnce(): void
    {
        $calls = 0;
        $resolver = new LogLevelResolver(static function () use (&$calls): array {
            $calls++;

            return ['log_level' => 'ERROR'];
        });

        $resolver->level();
        $resolver->level();
        $resolver->isDbWriteLoggingEnabled();

        $this->assertSame(1, $calls);
    }

    public function testDoesNotTouchReaderOnConstruction(): void
    {
        $calls = 0;
        new LogLevelResolver(static function () use (&$calls): array {
            $calls++;

            return [];
        });

        $this->assertSame(0, $calls);
    }

    public function testDbWriteLoggingIsOffByDefault(): void
    {
        $resolver = new LogLevelResolver(static fn (): array => []);

        $this->assertFalse($resolver->isDbWriteLoggingEnabled());
    }

    public function testDbWriteLoggingReadsFlag(): void
    {
        $resolver = new LogLevelResolver(static fn (): array => ['log_db_writes' => '1']);

        $this->assertTrue($resolver->isDbWriteLoggingEnabled());
    }
}
