<?php

declare(strict_types=1);

namespace Tests\Feature;

use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Slim\Views\Twig;

/**
 * Guards the navbar against a stale session snapshot in Twig.
 *
 * layout.twig renders the whole topbar behind `{% if session.user_id %}`. The
 * `session` global used to be a by-value copy of $_SESSION taken when the Twig
 * service was constructed. Middleware that runs before AuthMiddleware can pull
 * Twig out of the container (RegistrationReminderMiddleware did), so on the
 * first request after a deploy - session files gone, login restored from the
 * remember-me cookie inside AuthMiddleware - the snapshot was already empty and
 * the page rendered its content without any navigation.
 */
final class TwigSessionGlobalFeatureTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $originalSession = [];

    protected function setUp(): void
    {
        $this->originalSession = $_SESSION ?? [];
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->originalSession;
    }

    private function buildTwig(): Twig
    {
        $containerBuilder = new ContainerBuilder();

        $settings = require dirname(__DIR__, 2) . '/src/Settings.php';
        $settings($containerBuilder);

        $dependencies = require dirname(__DIR__, 2) . '/src/Dependencies.php';
        $dependencies($containerBuilder);

        $container = $containerBuilder->build();

        // Mirror the front controller: Eloquent is booted globally before the view
        // layer is resolved, because the Twig factory reads app settings from the DB.
        $container->get(Capsule::class);

        $view = $container->get(Twig::class);
        $this->assertInstanceOf(Twig::class, $view);

        return $view;
    }

    public function testSessionValuesWrittenAfterTwigConstructionAreVisible(): void
    {
        $view = $this->buildTwig();

        $_SESSION['user_id'] = 4711;
        $_SESSION['user_name'] = 'Testperson';

        $template = $view->getEnvironment()->createTemplate(
            '{% if session.user_id %}nav:{{ session.user_id }}:{{ session.user_name }}{% endif %}'
        );

        $this->assertSame('nav:4711:Testperson', $template->render([]));
    }

    public function testPermissionFlagsWrittenAfterTwigConstructionAreVisible(): void
    {
        $view = $this->buildTwig();

        $_SESSION['can_manage_users'] = true;

        $template = $view->getEnvironment()->createTemplate(
            '{% if session.can_manage_users %}yes{% else %}no{% endif %}'
        );

        $this->assertSame('yes', $template->render([]));
    }

    public function testMissingSessionKeysStayFalsyAndSupportDefault(): void
    {
        $view = $this->buildTwig();

        $template = $view->getEnvironment()->createTemplate(
            '{% if session.user_id %}nav{% else %}anonymous{% endif %}|{{ session.user_name|default("-") }}'
        );

        $this->assertSame('anonymous|-', $template->render([]));
    }
}
