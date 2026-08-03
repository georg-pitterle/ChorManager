<?php

declare(strict_types=1);

namespace Tests\Unit\Logging;

use App\Logging\LogLevelResolver;
use App\Logging\RuntimeLevelHandler;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

final class RuntimeLevelHandlerTest extends TestCase
{
    public function testPassesRecordAtOrAboveConfiguredLevel(): void
    {
        $inner = new TestHandler(Level::Debug);
        $handler = new RuntimeLevelHandler($inner, new LogLevelResolver(
            static fn (): array => ['log_level' => 'INFO']
        ));

        $handler->handle($this->record(Level::Error, 'boom'));

        $this->assertTrue($inner->hasErrorThatContains('boom'));
    }

    public function testDropsRecordBelowConfiguredLevel(): void
    {
        $inner = new TestHandler(Level::Debug);
        $handler = new RuntimeLevelHandler($inner, new LogLevelResolver(
            static fn (): array => ['log_level' => 'INFO']
        ));

        $handler->handle($this->record(Level::Debug, 'noise'));

        $this->assertFalse($inner->hasDebugRecords());
    }

    /**
     * The branch's headline claim: a level change takes effect at runtime, on the
     * same handler/logger instance, without a restart. The previous version of
     * this test built a fresh handler and a fresh resolver whose closure returned
     * a constant level - indistinguishable from testPassesRecordAtOrAboveConfiguredLevel
     * and unable to fail even if the gate stopped re-consulting the resolver per
     * record. This version mutates the resolver's underlying source and calls
     * LogLevelResolver::reset() between two handle() calls on the same handler,
     * proving the level is actually re-read, not just read once at construction.
     */
    public function testFollowsResolverWhenLevelIsLowered(): void
    {
        $configuredLevel = 'ERROR';
        $inner = new TestHandler(Level::Debug);
        $resolver = new LogLevelResolver(static function () use (&$configuredLevel): array {
            return ['log_level' => $configuredLevel];
        });
        $handler = new RuntimeLevelHandler($inner, $resolver);

        $handler->handle($this->record(Level::Debug, 'before-lowering'));
        $this->assertFalse($inner->hasDebugThatContains('before-lowering'));

        $configuredLevel = 'DEBUG';
        $resolver->reset();

        $handler->handle($this->record(Level::Debug, 'after-lowering'));
        $this->assertTrue($inner->hasDebugThatContains('after-lowering'));
    }

    private function record(Level $level, string $message): LogRecord
    {
        return new LogRecord(new \DateTimeImmutable(), 'chormanager', $level, $message);
    }
}
