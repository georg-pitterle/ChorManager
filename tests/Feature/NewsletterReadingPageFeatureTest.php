<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Newsletter;
use App\Models\NewsletterArchive;
use App\Models\User;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * "Im Browser ansehen" in der Mail führt auf eine eigene Leseseite: die Mail selbst,
 * ganzseitig, ohne App-Gerüst und ohne eingebetteten Rahmen. Wer dort landet, liest - auch
 * wenn er Newsletter verwaltet; die Verwaltungs-Vorschau erreicht man aus der Liste.
 */
final class NewsletterReadingPageFeatureTest extends TestCase
{
    use NewsletterControllerTestScaffold;

    public function testReadingPageIsTheMailItselfWithoutAppChrome(): void
    {
        [$reader, $newsletter] = $this->sentTo('Georg', 'Konzertinfo');

        $response = $this->read($reader, $newsletter);
        $body = (string) $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringStartsWith('<!DOCTYPE html>', ltrim($body));
        $this->assertStringContainsString('Konzertinfo', $body);
        $this->assertStringContainsString('Hallo Georg', $body);
        $this->assertStringNotContainsString('<iframe', $body);
        $this->assertStringNotContainsString('Seite suchen', $body);
        $this->assertStringNotContainsString('Newsletter-Vorschau', $body);
        $this->assertStringNotContainsString('Im Browser ansehen', $body);
    }

    public function testReadingPageLeadsBackToTheArchiveAndNamesTheSendDate(): void
    {
        [$reader, $newsletter] = $this->sentTo('Georg', 'Konzertinfo');

        $body = (string) $this->read($reader, $newsletter)->getBody();

        $this->assertStringContainsString('href="/newsletters/archive"', $body);
        $this->assertStringContainsString('Gesendet am 03.10.2026', $body);
        $this->assertStringContainsString('/css/newsletter-read.css', $body);
    }

    public function testReadingPageListsAttachedFiles(): void
    {
        [$reader, $newsletter] = $this->sentTo('Georg', 'Konzertinfo');
        $attached = $this->attachment((int) $newsletter->id, 'programm.pdf');

        $body = (string) $this->read($reader, $newsletter)->getBody();

        $this->assertStringContainsString('Anhänge', $body);
        $this->assertStringContainsString('/attachments/' . $attached->id . '/download', $body);
    }

    /**
     * Wer verwaltet, liest hier trotzdem nur mit den eigenen Daten: Fremde Empfängerdaten
     * gehören in die Verwaltungs-Vorschau, nicht auf eine Seite, die aus der Mail kommt.
     */
    public function testManagerReadsWithOwnDataEvenWhenAskingForAnotherRecipient(): void
    {
        [$reader, $newsletter] = $this->sentTo('Georg', 'Konzertinfo');
        $manager = $this->createUser('Anna');

        $_SESSION['user_id'] = (int) $manager->id;
        $_SESSION['can_manage_newsletters'] = true;
        $request = $this->makeRequest('GET', "/newsletters/{$newsletter->id}/read", [], [
            'recipient_id' => (string) $reader->id,
        ])->withAttribute('id', (string) $newsletter->id);
        $body = (string) $this->controller()->read($request, $this->makeResponse())->getBody();

        $this->assertStringContainsString('Hallo Anna', $body);
        $this->assertStringNotContainsString('Hallo Georg', $body);
        $this->assertStringNotContainsString('Empfänger:', $body);
    }

    public function testStrangerWithoutArchiveEntryIsRejected(): void
    {
        [, $newsletter] = $this->sentTo('Georg', 'Konzertinfo');
        $stranger = $this->createUser('Lisa');

        $this->assertSame(403, $this->read($stranger, $newsletter)->getStatusCode());
    }

    public function testReaderPreviewAsPageRedirectsToTheReadingPage(): void
    {
        [$reader, $newsletter] = $this->sentTo('Georg', 'Konzertinfo');

        $_SESSION['user_id'] = (int) $reader->id;
        $_SESSION['can_manage_newsletters'] = false;
        $request = $this->makeRequest('GET', "/newsletters/{$newsletter->id}/preview")
            ->withAttribute('id', (string) $newsletter->id);
        $response = $this->controller()->preview($request, $this->makeResponse());

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame("/newsletters/{$newsletter->id}/read", $response->getHeaderLine('Location'));
    }

    public function testManagerReaderPreviewAsPageRedirectsToo(): void
    {
        $manager = $this->createUser('Anna');
        $newsletter = $this->archive($manager, $manager, 'Konzertinfo');

        $_SESSION['user_id'] = (int) $manager->id;
        $_SESSION['can_manage_newsletters'] = true;
        $request = $this->makeRequest('GET', "/newsletters/{$newsletter->id}/preview", [], ['reader' => '1'])
            ->withAttribute('id', (string) $newsletter->id);
        $response = $this->controller()->preview($request, $this->makeResponse());

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame("/newsletters/{$newsletter->id}/read", $response->getHeaderLine('Location'));
    }

    public function testMailLinksTheReadingPage(): void
    {
        [, $newsletter] = $this->sentTo('Georg', 'Konzertinfo');

        $html = $this->mailRenderer()->renderHtml($newsletter, 'Konzertinfo', '<p>Text</p>', 'https://chor.example');

        $this->assertStringContainsString('href="https://chor.example/newsletters/' . $newsletter->id . '/read"', $html);
        $this->assertStringNotContainsString('newsletter-read.css', $html);
        $this->assertStringNotContainsString('href="/newsletters/archive"', $html);
    }

    public function testRouteIsRegisteredBesideThePreview(): void
    {
        $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Routes.php');

        $this->assertStringContainsString("'/newsletters/{id:[0-9]+}/read', [NewsletterController::class, 'read']", $routes);
    }

    /**
     * @return array{0: User, 1: Newsletter}
     */
    private function sentTo(string $firstName, string $title): array
    {
        $creator = $this->createUser('Clara');
        $reader = $this->createUser($firstName);

        return [$reader, $this->archive($creator, $reader, $title)];
    }

    private function archive(User $creator, User $recipient, string $title): Newsletter
    {
        $newsletter = Newsletter::create([
            'project_id' => null,
            'title' => $title,
            'content_html' => '<p>Hallo {{vorname}}</p>',
            'status' => Newsletter::STATUS_SENT,
            'recipient_count' => 1,
            'created_by' => $creator->id,
            'sent_at' => '2026-10-03 09:15:00',
        ]);

        (new \App\Services\Audience\AudienceFilterService())
            ->create(['user' => [(int) $recipient->id]], 'newsletter_id', (int) $newsletter->id);

        NewsletterArchive::create([
            'newsletter_id' => $newsletter->id,
            'user_id' => $recipient->id,
            'email' => $recipient->email,
            'sent_at' => '2026-10-03 09:15:00',
        ]);

        return $newsletter;
    }

    private function read(User $viewer, Newsletter $newsletter): ResponseInterface
    {
        $_SESSION['user_id'] = (int) $viewer->id;
        $_SESSION['can_manage_newsletters'] = false;

        $request = $this->makeRequest('GET', "/newsletters/{$newsletter->id}/read")
            ->withAttribute('id', (string) $newsletter->id);

        return $this->controller()->read($request, $this->makeResponse());
    }

    private function attachment(int $newsletterId, string $name): Attachment
    {
        return Attachment::create([
            'entity_type' => 'newsletter',
            'entity_id' => $newsletterId,
            'filename' => bin2hex(random_bytes(4)) . '_' . $name,
            'original_name' => $name,
            'mime_type' => 'application/pdf',
            'file_size' => 12,
            'file_content' => str_repeat('x', 12),
            'delivery_mode' => 'attach',
        ]);
    }
}
