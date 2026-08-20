<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * Die Dev-Seed liefert Newsletter, an denen sich Platzhalter sofort ausprobieren lassen.
 */
final class NewsletterSeedPlaceholderFeatureTest extends TestCase
{
    private function seedSource(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/src/Services/DevSeedService.php');
    }

    public function testSeedContentUsesPlaceholders(): void
    {
        $source = $this->seedSource();

        $this->assertStringContainsString('{{anrede}}', $source);
        $this->assertStringContainsString('{{stimmgruppe}}', $source);
        $this->assertStringContainsString('{{archiv_link}}', $source);
    }

    public function testSeedContainsDraftWithPlaceholderInSubject(): void
    {
        $this->assertStringContainsString(
            "'title' => 'Entwurf für {{vorname}}: Probenplan'",
            $this->seedSource()
        );
    }

    public function testSeedContainsMemberWithoutFirstName(): void
    {
        $this->assertStringContainsString('placeholder_fallback', $this->seedSource());
    }
}
