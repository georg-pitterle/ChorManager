<?php

declare(strict_types=1);

namespace Tests\Unit\Util;

use App\Util\BlockedHostException;
use App\Util\OutboundConnectionGuard;
use PHPUnit\Framework\TestCase;

final class OutboundConnectionGuardTest extends TestCase
{
    private const ENV_KEY = 'MAIL_ALLOW_PRIVATE_HOSTS';

    private ?string $originalEnvValue = null;
    private bool $hadEnvValue = false;

    protected function setUp(): void
    {
        $this->hadEnvValue = array_key_exists(self::ENV_KEY, $_ENV);
        $this->originalEnvValue = $_ENV[self::ENV_KEY] ?? null;
        $this->clearEnv();
    }

    protected function tearDown(): void
    {
        if ($this->hadEnvValue) {
            $_ENV[self::ENV_KEY] = $this->originalEnvValue;
            $_SERVER[self::ENV_KEY] = $this->originalEnvValue;
            putenv(self::ENV_KEY . '=' . $this->originalEnvValue);
        } else {
            $this->clearEnv();
        }
    }

    private function clearEnv(): void
    {
        unset($_ENV[self::ENV_KEY], $_SERVER[self::ENV_KEY]);
        putenv(self::ENV_KEY);
    }

    private function allowPrivate(): void
    {
        $_ENV[self::ENV_KEY] = '1';
        $_SERVER[self::ENV_KEY] = '1';
        putenv(self::ENV_KEY . '=1');
    }

    public function testPublicLiteralIpv4IsReturnedUnchanged(): void
    {
        $this->assertSame('8.8.8.8', OutboundConnectionGuard::resolvePublicIp('8.8.8.8'));
    }

    public function testPublicLiteralIpv6IsReturnedNormalised(): void
    {
        $this->assertSame('2001:4860:4860::8888', OutboundConnectionGuard::resolvePublicIp('[2001:4860:4860::8888]'));
    }

    /**
     * @dataProvider blockedLiteralProvider
     */
    public function testPrivateAndReservedLiteralsAreBlocked(string $host): void
    {
        $this->expectException(BlockedHostException::class);
        OutboundConnectionGuard::resolvePublicIp($host);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function blockedLiteralProvider(): array
    {
        return [
            'ipv4 loopback' => ['127.0.0.1'],
            'ipv4 private 10/8' => ['10.0.0.1'],
            'ipv4 private 172.16/12' => ['172.16.5.4'],
            'ipv4 private 192.168/16' => ['192.168.1.1'],
            'ipv4 link-local metadata' => ['169.254.169.254'],
            'ipv4 zero' => ['0.0.0.0'],
            'ipv6 loopback' => ['::1'],
            'ipv6 link-local' => ['fe80::1'],
            'ipv6 unique-local' => ['fc00::1'],
            'ipv6 unique-local fd' => ['fd12:3456:789a::1'],
            'ipv4-mapped loopback' => ['::ffff:127.0.0.1'],
            'ipv4-mapped private' => ['::ffff:10.0.0.1'],
        ];
    }

    public function testEmptyHostIsBlocked(): void
    {
        $this->expectException(BlockedHostException::class);
        OutboundConnectionGuard::resolvePublicIp('   ');
    }

    public function testLocalhostResolvesToBlockedLoopback(): void
    {
        // localhost is present in every hosts file and resolves to a loopback
        // address; the guard must reject it even when given as a name.
        $this->expectException(BlockedHostException::class);
        OutboundConnectionGuard::resolvePublicIp('localhost');
    }

    public function testEnvOptOutAllowsPrivateLiteral(): void
    {
        $this->allowPrivate();
        $this->assertSame('127.0.0.1', OutboundConnectionGuard::resolvePublicIp('127.0.0.1'));
    }
}
