<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\RateLimiterService;
use PHPUnit\Framework\TestCase;

class RateLimiterServiceFeatureTest extends TestCase
{
    private string $storeDir;

    protected function setUp(): void
    {
        $this->storeDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cm_rate_limit_test_' . uniqid('', true);
        @mkdir($this->storeDir, 0755, true);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->storeDir)) {
            return;
        }

        foreach ((array) glob($this->storeDir . DIRECTORY_SEPARATOR . '*') as $file) {
            @unlink($file);
        }
        @rmdir($this->storeDir);
    }

    public function testBlocksWhenAttemptsExceedLimit(): void
    {
        $limiter = new RateLimiterService($this->storeDir);

        $first = $limiter->hit('login:test', 2, 60);
        $second = $limiter->hit('login:test', 2, 60);
        $third = $limiter->hit('login:test', 2, 60);

        $this->assertTrue($first['allowed']);
        $this->assertTrue($second['allowed']);
        $this->assertFalse($third['allowed']);
        $this->assertGreaterThan(0, $third['retry_after']);
    }

    public function testResetClearsCounters(): void
    {
        $limiter = new RateLimiterService($this->storeDir);
        $limiter->hit('forgot:test', 1, 60);
        $blocked = $limiter->hit('forgot:test', 1, 60);
        $this->assertFalse($blocked['allowed']);

        $limiter->reset('forgot:test');
        $afterReset = $limiter->hit('forgot:test', 1, 60);
        $this->assertTrue($afterReset['allowed']);
    }
}
