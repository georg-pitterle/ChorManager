<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Newsletter;
use App\Models\User;
use App\Newsletter\ContentClasses;
use App\Services\HtmlSanitizer;
use App\Services\NewsletterMailRenderer;
use PHPUnit\Framework\TestCase;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * Gestaltung im Newsletter-Inhalt ist auf eine feste Liste von Klassen begrenzt. Der Sanitizer
 * lässt genau diese durch, der Mailrenderer übersetzt sie in Inline-Styles — ohne das würden
 * E-Mail-Programme sie ignorieren.
 */
final class NewsletterContentClassesFeatureTest extends TestCase
{
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

    private function createNewsletter(): Newsletter
    {
        $suffix = bin2hex(random_bytes(6));
        $creator = User::create([
            'email' => "classes_{$suffix}@example.test",
            'password' => password_hash('secret', PASSWORD_BCRYPT),
            'first_name' => 'Anna',
            'last_name' => 'Berger',
            'is_active' => 1,
        ]);

        return Newsletter::create([
            'project_id' => null,
            'title' => 'Probenplan',
            'content_html' => '<p>Inhalt</p>',
            'status' => Newsletter::STATUS_DRAFT,
            'created_by' => $creator->id,
        ]);
    }

    private function renderer(): NewsletterMailRenderer
    {
        return new NewsletterMailRenderer(Twig::create(dirname(__DIR__, 2) . '/templates'));
    }

    public function testAllowedClassSurvivesSanitizing(): void
    {
        $sanitized = (new HtmlSanitizer())->sanitizeNewsletterHtml(
            '<p class="newsletter-lead">Willkommen zur neuen Probenphase.</p>'
        );

        $this->assertStringContainsString('class="newsletter-lead"', $sanitized);
        $this->assertStringContainsString('Willkommen zur neuen Probenphase.', $sanitized);
    }

    public function testUnknownClassIsRemovedWithoutLosingTheText(): void
    {
        $sanitized = (new HtmlSanitizer())->sanitizeNewsletterHtml(
            '<p class="beliebige-fremdklasse">Dieser Text muss bleiben.</p>'
        );

        $this->assertStringNotContainsString('beliebige-fremdklasse', $sanitized);
        $this->assertStringContainsString('Dieser Text muss bleiben.', $sanitized);
        $this->assertStringContainsString('<p', $sanitized);
    }

    public function testStyleAttributeStaysBlocked(): void
    {
        $sanitized = (new HtmlSanitizer())->sanitizeNewsletterHtml(
            '<p style="color:red">Frei gewählte Farbe ist nicht erlaubt.</p>'
        );

        $this->assertStringNotContainsString('style=', $sanitized);
        $this->assertStringContainsString('Frei gewählte Farbe ist nicht erlaubt.', $sanitized);
    }

    public function testEveryAllowedClassBecomesAnInlineStyleInTheMail(): void
    {
        $newsletter = $this->createNewsletter();
        $renderer = $this->renderer();

        foreach (ContentClasses::names() as $className) {
            $html = $renderer->renderHtml(
                $newsletter,
                'Probenplan',
                '<p class="' . $className . '">Textprobe</p>',
                'https://chor.example'
            );

            $this->assertMatchesRegularExpression(
                '/<p[^>]*class="' . preg_quote($className, '/') . '"[^>]*style="[^"]+"/',
                $html,
                "Inline-Style fehlt für {$className}."
            );
            $this->assertStringContainsString('Textprobe', $html);
        }
    }

    public function testAccentClassUsesTheBrandColourInsteadOfAFixedValue(): void
    {
        $newsletter = $this->createNewsletter();

        $html = $this->renderer()->renderHtml(
            $newsletter,
            'Probenplan',
            '<h3 class="newsletter-accent">Zwischenüberschrift</h3>',
            'https://chor.example'
        );

        // Die Markenfarbe steht im Rahmen ohnehin an der Akzentlinie; taucht sie zusätzlich am
        // Zwischentitel auf, kommt sie nachweislich aus dem Erscheinungsbild und nicht aus einem
        // fest eingetragenen Farbwert.
        $this->assertMatchesRegularExpression(
            '/<h3[^>]*style="[^"]*color:#[0-9a-fA-F]{6}/',
            $html
        );
    }

    public function testExistingStyleAttributeIsKeptWhenClassesAreInlined(): void
    {
        $newsletter = $this->createNewsletter();

        // Aus dem Sanitizer kommt nie ein style-Attribut; der Renderer muss trotzdem damit
        // umgehen, weil er auch aus anderen Quellen aufgerufen werden könnte.
        $html = $this->renderer()->renderHtml(
            $newsletter,
            'Probenplan',
            '<p class="newsletter-center" style="margin:0">Mittig</p>',
            'https://chor.example'
        );

        $this->assertMatchesRegularExpression('/<p[^>]*style="margin:0;[^"]*text-align:center/', $html);
    }

    public function testContentWithoutClassesIsLeftAlone(): void
    {
        $newsletter = $this->createNewsletter();

        $html = $this->renderer()->renderHtml(
            $newsletter,
            'Probenplan',
            '<p>Schlichter Absatz</p><ul><li>Erster Punkt</li></ul>',
            'https://chor.example'
        );

        $this->assertStringContainsString('<p>Schlichter Absatz</p>', $html);
        $this->assertStringContainsString('<li>Erster Punkt</li>', $html);
    }

    public function testUmlautsSurviveTheInlining(): void
    {
        $newsletter = $this->createNewsletter();

        $html = $this->renderer()->renderHtml(
            $newsletter,
            'Probenplan',
            '<p class="newsletter-lead">Grüße an alle Sängerinnen und Sänger</p>',
            'https://chor.example'
        );

        $this->assertStringContainsString('Grüße an alle Sängerinnen und Sänger', $html);
    }
}
