<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Util\AppUrlResolver;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

class AppUrlResolverFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        $this->clearEnv('APP_URL');
        $this->clearEnv('DDEV_PRIMARY_URL');
        $this->clearEnv('DDEV_PRIMARY_URL_WITHOUT_PORT');
        $this->clearEnv('TRUSTED_PROXIES');
    }

    protected function tearDown(): void
    {
        $this->clearEnv('APP_URL');
        $this->clearEnv('DDEV_PRIMARY_URL');
        $this->clearEnv('DDEV_PRIMARY_URL_WITHOUT_PORT');
        $this->clearEnv('TRUSTED_PROXIES');
    }

    public function testPrefersConfiguredAppUrlWhenValid(): void
    {
        $_ENV['APP_URL'] = 'https://chor.example.org:443/base/';

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'http://internal.local/reset-password', ['REMOTE_ADDR' => '127.0.0.1']);

        $this->assertSame('https://chor.example.org/base', AppUrlResolver::resolveBaseUrl($request));
    }

    public function testPrefersDdevPrimaryUrlWhenAppUrlIsUnset(): void
    {
        $_ENV['DDEV_PRIMARY_URL'] = 'https://chormanager.ddev.site:443';

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'http://web/reset-password', ['REMOTE_ADDR' => '172.18.0.10']);

        $this->assertSame('https://chormanager.ddev.site', AppUrlResolver::resolveBaseUrl($request));
    }

    public function testPrefersDdevPrimaryUrlWithoutPortWhenAvailable(): void
    {
        $_ENV['DDEV_PRIMARY_URL'] = 'https://chormanager.ddev.site:443';
        $_ENV['DDEV_PRIMARY_URL_WITHOUT_PORT'] = 'https://chormanager.ddev.site';

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'http://web/reset-password', ['REMOTE_ADDR' => '172.18.0.10']);

        $this->assertSame('https://chormanager.ddev.site', AppUrlResolver::resolveBaseUrl($request));
    }

    public function testUsesRequestUriWhenProxyIsNotTrusted(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'http://internal.local/reset-password', ['REMOTE_ADDR' => '198.51.100.10'])
            ->withHeader('X-Forwarded-Proto', 'https')
            ->withHeader('X-Forwarded-Host', 'public.example.org')
            ->withHeader('X-Forwarded-Port', '443');

        $this->assertSame('http://internal.local', AppUrlResolver::resolveBaseUrl($request));
    }

    public function testUsesForwardedHeadersWhenProxyIsTrusted(): void
    {
        $_ENV['TRUSTED_PROXIES'] = '10.0.0.10';

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'http://web/reset-password', ['REMOTE_ADDR' => '10.0.0.10'])
            ->withHeader('X-Forwarded-Proto', 'https')
            ->withHeader('X-Forwarded-Host', 'public.example.org')
            ->withHeader('X-Forwarded-Port', '8443');

        $this->assertSame('https://public.example.org:8443', AppUrlResolver::resolveBaseUrl($request));
    }

    public function testUsesForwardedHeaderFallbackWhenXHeadersMissing(): void
    {
        $_ENV['TRUSTED_PROXIES'] = '10.0.0.10';

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'http://web/reset-password', ['REMOTE_ADDR' => '10.0.0.10'])
            ->withHeader('Forwarded', 'for=198.51.100.20;proto=https;host=public.example.org:443');

        $this->assertSame('https://public.example.org', AppUrlResolver::resolveBaseUrl($request));
    }

    private function clearEnv(string $name): void
    {
        unset($_ENV[$name], $_SERVER[$name]);
        putenv($name);
    }
}
