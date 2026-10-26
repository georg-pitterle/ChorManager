<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Newsletter;
use App\Models\User;
use App\Services\NewsletterMailRenderer;
use App\Util\PasswordHasher;
use PHPUnit\Framework\TestCase;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * Der Editor hat Links auf Seiten der Anwendung früher relativ gespeichert
 * (`../../events/5`). In der Mail und im Vorschau-Rahmen gibt es keine Seite, gegen die sich
 * ein solcher Pfad auflösen ließe - der Link war tot. Der Mailrenderer macht deshalb jeden
 * relativen Verweis gegen die Basisadresse absolut.
 */
final class NewsletterRelativeLinksFeatureTest extends TestCase
{
    private const BASE_URL = 'https://chor.example.org';

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Bootstrap::getCapsule()?->connection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $connection = Bootstrap::getCapsule()?->connection();
        if ($connection !== null && $connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        parent::tearDown();
    }

    private function render(string $contentHtml, string $baseUrl = self::BASE_URL): string
    {
        $suffix = bin2hex(random_bytes(6));
        $creator = User::create([
            'email' => "links_{$suffix}@example.test",
            'password' => PasswordHasher::hash('secret'),
            'first_name' => 'Anna',
            'last_name' => 'Berger',
            'is_active' => 1,
        ]);

        $newsletter = Newsletter::create([
            'project_id' => null,
            'title' => 'Probenplan',
            'content_html' => $contentHtml,
            'status' => Newsletter::STATUS_DRAFT,
            'created_by' => $creator->id,
        ]);

        $renderer = new NewsletterMailRenderer(Twig::create(dirname(__DIR__, 2) . '/templates'));

        return $renderer->renderHtml($newsletter, 'Probenplan', $contentHtml, $baseUrl);
    }

    public function testDotSegmentLinkBecomesAbsolute(): void
    {
        $html = $this->render('<p><a href="../../events/5">Termin</a></p>');

        $this->assertStringContainsString('href="https://chor.example.org/events/5"', $html);
        $this->assertStringNotContainsString('href="../../events/5"', $html);
    }

    public function testSingleDotSegmentLinkBecomesAbsolute(): void
    {
        $html = $this->render('<p><a href="../events/5">Termin</a></p>');

        $this->assertStringContainsString('href="https://chor.example.org/events/5"', $html);
    }

    public function testRootRelativeLinkBecomesAbsolute(): void
    {
        $html = $this->render('<p><a href="/events/5">Termin</a></p>');

        $this->assertStringContainsString('href="https://chor.example.org/events/5"', $html);
    }

    public function testQueryAndFragmentArePreserved(): void
    {
        $html = $this->render('<p><a href="../members?tab=2&amp;q=a#top">Mitglieder</a></p>');

        $this->assertStringContainsString('href="https://chor.example.org/members?tab=2&amp;q=a#top"', $html);
    }

    public function testRelativeImageSourceBecomesAbsolute(): void
    {
        $html = $this->render('<p><img src="../../files/bild.png" alt="Bild"></p>');

        $this->assertStringContainsString('src="https://chor.example.org/files/bild.png"', $html);
    }

    public function testTrailingSlashInBaseUrlDoesNotDoubleTheSlash(): void
    {
        $html = $this->render('<p><a href="/events/5">Termin</a></p>', self::BASE_URL . '/');

        $this->assertStringContainsString('href="https://chor.example.org/events/5"', $html);
    }

    public function testAbsoluteAndSpecialLinksStayUntouched(): void
    {
        $html = $this->render(
            '<p><a href="https://example.com/x">Extern</a>'
            . '<a href="mailto:info@example.com">Mail</a>'
            . '<a href="tel:+43123">Telefon</a>'
            . '<a href="#oben">Anker</a>'
            . '<a href="//cdn.example.com/y">Protokollrelativ</a></p>'
        );

        $this->assertStringContainsString('href="https://example.com/x"', $html);
        $this->assertStringContainsString('href="mailto:info@example.com"', $html);
        $this->assertStringContainsString('href="tel:+43123"', $html);
        $this->assertStringContainsString('href="#oben"', $html);
        $this->assertStringContainsString('href="//cdn.example.com/y"', $html);
    }
}
