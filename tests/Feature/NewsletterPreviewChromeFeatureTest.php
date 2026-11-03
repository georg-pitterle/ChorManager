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

    public function testReaderWithoutManagementRightLandsOnReadingPage(): void
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

        // Wer nur empfangen hat, liest auf der eigenen Leseseite; deren Zurück-Link führt ins
        // Archiv (NewsletterReadingPageFeatureTest).
        $request = $this->makeRequest('GET', "/newsletters/{$newsletter->id}/preview")
            ->withAttribute('id', (string) $newsletter->id);
        $response = $this->controller()->preview($request, $this->makeResponse());

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame("/newsletters/{$newsletter->id}/read", $response->getHeaderLine('Location'));
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

    /**
     * Der Betreff steht in der Seitenüberschrift und im Mailkopf - eine dritte Kopie im
     * Kartenkopf trägt nichts bei. Dort stehen stattdessen die Verwaltungsangaben, die
     * vorher abgelöst unter dem Rahmen schwebten.
     */
    public function testManagerPreviewNamesSubjectOnceAndCarriesFactsInTheHeader(): void
    {
        $creator = $this->createUser('Anna');
        $recipient = $this->createUser('Georg');
        $newsletter = $this->createNewsletter($creator, $recipient);
        $newsletter->recipient_count = 68;
        $newsletter->save();

        $_SESSION['user_id'] = (int) $creator->id;
        $_SESSION['can_manage_newsletters'] = true;

        $body = $this->renderPreview($newsletter->id);

        $this->assertStringNotContainsString('<h2 class="h5 mb-1">Probenplan</h2>', $body);
        $this->assertStringNotContainsString('card-footer', $body);
        $this->assertMatchesRegularExpression('/newsletter-preview-facts.*68 Empfänger.*Entwurf/s', $body);
    }

    public function testSentNewsletterPreviewNamesTheSendDateInsteadOfCreation(): void
    {
        $creator = $this->createUser('Anna');
        $recipient = $this->createUser('Georg');
        $newsletter = $this->createNewsletter($creator, $recipient);
        $newsletter->status = \App\Models\Newsletter::STATUS_SENT;
        $newsletter->sent_at = '2026-10-27 21:31:00';
        $newsletter->save();

        $_SESSION['user_id'] = (int) $creator->id;
        $_SESSION['can_manage_newsletters'] = true;

        $body = $this->renderPreview($newsletter->id);

        $this->assertStringContainsString('Versendet am 27.10.2026 21:31', $body);
        $this->assertStringNotContainsString('Erstellt:', $body);
    }

    private function renderPreview(int $newsletterId): string
    {
        $request = $this->makeRequest('GET', "/newsletters/{$newsletterId}/preview")
            ->withAttribute('id', (string) $newsletterId);

        return (string) $this->controller()->preview($request, $this->makeResponse())->getBody();
    }
}
