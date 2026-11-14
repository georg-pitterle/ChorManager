<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\NewsletterTemplateController;
use App\Models\Newsletter;
use App\Models\NewsletterTemplate;
use App\Persistence\NewsletterTemplatePersistence;
use App\Services\HtmlSanitizer;
use App\Services\NameFormatterService;
use App\Services\NewsletterRecipientService;
use PHPUnit\Framework\TestCase;
use Slim\Views\Twig;

/**
 * Liegt die Anwendung hinter Cloudflare mit eingeschalteter E-Mail-Verschleierung, kommt
 * eine Adresse im Editor als "[email protected]" an. Wer dann speichert, schriebe den
 * Platzhalter dauerhaft in Newsletter oder Vorlage - und er ginge so an alle Empfänger.
 * Das Speichern wird deshalb mit einem Hinweis abgewiesen, statt still Kaputtes abzulegen.
 */
final class NewsletterObfuscatedEmailFeatureTest extends TestCase
{
    use NewsletterControllerTestScaffold;

    private const OBFUSCATED = '<p>Schreib an <a href="/cdn-cgi/l/email-protection#a1b2">[email&#160;protected]</a></p>';

    public function testStoringANewsletterWithObfuscatedAddressIsRejected(): void
    {
        $creator = $this->createUser('Anna');
        $_SESSION['user_id'] = (int) $creator->id;
        $_SESSION['can_manage_newsletters'] = true;
        $before = Newsletter::query()->count();

        $response = $this->controller()->store($this->jsonPost('/newsletters', [
            'title' => 'Probenplan',
            'content_html' => self::OBFUSCATED,
        ]), $this->makeResponse());

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('[email protected]', $this->errorOf($response));
        $this->assertSame($before, Newsletter::query()->count());
    }

    public function testUpdatingANewsletterWithObfuscatedAddressKeepsTheStoredContent(): void
    {
        $creator = $this->createUser('Anna');
        $recipient = $this->createUser('Georg');
        $_SESSION['user_id'] = (int) $creator->id;
        $_SESSION['can_manage_newsletters'] = true;
        $newsletter = $this->createNewsletter($creator, $recipient);
        (new \App\Services\NewsletterLockingService())->acquireLock($newsletter, (int) $creator->id);

        $request = $this->jsonPost("/newsletters/{$newsletter->id}", [
            'title' => 'Probenplan',
            'content_html' => self::OBFUSCATED,
        ])->withAttribute('id', (string) $newsletter->id);
        $response = $this->controller()->update($request, $this->makeResponse());

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('<p>{{anrede}}</p>', (string) $newsletter->fresh()?->content_html);
    }

    public function testStoringATemplateWithObfuscatedAddressIsRejected(): void
    {
        $creator = $this->createUser('Anna');
        $_SESSION['user_id'] = (int) $creator->id;
        $_SESSION['can_manage_newsletters'] = true;
        $before = NewsletterTemplate::query()->count();

        $response = $this->templateController()->store($this->jsonPost('/newsletters/templates', [
            'name' => 'Rundschreiben',
            'content_html' => self::OBFUSCATED,
        ]), $this->makeResponse());

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('[email protected]', $this->errorOf($response));
        $this->assertSame($before, NewsletterTemplate::query()->count());
    }

    public function testPlainAddressesAreStoredAsWritten(): void
    {
        $creator = $this->createUser('Anna');
        $_SESSION['user_id'] = (int) $creator->id;
        $_SESSION['can_manage_newsletters'] = true;

        $response = $this->controller()->store($this->jsonPost('/newsletters', [
            'title' => 'Probenplan',
            'content_html' => '<p>Schreib an <a href="mailto:kassa@chor.example">kassa@chor.example</a></p>',
        ]), $this->makeResponse());

        $this->assertLessThan(300, $response->getStatusCode());
        $stored = Newsletter::query()->where('created_by', $creator->id)->latest('id')->first();
        $this->assertStringContainsString('kassa@chor.example', (string) $stored?->content_html);
    }

    /**
     * @param array<string, string> $body
     */
    private function jsonPost(string $uri, array $body): \Psr\Http\Message\ServerRequestInterface
    {
        return $this->makeRequest('POST', $uri, $body, [], [
            'Accept' => 'application/json',
            'X-Requested-With' => 'XMLHttpRequest',
        ]);
    }

    private function errorOf(\Psr\Http\Message\ResponseInterface $response): string
    {
        $payload = json_decode((string) $response->getBody(), true);

        return (string) ($payload['error'] ?? '');
    }

    private function templateController(): NewsletterTemplateController
    {
        return new NewsletterTemplateController(
            Twig::create(dirname(__DIR__, 2) . '/templates'),
            new HtmlSanitizer(),
            new NewsletterTemplatePersistence(),
            new NewsletterRecipientService(),
            new NameFormatterService()
        );
    }
}
