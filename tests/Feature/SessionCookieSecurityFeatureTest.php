<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Util\SessionConfig;
use PHPUnit\Framework\TestCase;

/**
 * Der Einstiegspunkt las die Umgebung frueher ueber getenv('APP_ENV'). Dotenv::safeLoad() fuellt
 * aber nur $_ENV und $_SERVER, sodass ein ausschliesslich in der .env gesetztes APP_ENV=production
 * unsichtbar blieb und das Session-Cookie ohne Secure-Flag ausgeliefert wurde.
 */
final class SessionCookieSecurityFeatureTest extends TestCase
{
    private ?string $previousAppEnv = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousAppEnv = $_ENV['APP_ENV'] ?? null;
    }

    protected function tearDown(): void
    {
        unset($_ENV['TRUSTED_PROXIES'], $_SERVER['TRUSTED_PROXIES']);

        if ($this->previousAppEnv === null) {
            unset($_ENV['APP_ENV']);
        } else {
            $_ENV['APP_ENV'] = $this->previousAppEnv;
        }

        parent::tearDown();
    }

    public function testProductionFromDotenvForcesSecureCookie(): void
    {
        $_ENV['APP_ENV'] = 'production';

        $this->assertTrue(SessionConfig::shouldUseSecureCookie([]));
    }

    public function testPlainHttpInDevelopmentKeepsCookieWithoutSecureFlag(): void
    {
        $_ENV['APP_ENV'] = 'development';

        $this->assertFalse(SessionConfig::shouldUseSecureCookie(['HTTPS' => 'off']));
    }

    public function testDirectTlsConnectionForcesSecureCookie(): void
    {
        $_ENV['APP_ENV'] = 'development';

        $this->assertTrue(SessionConfig::shouldUseSecureCookie(['HTTPS' => 'on']));
    }

    public function testForwardedProtoCountsOnlyBehindATrustedProxy(): void
    {
        $_ENV['APP_ENV'] = 'development';
        $_ENV['TRUSTED_PROXIES'] = '10.0.0.10';

        $this->assertTrue(SessionConfig::shouldUseSecureCookie([
            'REMOTE_ADDR' => '10.0.0.10',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]));
    }

    public function testForwardedProtoFromUntrustedClientIsIgnored(): void
    {
        $_ENV['APP_ENV'] = 'development';
        $_ENV['TRUSTED_PROXIES'] = '10.0.0.10';

        $this->assertFalse(SessionConfig::shouldUseSecureCookie([
            'REMOTE_ADDR' => '203.0.113.10',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]));
    }

    public function testBootstrapUsesTheSharedHelper(): void
    {
        $bootstrap = file_get_contents(dirname(__DIR__, 2) . '/public/index.php');

        $this->assertIsString($bootstrap);
        $this->assertStringContainsString('SessionConfig::shouldUseSecureCookie($_SERVER)', $bootstrap);
        $this->assertStringNotContainsString("getenv('APP_ENV')", $bootstrap);
    }
}
