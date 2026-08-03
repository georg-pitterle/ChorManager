<?php

declare(strict_types=1);

namespace Tests\Unit\Logging;

use App\Logging\RequestContext;
use App\Logging\RequestContextProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

final class RequestContextProcessorTest extends TestCase
{
    public function testAddsContextToExtra(): void
    {
        $context = new RequestContext();
        $context->assign([
            'request_id' => 'abc123',
            'method' => 'POST',
            'path' => '/login',
            'ip' => '203.0.113.7',
        ]);
        $context->setUserId(42);

        $processor = new RequestContextProcessor($context);
        $record = $processor($this->record());

        $this->assertSame('abc123', $record->extra['request_id']);
        $this->assertSame('POST', $record->extra['method']);
        $this->assertSame('/login', $record->extra['path']);
        $this->assertSame('203.0.113.7', $record->extra['ip']);
        $this->assertSame(42, $record->extra['user_id']);
    }

    public function testLeavesRecordUntouchedWhenContextIsEmpty(): void
    {
        $processor = new RequestContextProcessor(new RequestContext());
        $record = $processor($this->record());

        $this->assertSame([], $record->extra);
    }

    private function record(): LogRecord
    {
        return new LogRecord(new \DateTimeImmutable(), 'chormanager', Level::Info, 'test');
    }
}
