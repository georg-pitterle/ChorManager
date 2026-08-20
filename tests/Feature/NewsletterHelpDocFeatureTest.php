<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\NameFormatterService;
use App\Services\NewsletterPlaceholderService;
use PHPUnit\Framework\TestCase;

/**
 * Der Hilfetext dokumentiert jeden Platzhalter, den die Registry kennt.
 */
final class NewsletterHelpDocFeatureTest extends TestCase
{
    public function testHelpDocumentsEveryPlaceholder(): void
    {
        $doc = (string) file_get_contents(
            dirname(__DIR__, 2) . '/help/newsletter/docs/newsletter-compose.md'
        );

        foreach (array_keys((new NewsletterPlaceholderService(new NameFormatterService()))->definitions()) as $key) {
            $this->assertStringContainsString('{{' . $key . '}}', $doc, "Platzhalter fehlt in der Hilfe: {$key}");
        }
    }

    public function testHelpAvoidsRoleNames(): void
    {
        $doc = (string) file_get_contents(
            dirname(__DIR__, 2) . '/help/newsletter/docs/newsletter-compose.md'
        );

        foreach (['Vorstand', 'Kassier', 'Admin-Rolle'] as $roleName) {
            $this->assertStringNotContainsString($roleName, $doc, "Rollenname in der Hilfe: {$roleName}");
        }
    }
}
