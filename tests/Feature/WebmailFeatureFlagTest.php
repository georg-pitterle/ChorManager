<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\UserMailAccount;
use PHPUnit\Framework\TestCase;
use Slim\Factory\AppFactory;

final class WebmailFeatureFlagTest extends TestCase
{
    public function testUserMailAccountHasExternalWebmailUrlFillable(): void
    {
        $this->assertContains('external_webmail_url', (new UserMailAccount())->getFillable());
    }

    public function testMigrationForExternalWebmailUrlExists(): void
    {
        $migration = dirname(__DIR__, 2)
            . '/db/migrations/20260704200000_add_external_webmail_url_to_user_mail_accounts.php';
        $this->assertFileExists($migration);

        $content = file_get_contents($migration);
        $this->assertIsString($content);
        $this->assertStringContainsString("'external_webmail_url'", $content);
        $this->assertStringContainsString("'null' => true", $content);
    }

    public function testSettingsExposeWebmailFeatureFlagWithFalseDefault(): void
    {
        $content = file_get_contents(dirname(__DIR__) . '/../src/Settings.php');

        $this->assertIsString($content);
        $this->assertMatchesRegularExpression(
            "/'webmail'\\s*=>\\s*EnvHelper::read\\('FEATURE_WEBMAIL', 'false'\\) === 'true'/",
            $content
        );
    }

    public function testWebmailRouteIsRegisteredOnlyInsideFeatureGate(): void
    {
        $content = file_get_contents(dirname(__DIR__) . '/../src/Routes.php');
        $this->assertIsString($content);

        $gatePos = strpos($content, "if (\$settings['modules']['webmail'] ?? false) {");
        $routePos = strpos($content, "'/profile/webmail/start'");

        $this->assertNotFalse($gatePos, 'Webmail feature gate missing in Routes.php.');
        $this->assertNotFalse($routePos, 'Webmail route missing in Routes.php.');
        $this->assertGreaterThan($gatePos, $routePos, 'Webmail route must be inside the feature gate.');
    }

    public function testWebmailRouteRegistrationRespectsFeatureFlag(): void
    {
        $this->assertFalse($this->webmailStartRouteIsRegistered(false));
        $this->assertTrue($this->webmailStartRouteIsRegistered(true));
    }

    /**
     * Builds a minimal real Slim App (no DB/session/middleware) and registers the actual
     * src/Routes.php route definitions against it, then inspects the real RouteCollector
     * to confirm the feature-gated route is only present when the flag is enabled.
     *
     * Note: AppFactory::setContainer() mutates Slim's process-global static factory state.
     * No other test in this suite currently calls AppFactory::create(); if a future test
     * does, make sure it also sets/resets the container to avoid cross-test leakage.
     */
    private function webmailStartRouteIsRegistered(bool $webmailEnabled): bool
    {
        $settings = ['modules' => ['webmail' => $webmailEnabled]];

        $container = new class ($settings) implements \Psr\Container\ContainerInterface {
            public function __construct(private array $settings)
            {
            }

            public function get(string $id): mixed
            {
                return $id === 'settings' ? $this->settings : null;
            }

            public function has(string $id): bool
            {
                return $id === 'settings';
            }
        };

        AppFactory::setContainer($container);
        $app = AppFactory::create();

        $routes = require dirname(__DIR__, 2) . '/src/Routes.php';
        $routes($app);

        foreach ($app->getRouteCollector()->getRoutes() as $route) {
            if ($route->getPattern() === '/profile/webmail/start' && in_array('POST', $route->getMethods(), true)) {
                return true;
            }
        }

        return false;
    }

    public function testProfileTemplateGatesExternalUrlFieldAndSsoButton(): void
    {
        $template = file_get_contents(dirname(__DIR__) . '/../templates/profile/index.twig');
        $this->assertIsString($template);

        // URL-Feld nur bei abgeschaltetem Flag.
        $this->assertStringContainsString('{% if not settings.modules.webmail %}', $template);
        $this->assertStringContainsString('name="external_webmail_url"', $template);

        // SSO-Button nur bei aktivem Flag.
        $this->assertStringContainsString(
            '{% if settings.modules.webmail and webmail_available %}',
            $template
        );

        // Externer Link-Button bei Flag aus + gesetzter URL.
        $this->assertStringContainsString('{% set _external_url =', $template);
        $this->assertStringContainsString('rel="noopener noreferrer"', $template);
    }

    public function testDependenciesExposeExternalWebmailUrlGlobal(): void
    {
        $content = file_get_contents(dirname(__DIR__) . '/../src/Dependencies.php');
        $this->assertIsString($content);
        $this->assertStringContainsString("addGlobal('mail_external_webmail_url'", $content);
    }

    public function testUserMenuBadgeCoversAllThreeWebmailCases(): void
    {
        $template = file_get_contents(dirname(__DIR__) . '/../templates/partials/navigation/user_menu.twig');
        $this->assertIsString($template);

        // Fall 1: Flag an -> SSO-Formular.
        $this->assertStringContainsString('{% if settings.modules.webmail %}', $template);
        $this->assertStringContainsString('action="/profile/webmail/start"', $template);

        // Fall 2: Flag aus + externe URL -> Link.
        $this->assertStringContainsString('{% elseif mail_external_webmail_url %}', $template);
        $this->assertStringContainsString('href="{{ mail_external_webmail_url }}"', $template);
        $this->assertStringContainsString('rel="noopener noreferrer"', $template);

        // Fall 3: Flag aus, keine URL -> reiner Indikator ohne Form/Link.
        $this->assertStringContainsString('title="Ungelesene Nachrichten"', $template);
    }

    public function testEnvExamplesDocumentFeatureWebmail(): void
    {
        $root = dirname(__DIR__, 2);

        $devEnv = file_get_contents($root . '/.env.example');
        $this->assertIsString($devEnv);
        $this->assertStringContainsString('FEATURE_WEBMAIL=', $devEnv);

        $prodEnv = file_get_contents($root . '/dist/.env.example');
        $this->assertIsString($prodEnv);
        $this->assertStringContainsString('FEATURE_WEBMAIL=', $prodEnv);

        $compose = file_get_contents($root . '/dist/docker-compose.prod.yml');
        $this->assertIsString($compose);
        $this->assertStringContainsString('FEATURE_WEBMAIL: ${FEATURE_WEBMAIL:-false}', $compose);
    }
}
