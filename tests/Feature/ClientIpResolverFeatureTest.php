<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Util\ClientIpResolver;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

class ClientIpResolverFeatureTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_ENV['TRUSTED_PROXIES'], $_SERVER['TRUSTED_PROXIES']);
    }

    public function testUsesRemoteAddressWhenNoTrustedProxyIsConfigured(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/login', ['REMOTE_ADDR' => '10.0.0.10'])
            ->withHeader('X-Forwarded-For', '203.0.113.10');

        $this->assertSame('10.0.0.10', ClientIpResolver::resolve($request));
    }

    public function testUsesForwardedClientIpForTrustedProxy(): void
    {
        $_ENV['TRUSTED_PROXIES'] = '10.0.0.10';

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/login', ['REMOTE_ADDR' => '10.0.0.10'])
            ->withHeader('X-Forwarded-For', '203.0.113.10, 10.0.0.10');

        $this->assertSame('203.0.113.10', ClientIpResolver::resolve($request));
    }

    public function testSupportsTrustedProxyCidrs(): void
    {
        $_SERVER['TRUSTED_PROXIES'] = '10.0.0.0/24';

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/login', ['REMOTE_ADDR' => '10.0.0.25'])
            ->withHeader('X-Forwarded-For', '198.51.100.7');

        $this->assertSame('198.51.100.7', ClientIpResolver::resolve($request));
    }

    /**
     * Der Client kann beliebig viele X-Forwarded-For-Eintraege selbst vorgeben. Nur der
     * rechteste, nicht vertrauenswuerdige Eintrag stammt vom eigenen Proxy - alles links
     * davon ist faelschbar und darf das Login-Rate-Limit nicht umgehen koennen.
     */
    public function testIgnoresSpoofedForwardedEntriesLeftOfTheProxyChain(): void
    {
        $_ENV['TRUSTED_PROXIES'] = '10.0.0.10';

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/login', ['REMOTE_ADDR' => '10.0.0.10'])
            ->withHeader('X-Forwarded-For', '1.2.3.4, 203.0.113.10');

        $this->assertSame('203.0.113.10', ClientIpResolver::resolve($request));
    }

    public function testSkipsTrustedProxiesInsideTheForwardedChain(): void
    {
        $_ENV['TRUSTED_PROXIES'] = '10.0.0.0/24';

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/login', ['REMOTE_ADDR' => '10.0.0.10'])
            ->withHeader('X-Forwarded-For', '203.0.113.10, 10.0.0.11, 10.0.0.12');

        $this->assertSame('203.0.113.10', ClientIpResolver::resolve($request));
    }

    public function testFallsBackToRemoteAddressWhenAllForwardedEntriesAreTrusted(): void
    {
        $_ENV['TRUSTED_PROXIES'] = '10.0.0.0/24';

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/login', ['REMOTE_ADDR' => '10.0.0.10'])
            ->withHeader('X-Forwarded-For', '10.0.0.11, 10.0.0.12');

        $this->assertSame('10.0.0.10', ClientIpResolver::resolve($request));
    }

    public function testFallsBackToRemoteAddressWhenForwardedChainIsMalformed(): void
    {
        $_ENV['TRUSTED_PROXIES'] = '10.0.0.10';

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/login', ['REMOTE_ADDR' => '10.0.0.10'])
            ->withHeader('X-Forwarded-For', '203.0.113.10, not-an-ip');

        $this->assertSame('10.0.0.10', ClientIpResolver::resolve($request));
    }
}
