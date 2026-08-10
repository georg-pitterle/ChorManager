<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * Guards the production stack against a single failing service taking down the
 * whole chain. A literal fastcgi_pass upstream makes Nginx resolve the app
 * service at startup and abort with [emerg] when it is not up yet, which in turn
 * removes the web container from DNS and makes the reverse proxy fail as well.
 */
class StackResilienceFeatureTest extends TestCase
{
    private function nginxConf(): string
    {
        $content = file_get_contents(dirname(__DIR__, 2) . '/nginx.conf');
        $this->assertIsString($content);

        return $content;
    }

    private function productionCompose(): string
    {
        $content = file_get_contents(dirname(__DIR__, 2) . '/dist/docker-compose.prod.yml');
        $this->assertIsString($content);

        return $content;
    }

    public function testNginxResolvesTheFpmUpstreamAtRuntime(): void
    {
        $nginxConf = $this->nginxConf();

        $this->assertStringContainsString('resolver 127.0.0.11', $nginxConf);
        $this->assertStringContainsString('set $fpm chormanager-fpm:9000;', $nginxConf);
        $this->assertStringContainsString('fastcgi_pass $fpm;', $nginxConf);
    }

    public function testNginxDoesNotUseALiteralFpmUpstream(): void
    {
        $this->assertStringNotContainsString('fastcgi_pass chormanager-fpm:9000;', $this->nginxConf());
    }

    public function testInternalNetworkIsProjectScopedInsteadOfFixedName(): void
    {
        $this->assertStringNotContainsString('name: chormanager-internal-', $this->productionCompose());
    }

    public function testSharedProxyAliasesStayStackScoped(): void
    {
        $compose = $this->productionCompose();

        $this->assertStringContainsString('chormanager-web-${STACK_ID:-prod}', $compose);
        $this->assertStringContainsString('chormanager-webmail-${STACK_ID:-prod}', $compose);
    }

    /**
     * Web must not gate its start on a healthy app. Combined with the runtime
     * resolver it would reintroduce the outage it is meant to prevent: an app
     * that stays unhealthy would keep web from starting at all, instead of web
     * serving 502 until the app recovers.
     */
    public function testWebDoesNotGateItsStartOnAHealthyAppService(): void
    {
        $this->assertDoesNotMatchRegularExpression(
            '/depends_on:\s*\n\s*app:\s*\n\s*condition: service_healthy/',
            $this->productionCompose()
        );
    }
}
