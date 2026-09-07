<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Newsletter;
use App\Models\NewsletterArchive;
use App\Models\NewsletterRecipientSource;
use App\Models\User;
use PHPUnit\Framework\TestCase;

/**
 * Im Archiv steht derselbe Betreff, der in der Mail angekommen ist. Ungelöst zeigte die
 * Liste "Entwurf für {{vorname}}: Probenplan", während die Mail längst den Namen trug.
 */
final class NewsletterArchiveSubjectFeatureTest extends TestCase
{
    use NewsletterControllerTestScaffold;

    public function testArchiveListResolvesPlaceholdersInSubject(): void
    {
        $creator = $this->createUser('Anna');
        $recipient = $this->createUser('Georg');
        $this->createArchivedNewsletter($creator, $recipient, 'Probenplan für {{vorname}}');

        $_SESSION['user_id'] = (int) $recipient->id;
        $_SESSION['can_manage_newsletters'] = false;

        $body = $this->renderArchive();

        $this->assertStringContainsString('Probenplan für Georg', $body);
        $this->assertStringNotContainsString('{{vorname}}', $body);
    }

    public function testUnknownPlaceholderStaysVisible(): void
    {
        $creator = $this->createUser('Anna');
        $recipient = $this->createUser('Georg');
        $this->createArchivedNewsletter($creator, $recipient, 'Probenplan {{unbekannt}}');

        $_SESSION['user_id'] = (int) $recipient->id;
        $_SESSION['can_manage_newsletters'] = false;

        $this->assertStringContainsString('{{unbekannt}}', $this->renderArchive());
    }

    private function renderArchive(): string
    {
        $request = $this->makeRequest('GET', '/newsletters/archive');
        $response = $this->controller()->archive($request, $this->makeResponse());

        return (string) $response->getBody();
    }

    private function createArchivedNewsletter(User $creator, User $recipient, string $title): Newsletter
    {
        $newsletter = Newsletter::create([
            'project_id' => null,
            'title' => $title,
            'content_html' => '<p>{{anrede}}</p>',
            'status' => Newsletter::STATUS_SENT,
            'recipient_count' => 1,
            'created_by' => $creator->id,
            'sent_at' => '2026-09-12 18:30:00',
        ]);

        NewsletterRecipientSource::create([
            'newsletter_id' => $newsletter->id,
            'source_type' => NewsletterRecipientSource::TYPE_USER,
            'reference_id' => $recipient->id,
        ]);

        NewsletterArchive::create([
            'newsletter_id' => $newsletter->id,
            'user_id' => $recipient->id,
            'email' => $recipient->email,
            'sent_at' => '2026-09-12 18:30:00',
        ]);

        return $newsletter;
    }
}
