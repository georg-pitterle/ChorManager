<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\NewsletterArchive;
use PHPUnit\Framework\TestCase;

/**
 * Die Vorschau löst Platzhalter live auf — für Empfänger mit deren eigenen Daten,
 * für Verwaltende wahlweise mit den Daten eines echten Empfängers. preview() selbst bettet nur
 * noch den Rahmen ein (siehe NewsletterPreviewFrameFeatureTest für die tatsächlich
 * personalisierten Inhalte); hier wird zusätzlich die Verdrahtung zur Rahmen-Adresse geprüft.
 */
final class NewsletterPreviewPersonalizationFeatureTest extends TestCase
{
    use NewsletterControllerTestScaffold;

    public function testRecipientSeesOwnDataInArchive(): void
    {
        $creator = $this->createUser('Anna');
        $recipient = $this->createUser('Georg');
        $newsletter = $this->createNewsletter($creator, $recipient);

        NewsletterArchive::create([
            'newsletter_id' => $newsletter->id,
            'user_id' => $recipient->id,
            'email' => $recipient->email,
            'sent_at' => '2026-08-18 10:00:00',
        ]);

        $_SESSION['user_id'] = (int) $recipient->id;
        $_SESSION['can_manage_newsletters'] = false;

        $request = $this->makeRequest('GET', "/newsletters/{$newsletter->id}/preview")
            ->withAttribute('id', (string) $newsletter->id);
        $response = $this->controller()->preview($request, $this->makeResponse());
        $body = (string) $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('src="/newsletters/' . $newsletter->id . '/preview-frame"', $body);

        $frameRequest = $this->makeRequest('GET', "/newsletters/{$newsletter->id}/preview-frame")
            ->withAttribute('id', (string) $newsletter->id);
        $frameResponse = $this->controller()->previewFrame($frameRequest, $this->makeResponse());

        $this->assertSame(200, $frameResponse->getStatusCode());
        $this->assertStringContainsString('Hallo Georg', (string) $frameResponse->getBody());
    }

    public function testManagerCanPreviewAsResolvedRecipient(): void
    {
        $creator = $this->createUser('Anna');
        $recipient = $this->createUser('Georg');
        $newsletter = $this->createNewsletter($creator, $recipient);

        $_SESSION['user_id'] = (int) $creator->id;
        $_SESSION['can_manage_newsletters'] = true;

        $request = $this->makeRequest(
            'GET',
            "/newsletters/{$newsletter->id}/preview",
            [],
            ['recipient_id' => (string) $recipient->id]
        )->withAttribute('id', (string) $newsletter->id);
        $response = $this->controller()->preview($request, $this->makeResponse());
        $body = (string) $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString(
            'src="/newsletters/' . $newsletter->id . '/preview-frame?recipient_id=' . $recipient->id . '"',
            $body
        );

        $frameRequest = $this->makeRequest(
            'GET',
            "/newsletters/{$newsletter->id}/preview-frame",
            [],
            ['recipient_id' => (string) $recipient->id]
        )->withAttribute('id', (string) $newsletter->id);
        $frameResponse = $this->controller()->previewFrame($frameRequest, $this->makeResponse());

        $this->assertSame(200, $frameResponse->getStatusCode());
        $this->assertStringContainsString('Hallo Georg', (string) $frameResponse->getBody());
    }

    public function testManagerCannotPreviewAsUnrelatedUser(): void
    {
        $creator = $this->createUser('Anna');
        $recipient = $this->createUser('Georg');
        $outsider = $this->createUser('Fremd');
        $newsletter = $this->createNewsletter($creator, $recipient);

        $_SESSION['user_id'] = (int) $creator->id;
        $_SESSION['can_manage_newsletters'] = true;

        $request = $this->makeRequest(
            'GET',
            "/newsletters/{$newsletter->id}/preview",
            [],
            ['recipient_id' => (string) $outsider->id]
        )->withAttribute('id', (string) $newsletter->id);
        $response = $this->controller()->preview($request, $this->makeResponse());

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testManagerWithoutParameterSeesOwnData(): void
    {
        $creator = $this->createUser('Anna');
        $recipient = $this->createUser('Georg');
        $newsletter = $this->createNewsletter($creator, $recipient);

        $_SESSION['user_id'] = (int) $creator->id;
        $_SESSION['can_manage_newsletters'] = true;

        $request = $this->makeRequest('GET', "/newsletters/{$newsletter->id}/preview")
            ->withAttribute('id', (string) $newsletter->id);
        $response = $this->controller()->preview($request, $this->makeResponse());
        $body = (string) $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('eigenen Daten', $body);
        $this->assertStringContainsString('src="/newsletters/' . $newsletter->id . '/preview-frame"', $body);

        $frameRequest = $this->makeRequest('GET', "/newsletters/{$newsletter->id}/preview-frame")
            ->withAttribute('id', (string) $newsletter->id);
        $frameResponse = $this->controller()->previewFrame($frameRequest, $this->makeResponse());

        $this->assertSame(200, $frameResponse->getStatusCode());
        $this->assertStringContainsString('Hallo Anna', (string) $frameResponse->getBody());
    }
}
