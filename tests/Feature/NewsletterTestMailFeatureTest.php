<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MailQueue;
use App\Models\Newsletter;
use App\Models\NewsletterArchive;
use App\Models\NewsletterRecipient;
use PHPUnit\Framework\TestCase;

/**
 * Die Testmail geht ausschließlich an die eigene Adresse und berührt den Versand nicht.
 */
final class NewsletterTestMailFeatureTest extends TestCase
{
    use NewsletterControllerTestScaffold;

    public function testTestMailGoesToOwnAddressAndCreatesNoRecipientRows(): void
    {
        $creator = $this->createUser('Anna');
        $recipient = $this->createUser('Georg');
        $newsletter = $this->createNewsletter($creator, $recipient);

        $_SESSION['user_id'] = (int) $creator->id;
        $_SESSION['can_manage_newsletters'] = true;

        $request = $this->makeRequest('POST', "/newsletters/{$newsletter->id}/test-mail", [
            'title' => 'Probe für {{vorname}}',
            'content_html' => '<p>{{anrede}}</p>',
        ])->withAttribute('id', (string) $newsletter->id);

        $response = $this->controller()->testMail($request, $this->makeResponse());

        $this->assertSame(200, $response->getStatusCode());

        $queued = MailQueue::query()->where('recipient_email', $creator->email)->first();

        $this->assertNotNull($queued);
        $this->assertSame('Probe für Anna', (string) $queued->subject);
        $this->assertStringContainsString('Hallo Anna', (string) $queued->body_html);
        $this->assertSame(0, NewsletterRecipient::query()->where('newsletter_id', $newsletter->id)->count());
        $this->assertSame(0, NewsletterArchive::query()->where('newsletter_id', $newsletter->id)->count());
        $this->assertSame(Newsletter::STATUS_DRAFT, $newsletter->fresh()->status);
    }

    public function testTestMailIgnoresRecipientAddressFromRequest(): void
    {
        $creator = $this->createUser('Anna');
        $recipient = $this->createUser('Georg');
        $newsletter = $this->createNewsletter($creator, $recipient);

        $_SESSION['user_id'] = (int) $creator->id;
        $_SESSION['can_manage_newsletters'] = true;

        $request = $this->makeRequest('POST', "/newsletters/{$newsletter->id}/test-mail", [
            'title' => 'Probe',
            'content_html' => '<p>Inhalt</p>',
            'recipient_email' => 'angriff@example.test',
        ])->withAttribute('id', (string) $newsletter->id);

        $this->controller()->testMail($request, $this->makeResponse());

        $this->assertSame(0, MailQueue::query()->where('recipient_email', 'angriff@example.test')->count());
        $this->assertSame(1, MailQueue::query()->where('recipient_email', $creator->email)->count());
    }

    public function testTestMailRequiresManagementRight(): void
    {
        $creator = $this->createUser('Anna');
        $recipient = $this->createUser('Georg');
        $newsletter = $this->createNewsletter($creator, $recipient);

        $_SESSION['user_id'] = (int) $creator->id;
        $_SESSION['can_manage_newsletters'] = false;

        $request = $this->makeRequest('POST', "/newsletters/{$newsletter->id}/test-mail", [
            'title' => 'Probe',
            'content_html' => '<p>Inhalt</p>',
        ])->withAttribute('id', (string) $newsletter->id);

        $this->assertSame(403, $this->controller()->testMail($request, $this->makeResponse())->getStatusCode());
    }
}
