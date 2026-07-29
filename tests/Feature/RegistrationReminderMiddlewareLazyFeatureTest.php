<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Middleware\RegistrationReminderMiddleware;
use Closure;
use DI\ContainerBuilder;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;
use RuntimeException;
use Slim\Views\Twig;

/**
 * RegistrationReminderMiddleware is a global middleware and therefore runs
 * before the route-level AuthMiddleware. Its reminder service depends on Twig,
 * so resolving it during middleware construction built the view layer before
 * the session was authenticated. The service must be resolved lazily, inside
 * the guarded due-check, so nothing in the pre-auth phase touches Twig.
 */
final class RegistrationReminderMiddlewareLazyFeatureTest extends TestCase
{
    private function containerBuilder(): ContainerBuilder
    {
        $containerBuilder = new ContainerBuilder();

        $settings = require dirname(__DIR__, 2) . '/src/Settings.php';
        $settings($containerBuilder);

        $dependencies = require dirname(__DIR__, 2) . '/src/Dependencies.php';
        $dependencies($containerBuilder);

        return $containerBuilder;
    }

    public function testMiddlewareResolvesFromContainer(): void
    {
        $middleware = $this->containerBuilder()->build()->get(RegistrationReminderMiddleware::class);

        $this->assertInstanceOf(RegistrationReminderMiddleware::class, $middleware);
    }

    public function testResolvingTheMiddlewareDoesNotBuildTwig(): void
    {
        $containerBuilder = $this->containerBuilder();
        $containerBuilder->addDefinitions([
            Twig::class => static function (): Twig {
                throw new RuntimeException('Twig must not be built before authentication.');
            },
        ]);

        $middleware = $containerBuilder->build()->get(RegistrationReminderMiddleware::class);

        $this->assertInstanceOf(RegistrationReminderMiddleware::class, $middleware);
    }

    public function testReminderServiceIsInjectedAsFactory(): void
    {
        $constructor = (new ReflectionClass(RegistrationReminderMiddleware::class))->getConstructor();
        $this->assertNotNull($constructor);

        $firstParameter = $constructor->getParameters()[0] ?? null;
        $this->assertNotNull($firstParameter);

        $type = $firstParameter->getType();
        $this->assertNotNull($type);
        $this->assertSame(Closure::class, (string) $type);
    }

    public function testFactoryIsNotInvokedWhileConstructingTheMiddleware(): void
    {
        $middleware = new RegistrationReminderMiddleware(
            static function (): never {
                throw new RuntimeException('Reminder service resolved too early.');
            },
            new NullLogger()
        );

        $this->assertInstanceOf(RegistrationReminderMiddleware::class, $middleware);
    }
}
