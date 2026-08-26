<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Navigation\NavigationBuilder;
use App\Navigation\NavigationContext;
use App\Services\NameFormatterService;
use Slim\Views\Twig;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Shared seams for tests that hand-build a Twig environment and render the real
 * layout instead of going through the DI container.
 */
trait TwigViewStubs
{
    /**
     * Twig-Umgebung, die das echte layout.twig rendern kann.
     *
     * Ein blankes Twig::create() reicht dafür nicht: Das Layout ruft asset_path()
     * und navigation() auf und bricht sonst schon beim Übersetzen ab. Jede Seite,
     * die auf layout.twig aufbaut - auch errors/403.twig - braucht deshalb diese
     * Umgebung statt des blanken Laders.
     */
    protected function createAppTwig(string $currentPath = '/'): Twig
    {
        $twig = new Twig(new FilesystemLoader(dirname(__DIR__, 2) . '/templates'));
        $environment = $twig->getEnvironment();

        $environment->addFilter(new TwigFilter(
            'person_name',
            static fn (mixed $person): string => (new NameFormatterService())->formatPerson($person)
        ));
        $environment->addGlobal('session', $_SESSION);
        $environment->addGlobal('current_path', $currentPath);
        $environment->addGlobal('app_settings', []);
        $this->registerMailBadgeStub($environment);

        $environment->addFunction(new TwigFunction(
            'asset_path',
            static fn (string $path): string => $path
        ));
        $environment->addFunction(new TwigFunction(
            'navigation',
            static function (string $activeNav = '') use ($currentPath): array {
                $context = NavigationContext::fromSession($_SESSION, [], $currentPath, $activeNav);

                return (new NavigationBuilder())->build($context);
            }
        ));

        return $twig;
    }

    /**
     * Register the mail-badge function that the layout's user menu calls.
     *
     * Production wires it in Dependencies.php as a function (not a global) so the
     * badge is resolved at render time rather than while Twig is constructed.
     */
    protected function registerMailBadgeStub(
        Environment $environment,
        ?int $unseenCount = null,
        ?string $externalWebmailUrl = null
    ): void {
        $environment->addFunction(new TwigFunction(
            'mail_badge',
            static fn (): array => [
                'unseen_count' => $unseenCount,
                'external_webmail_url' => $externalWebmailUrl,
            ]
        ));
    }
}
