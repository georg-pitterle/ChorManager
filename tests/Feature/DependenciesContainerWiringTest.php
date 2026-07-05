<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Middleware\MailBadgeRefreshMiddleware;
use DI\ContainerBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Guards against DI container misregistration: Dependencies.php must resolve
 * every ::class key it registers to the same fully-qualified name the rest
 * of the app (e.g. Middleware.php) requests it under. A missing `use` import
 * in Dependencies.php previously made MailBadgeRefreshMiddleware::class
 * resolve to the bare global-namespace string there, so the container had no
 * definition under the real App\Middleware\MailBadgeRefreshMiddleware key and
 * fell back to broken autowiring on every request.
 */
final class DependenciesContainerWiringTest extends TestCase
{
    public function testMailBadgeRefreshMiddlewareResolvesFromContainer(): void
    {
        $containerBuilder = new ContainerBuilder();

        $settings = require dirname(__DIR__, 2) . '/src/Settings.php';
        $settings($containerBuilder);

        $dependencies = require dirname(__DIR__, 2) . '/src/Dependencies.php';
        $dependencies($containerBuilder);

        $container = $containerBuilder->build();

        $middleware = $container->get(MailBadgeRefreshMiddleware::class);

        $this->assertInstanceOf(MailBadgeRefreshMiddleware::class, $middleware);
    }
}
