<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\NewsletterArchive;
use PHPUnit\Framework\TestCase;

/**
 * Rahmen der Vorschau-Seite: Der Hinweis auf gefüllte Platzhalter trägt nur dann
 * Information, wenn fremde Empfängerdaten eingesetzt sind - bei den eigenen Daten ist er
 * Rauschen. Und der "Schließen"-Knopf muss dorthin führen, wo die betrachtende Person
 * auch hindarf: Wer den Newsletter nur empfangen hat, landete bisher über den Link aus
 * der Mail auf der Verwaltungsliste und damit auf 403.
 */
final class NewsletterPreviewChromeFeatureTest extends TestCase
{
    use NewsletterControllerTestScaffold;

    public function testOwnDataHintIsNotShown(): void
    {
        $creator = $this->createUser('Anna');
        $recipient = $this->createUser('Georg');
        $newsletter = $this->createNewsletter($creator, $recipient);

        $_SESSION['user_id'] = (int) $creator->id;
        $_SESSION['can_manage_newsletters'] = true;

        $body = $this->renderPreview($newsletter->id);

        $this->assertStringNotContainsString('deinen eigenen Daten', $body);
    }

    public function testForeignRecipientHintRemains(): void
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
        $body = (string) $this->controller()->preview($request, $this->makeResponse())->getBody();

        $this->assertStringContainsString('Platzhalter sind mit den Daten von', $body);
    }

    public function testCloseLinkLeadsToArchiveWithoutManagementRight(): void
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

        $body = $this->renderPreview($newsletter->id);

        $this->assertStringContainsString('href="/newsletters/archive"', $body);
        $this->assertStringNotContainsString('href="/newsletters?project_id=', $body);
    }

    public function testCloseLinkLeadsToNewsletterListWithManagementRight(): void
    {
        $creator = $this->createUser('Anna');
        $recipient = $this->createUser('Georg');
        $newsletter = $this->createNewsletter($creator, $recipient);

        $_SESSION['user_id'] = (int) $creator->id;
        $_SESSION['can_manage_newsletters'] = true;

        $body = $this->renderPreview($newsletter->id);

        $this->assertStringContainsString('href="/newsletters?project_id=', $body);
    }

    private function renderPreview(int $newsletterId): string
    {
        $request = $this->makeRequest('GET', "/newsletters/{$newsletterId}/preview")
            ->withAttribute('id', (string) $newsletterId);

        return (string) $this->controller()->preview($request, $this->makeResponse())->getBody();
    }
}
