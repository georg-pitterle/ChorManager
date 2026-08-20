<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MailQueue;
use App\Models\Newsletter;
use App\Models\NewsletterRecipientSource;
use App\Models\User;
use App\Services\HtmlSanitizer;
use App\Services\MailQueueService;
use App\Services\Mailer;
use App\Services\NameFormatterService;
use App\Services\NewsletterPlaceholderService;
use App\Services\NewsletterRecipientService;
use App\Services\NewsletterService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Unit\Bootstrap;

/**
 * Versand personalisiert Body und Betreff je Empfänger.
 */
final class NewsletterPersonalizedSendFeatureTest extends TestCase
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

    private function service(): NewsletterService
    {
        return new NewsletterService(
            new NewsletterRecipientService(),
            new Mailer(new NullLogger()),
            new HtmlSanitizer(),
            new MailQueueService(),
            new NullLogger(),
            new NewsletterPlaceholderService(new NameFormatterService())
        );
    }

    private function createUser(string $firstName): User
    {
        $suffix = bin2hex(random_bytes(6));

        return User::create([
            'email' => "personalized_{$suffix}@example.test",
            'password' => password_hash('secret', PASSWORD_BCRYPT),
            'first_name' => $firstName,
            'last_name' => 'Test',
            'is_active' => 1,
        ]);
    }

    public function testEachRecipientGetsOwnBodyAndSubject(): void
    {
        $creator = $this->createUser('Anna');
        $first = $this->createUser('Georg');
        $second = $this->createUser('Maria');

        $newsletter = Newsletter::create([
            'project_id' => null,
            'title' => 'Probenplan für {{vorname}}',
            'content_html' => '<p>{{anrede}}, willkommen bei {{app_name}}.</p>',
            'status' => Newsletter::STATUS_DRAFT,
            'created_by' => $creator->id,
        ]);

        foreach ([$first, $second] as $recipient) {
            NewsletterRecipientSource::create([
                'newsletter_id' => $newsletter->id,
                'source_type' => NewsletterRecipientSource::TYPE_USER,
                'reference_id' => $recipient->id,
            ]);
        }

        $sentCount = $this->service()->send($newsletter, (int) $creator->id, 'https://chor.example');

        $this->assertSame(2, $sentCount);

        $queued = MailQueue::query()
            ->where('mail_type', 'newsletter')
            ->get()
            ->keyBy('recipient_email');

        $this->assertStringContainsString('Hallo Georg', (string) $queued[$first->email]->body_html);
        $this->assertStringContainsString('Hallo Maria', (string) $queued[$second->email]->body_html);
        $this->assertSame('Probenplan für Georg', (string) $queued[$first->email]->subject);
        $this->assertSame('Probenplan für Maria', (string) $queued[$second->email]->subject);
    }

    public function testStoredNewsletterKeepsRawTokens(): void
    {
        $creator = $this->createUser('Anna');
        $recipient = $this->createUser('Georg');

        $newsletter = Newsletter::create([
            'project_id' => null,
            'title' => 'Info',
            'content_html' => '<p>{{anrede}}</p>',
            'status' => Newsletter::STATUS_DRAFT,
            'created_by' => $creator->id,
        ]);

        NewsletterRecipientSource::create([
            'newsletter_id' => $newsletter->id,
            'source_type' => NewsletterRecipientSource::TYPE_USER,
            'reference_id' => $recipient->id,
        ]);

        $this->service()->send($newsletter, (int) $creator->id, 'https://chor.example');

        $this->assertStringContainsString('{{anrede}}', (string) $newsletter->fresh()->content_html);
    }
}
