<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class DashboardFeatureTest extends TestCase
{
    public function testDashboardControllerExposesLatestViewableSentNewsletter(): void
    {
        $controller = file_get_contents(dirname(__DIR__) . '/../src/Controllers/DashboardController.php');

        $this->assertIsString($controller);
        $this->assertStringContainsString("'latest_sent_newsletter' => " . '$latestSentNewsletter', $controller);
        $this->assertStringContainsString('Newsletter::STATUS_SENT', $controller);
        $this->assertStringContainsString("->orderBy('sent_at', 'desc')", $controller);
        $this->assertStringContainsString("->with(['project', 'event'])", $controller);
    }

    public function testDashboardTemplateContainsLatestNewsletterCardAndDistinctCardColors(): void
    {
        $template = file_get_contents(dirname(__DIR__) . '/../templates/dashboard/index.twig');

        $this->assertIsString($template);

        $this->assertStringContainsString('Zuletzt versendeter Newsletter', $template);
        $this->assertStringContainsString('latest_sent_newsletter', $template);
        $this->assertStringContainsString(
            'data-newsletter-modal-url="/newsletters/{{ latest_sent_newsletter.id }}/preview?modal=1"',
            $template
        );
        $this->assertStringContainsString('id="newsletterActionModal"', $template);
        $this->assertStringContainsString('<script src="/js/newsletters.js"></script>', $template);

        $this->assertStringContainsString('border-primary', $template);
        $this->assertStringContainsString('border-warning', $template);
        $this->assertStringContainsString('border-info', $template);
        $this->assertStringContainsString('border-secondary', $template);
        $this->assertStringContainsString('border-success', $template);
        $this->assertStringContainsString('border-dark', $template);
        $this->assertStringContainsString('border-danger', $template);
    }
}
