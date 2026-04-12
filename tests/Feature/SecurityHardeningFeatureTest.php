<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class SecurityHardeningFeatureTest extends TestCase
{
    public function testLogoutRouteIsPostOnlyInRouteDefinition(): void
    {
        $routes = file_get_contents(dirname(__DIR__) . '/../src/Routes.php');

        $this->assertIsString($routes);
        $this->assertStringContainsString('$app->post(\'/logout\'', $routes);
        $this->assertStringNotContainsString('$app->get(\'/logout\'', $routes);
    }

    public function testNewsletterControllerContainsAccessChecksForSensitiveEndpoints(): void
    {
        $content = file_get_contents(dirname(__DIR__) . '/../src/Controllers/NewsletterController.php');

        $this->assertIsString($content);
        $this->assertStringContainsString('private function canAccessNewsletterById', $content);
        $this->assertStringContainsString('if (!$this->canAccessNewsletterById($id, $userId))', $content);
        $this->assertStringContainsString('if (!$this->canAccessTemplateContext($template->project_id', $content);
    }

    public function testPasswordResetBuildsLinkFromTrustedAppUrl(): void
    {
        $content = file_get_contents(dirname(__DIR__) . '/../src/Controllers/PasswordResetController.php');

        $this->assertIsString($content);
        $this->assertStringContainsString('buildTrustedAppUrl()', $content);
        $this->assertStringContainsString("EnvHelper::read('APP_URL'", $content);
        $this->assertStringNotContainsString('$request->getUri()->getHost()', $content);
    }

    public function testFileNameSanitizationForContentDispositionExists(): void
    {
        $finance = file_get_contents(dirname(__DIR__) . '/../src/Controllers/FinanceController.php');
        $settings = file_get_contents(dirname(__DIR__) . '/../src/Controllers/AppSettingController.php');

        $this->assertIsString($finance);
        $this->assertIsString($settings);

        $this->assertStringContainsString('private static function normalizeFileName', $finance);
        $this->assertStringContainsString('filename*=', $finance);

        $this->assertStringContainsString('private static function normalizeFileName', $settings);
        $this->assertStringContainsString('filename*=', $settings);
    }

    public function testCsrfTokenIsExposedInLayoutAndInjectedByJs(): void
    {
        $layout = file_get_contents(dirname(__DIR__) . '/../templates/layout.twig');
        $layoutModal = file_get_contents(dirname(__DIR__) . '/../templates/layout_modal.twig');
        $commonJs = file_get_contents(dirname(__DIR__) . '/../public/js/common.js');
        $login = file_get_contents(dirname(__DIR__) . '/../templates/auth/login.twig');
        $forgot = file_get_contents(dirname(__DIR__) . '/../templates/auth/forgot_password.twig');
        $reset = file_get_contents(dirname(__DIR__) . '/../templates/auth/reset_password.twig');
        $setup = file_get_contents(dirname(__DIR__) . '/../templates/auth/setup.twig');
        $userMenu = file_get_contents(dirname(__DIR__) . '/../templates/partials/navigation/user_menu.twig');

        $this->assertIsString($layout);
        $this->assertIsString($layoutModal);
        $this->assertIsString($commonJs);
        $this->assertIsString($login);
        $this->assertIsString($forgot);
        $this->assertIsString($reset);
        $this->assertIsString($setup);
        $this->assertIsString($userMenu);

        $this->assertStringContainsString('meta name="csrf-token"', $layout);
        $this->assertStringContainsString('meta name="csrf-token"', $layoutModal);
        $this->assertStringContainsString('input type="hidden" name="_csrf" value="{{ csrf_token }}"', $login);
        $this->assertStringContainsString('input type="hidden" name="_csrf" value="{{ csrf_token }}"', $setup);
        $this->assertStringContainsString('input type="hidden" name="_csrf" value="{{ csrf_token }}"', $forgot);
        $this->assertStringContainsString('input type="hidden" name="_csrf" value="{{ csrf_token }}"', $reset);
        $this->assertStringContainsString('input type="hidden" name="_csrf" value="{{ csrf_token }}"', $userMenu);
        $this->assertStringContainsString("meta[name=\"csrf-token\"]", $commonJs);
        $this->assertStringContainsString("form[method=\"post\"]", $commonJs);
    }
}
