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
        $this->assertStringContainsString('private function canManageNewsletters', $content);
        $this->assertStringContainsString('private function canAccessReceivedNewsletterById', $content);
        $this->assertStringContainsString(
            '!$this->canManageNewsletters() && !$this->canAccessReceivedNewsletterById($id, $userId)',
            $content
        );
    }

    public function testPasswordResetBuildsLinkFromTrustedAppUrl(): void
    {
        $content = file_get_contents(dirname(__DIR__) . '/../src/Controllers/PasswordResetController.php');
        $resolverContent = file_get_contents(dirname(__DIR__) . '/../src/Util/AppUrlResolver.php');

        $this->assertIsString($content);
        $this->assertIsString($resolverContent);
        $this->assertStringContainsString('AppUrlResolver::resolveBaseUrl($request)', $content);
        $this->assertStringContainsString("EnvHelper::read('APP_URL'", $resolverContent);
        $this->assertStringContainsString("X-Forwarded-Host", $resolverContent);
        $this->assertStringContainsString("X-Forwarded-Proto", $resolverContent);
    }

    /**
     * Die Bereinigung lag früher als wortgleiche Kopie in jedem ausliefernden
     * Controller. Anhänge laufen inzwischen ausschließlich über
     * AttachmentController/AttachmentResponseFactory - Finanzen und
     * App-Einstellungen bauen daneben weiterhin eigene, von Anhängen
     * unabhängige Downloads (PDF-/CSV-Export bzw. ICS-Kalenderexport) und
     * sanitisieren ihren Dateinamen dafür selbst.
     */
    public function testFileNameSanitizationForContentDispositionExists(): void
    {
        foreach (['FinanceController', 'AppSettingController'] as $controller) {
            $content = file_get_contents(dirname(__DIR__) . '/../src/Controllers/' . $controller . '.php');

            $this->assertIsString($content);
            $this->assertStringContainsString('DownloadFileName::sanitize(', $content, $controller);
            $this->assertStringNotContainsString(
                'function normalizeFileName',
                $content,
                $controller . ' darf keine eigene Kopie der Bereinigung mehr halten'
            );
        }

        // Der einzige verbliebene Auslieferer für Anhänge: die Bereinigung
        // steht hier einmal statt verstreut in fünf Controllern.
        $factoryContent = file_get_contents(dirname(__DIR__) . '/../src/Services/AttachmentResponseFactory.php');
        $this->assertIsString($factoryContent);
        $this->assertStringContainsString('DownloadFileName::sanitize(', $factoryContent);
        $this->assertStringContainsString('filename*=', $factoryContent);

        // DownloadController, TaskController, SponsorController und
        // SponsorshipController hatten früher je eine eigene Kopie des
        // Download-Headers - alle vier bauen inzwischen keinen mehr selbst.
        //
        // Beide Verbote stehen nebeneinander, und das ist Absicht: `DownloadFileName`
        // allein fängt nur die *bereinigte* Kopie. Ein wieder eingebauter, von Hand
        // zusammengesetzter Header wie
        //     'attachment; filename="' . $attachment->original_name . '"'
        // enthält den Namen der Bereinigungsklasse gerade nicht - also genau die
        // gefährlichere der beiden Rückfälle. Das Verbot von `Content-Disposition`
        // fängt sie.
        foreach (
            ['DownloadController', 'TaskController', 'SponsorController', 'SponsorshipController'] as $controller
        ) {
            $content = (string) file_get_contents(dirname(__DIR__) . '/../src/Controllers/' . $controller . '.php');
            $this->assertStringNotContainsString(
                'DownloadFileName',
                $content,
                $controller . ' darf keinen Dateinamen für einen Anhang mehr selbst bereinigen'
            );
            // Ein `Content-Disposition` mit festem Wert ist harmlos - der
            // Kalenderfeed in TaskController hat einen. Gefährlich ist der
            // Header, der einen Wert einsetzt: sobald ein `$` im Wert steht,
            // stammt der Dateiname aus den Daten und gehört bereinigt.
            preg_match_all("/'Content-Disposition',\s*(.+)/", $content, $matches);
            foreach ($matches[1] as $headerValue) {
                $this->assertStringNotContainsString(
                    '$',
                    $headerValue,
                    $controller . ' darf keinen Auslieferungs-Header aus Daten zusammensetzen'
                );
            }
            $this->assertStringNotContainsString(
                'function normalizeFileName',
                $content,
                $controller . ' darf keine eigene Kopie der Bereinigung mehr halten'
            );
        }

        foreach (['FinanceController', 'AppSettingController'] as $controller) {
            $content = (string) file_get_contents(dirname(__DIR__) . '/../src/Controllers/' . $controller . '.php');
            $this->assertStringContainsString('filename*=', $content, $controller);
        }
    }

    /**
     * Was die Bereinigung leisten muss: Steuerzeichen brechen den
     * Content-Disposition-Kopf auf, Anführungszeichen beenden den quoted-string,
     * Pfadtrenner laden zur Deutung als Pfad ein - und leer darf nie herauskommen.
     */
    public function testFileNameSanitizationNeutralisesHeaderBreakingCharacters(): void
    {
        $this->assertSame(
            'bad__ok_name.pdf',
            \App\Util\DownloadFileName::sanitize("bad\r\nok\"name.pdf")
        );
        $this->assertSame('a_b_c.pdf', \App\Util\DownloadFileName::sanitize('a/b\\c.pdf'));

        // Der Fallback greift nur, wenn wirklich nichts übrig bleibt. Steuerzeichen
        // werden zu Unterstrichen und zählen danach als Inhalt - "_____" ist ein
        // harmloser Name, siehe DownloadFeatureTest.
        $this->assertSame('download', \App\Util\DownloadFileName::sanitize('   '));
        $this->assertSame('download', \App\Util\DownloadFileName::sanitize(''));
    }

    public function testCsrfTokenIsExposedInLayoutAndInjectedByJs(): void
    {
        $layout = file_get_contents(dirname(__DIR__) . '/../templates/layout.twig');
        $layoutModal = file_get_contents(dirname(__DIR__) . '/../templates/layout_modal.twig');
        $commonJs = file_get_contents(dirname(__DIR__) . '/../public/js/common.js');
        $middleware = file_get_contents(dirname(__DIR__) . '/../src/Middleware/HtmlFormCsrfInjectorMiddleware.php');
        $pipeline = file_get_contents(dirname(__DIR__) . '/../src/Middleware.php');
        $login = file_get_contents(dirname(__DIR__) . '/../templates/auth/login.twig');
        $forgot = file_get_contents(dirname(__DIR__) . '/../templates/auth/forgot_password.twig');
        $reset = file_get_contents(dirname(__DIR__) . '/../templates/auth/reset_password.twig');
        $setup = file_get_contents(dirname(__DIR__) . '/../templates/auth/setup.twig');
        $userMenu = file_get_contents(dirname(__DIR__) . '/../templates/partials/navigation/user_menu.twig');

        $this->assertIsString($layout);
        $this->assertIsString($layoutModal);
        $this->assertIsString($commonJs);
        $this->assertIsString($middleware);
        $this->assertIsString($pipeline);
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
        $this->assertStringContainsString('class HtmlFormCsrfInjectorMiddleware', $middleware);
        $this->assertStringContainsString('$app->add(HtmlFormCsrfInjectorMiddleware::class);', $pipeline);
    }

    public function testDebugDetailsDefaultToSafeEnvironmentHelper(): void
    {
        $settings = file_get_contents(dirname(__DIR__) . '/../src/Settings.php');
        $middleware = file_get_contents(dirname(__DIR__) . '/../src/Middleware.php');
        $environment = file_get_contents(dirname(__DIR__) . '/../src/Util/AppEnvironment.php');

        $this->assertIsString($settings);
        $this->assertIsString($middleware);
        $this->assertIsString($environment);
        $this->assertStringContainsString('AppEnvironment::isDebugEnabled()', $settings);
        $this->assertStringContainsString('AppEnvironment::isDebugEnabled()', $middleware);
        $this->assertStringContainsString("EnvHelper::read('APP_ENV', 'production')", $environment);
    }
}
