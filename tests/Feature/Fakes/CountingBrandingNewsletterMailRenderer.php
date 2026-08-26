<?php

declare(strict_types=1);

namespace Tests\Feature\Fakes;

use App\Services\NewsletterMailRenderer;

/**
 * Zählt, wie oft MailBranding::resolve() über die geschützte fetchBranding()-Methode
 * tatsächlich aufgelöst wird - nicht wie oft renderHtml() aufgerufen wird. Dient dem Nachweis,
 * dass der Versand an mehrere Empfänger das Erscheinungsbild nur einmal je Renderer-Instanz
 * auflöst und danach den Zwischenspeicher aus NewsletterMailRenderer::resolveBranding() nutzt.
 */
final class CountingBrandingNewsletterMailRenderer extends NewsletterMailRenderer
{
    public int $brandingResolutions = 0;

    protected function fetchBranding(): array
    {
        $this->brandingResolutions++;

        return parent::fetchBranding();
    }
}
