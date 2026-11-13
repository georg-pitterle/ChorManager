<?php

declare(strict_types=1);

namespace Tests\Feature;

use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * Die Leiste "Als App installieren" oben im Inhalt.
 *
 * Sie steckt immer versteckt im Markup; ob sie erscheint, entscheidet erst
 * install-banner.js im Browser (Installationsangebot, iOS, schon installiert,
 * "Nicht mehr anzeigen"). Ohne Skript bleibt sie also unsichtbar.
 */
final class InstallBannerRenderFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
    }

    public function testTheBannerIsHiddenUntilTheScriptShowsIt(): void
    {
        $html = $this->render();

        $this->assertMatchesRegularExpression('/<div[^>]*data-install-banner[^>]*\shidden[\s>]/', $html);
    }

    public function testTheBannerOffersInstallIosHintAndDismissal(): void
    {
        $html = $this->render();

        $this->assertMatchesRegularExpression('/<button[^>]*data-install-banner-install[^>]*>.*?Installieren/s', $html);
        $this->assertMatchesRegularExpression('/data-install-banner-ios-hint[^>]*\shidden[\s>]/', $html);
        $this->assertStringContainsString('Zum Home-Bildschirm', $html);
        $this->assertMatchesRegularExpression('/<button[^>]*data-install-banner-dismiss[^>]*>.*?Nicht mehr anzeigen/s', $html);
    }

    public function testTheBannerCarriesNoInlineScriptOrStyle(): void
    {
        $html = $this->render();

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('style=', $html);
        $this->assertStringNotContainsString('onclick', $html);
    }

    public function testTheLayoutShowsBannerAndScriptOnlyToSignedInPeople(): void
    {
        $layout = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/layout.twig');

        // Zwischen "if session.user_id" und dem Fundort darf kein endif liegen.
        $guarded = '/\{%\s*if session\.user_id\s*%\}(?:(?!\{%\s*endif\s*%\}).)*?NEEDLE/s';
        foreach (['partials/install_banner.twig', '/js/install-banner.js'] as $needle) {
            $this->assertMatchesRegularExpression(str_replace('NEEDLE', preg_quote($needle, '/'), $guarded), $layout);
        }
    }

    private function render(): string
    {
        $containerBuilder = new ContainerBuilder();

        $settings = require dirname(__DIR__, 2) . '/src/Settings.php';
        $settings($containerBuilder);

        $dependencies = require dirname(__DIR__, 2) . '/src/Dependencies.php';
        $dependencies($containerBuilder);

        $container = $containerBuilder->build();
        $container->get(Capsule::class);

        return $container->get(Twig::class)->getEnvironment()->render('partials/install_banner.twig');
    }
}
