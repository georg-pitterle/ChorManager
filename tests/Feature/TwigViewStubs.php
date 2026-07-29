<?php

declare(strict_types=1);

namespace Tests\Feature;

use Twig\Environment;
use Twig\TwigFunction;

/**
 * Shared seams for tests that hand-build a Twig environment and render the real
 * layout instead of going through the DI container.
 */
trait TwigViewStubs
{
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
