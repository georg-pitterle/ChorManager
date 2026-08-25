<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MailQueue;
use App\Models\Newsletter;
use App\Models\NewsletterRecipientSource;
use App\Models\Project;
use App\Models\User;
use App\Services\HtmlSanitizer;
use App\Services\MailQueueService;
use App\Services\Mailer;
use App\Services\NameFormatterService;
use App\Services\NewsletterMailRenderer;
use App\Services\NewsletterPlaceholderService;
use App\Services\NewsletterRecipientService;
use App\Services\NewsletterService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Views\Twig;
use Tests\Feature\Fakes\CountingBrandingNewsletterMailRenderer;
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
        $twig = Twig::create(dirname(__DIR__, 2) . '/templates');

        return new NewsletterService(
            new NewsletterRecipientService(),
            new Mailer(new NullLogger()),
            new HtmlSanitizer(),
            new MailQueueService(),
            new NullLogger(),
            new NewsletterPlaceholderService(new NameFormatterService()),
            new NewsletterMailRenderer($twig)
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

    /**
     * Die Warteschlangen-Mail trägt denselben Rahmen wie die Systemmails: Kopfbereich,
     * Akzentfarbe, der personalisierte Betreff als Überschrift, der Newsletter-Text im
     * Inhaltsbereich und der Link zur Browser-Ansicht im Fußbereich.
     */
    public function testQueuedMailCarriesTheBrandFrame(): void
    {
        $creator = $this->createUser('Anna');
        $recipient = $this->createUser('Georg');

        $newsletter = Newsletter::create([
            'project_id' => null,
            'title' => 'Herbstkonzert-Neuigkeiten',
            'content_html' => '<p>Die Proben starten nächste Woche.</p>',
            'status' => Newsletter::STATUS_DRAFT,
            'created_by' => $creator->id,
        ]);

        NewsletterRecipientSource::create([
            'newsletter_id' => $newsletter->id,
            'source_type' => NewsletterRecipientSource::TYPE_USER,
            'reference_id' => $recipient->id,
        ]);

        $this->service()->send($newsletter, (int) $creator->id, 'https://chor.example');

        $bodyHtml = (string) MailQueue::query()
            ->where('mail_type', 'newsletter')
            ->where('recipient_email', $recipient->email)
            ->first()
            ->body_html;

        $this->assertStringContainsString('#E8A817', $bodyHtml, 'Markenfarbe fehlt.');
        $this->assertStringContainsString('#1d2836', $bodyHtml, 'Kopfbereich fehlt.');
        $this->assertStringContainsString('Herbstkonzert-Neuigkeiten', $bodyHtml, 'Betreff als Überschrift fehlt.');
        $this->assertStringContainsString(
            'Die Proben starten nächste Woche.',
            $bodyHtml,
            'Newsletter-Inhalt fehlt.'
        );
        $this->assertStringContainsString(
            'https://chor.example/newsletters/' . $newsletter->id . '/preview',
            $bodyHtml,
            'Link zur Browser-Ansicht im Fußbereich fehlt.'
        );
    }

    /**
     * Der Newsletter-Text ist an dieser Stelle bereits sanitisiert und Platzhalter sind bereits
     * ersetzt. Der Rahmen darf ihn nicht ein zweites Mal escapen, sonst erscheint Markup als
     * sichtbarer Text statt als Formatierung.
     */
    public function testQueuedMailDoesNotDoubleEscapeContent(): void
    {
        $creator = $this->createUser('Anna');
        $recipient = $this->createUser('Georg');

        $newsletter = Newsletter::create([
            'project_id' => null,
            'title' => 'Wichtige Info',
            'content_html' => '<p>Das ist <strong>sehr wichtig</strong>.</p>',
            'status' => Newsletter::STATUS_DRAFT,
            'created_by' => $creator->id,
        ]);

        NewsletterRecipientSource::create([
            'newsletter_id' => $newsletter->id,
            'source_type' => NewsletterRecipientSource::TYPE_USER,
            'reference_id' => $recipient->id,
        ]);

        $this->service()->send($newsletter, (int) $creator->id, 'https://chor.example');

        $bodyHtml = (string) MailQueue::query()
            ->where('mail_type', 'newsletter')
            ->where('recipient_email', $recipient->email)
            ->first()
            ->body_html;

        $this->assertStringContainsString('<strong>sehr wichtig</strong>', $bodyHtml);
        $this->assertStringNotContainsString('&lt;strong&gt;', $bodyHtml);
    }

    /**
     * Der Versand ruft den Renderer je Empfänger auf. Ohne Zwischenspeicher würde
     * MailBranding::resolve() (zwei Datenbankabfragen, im Zweifel Logo-Kodierung) bei jedem
     * Empfänger erneut laufen - bei mehreren hundert Empfängern hunderte überflüssige
     * Abfragen synchron im Web-Request. Der Renderer löst das Erscheinungsbild deshalb nur
     * beim ersten Aufruf auf und verwendet es danach wieder.
     */
    public function testMailRendererResolvesBrandingOnlyOnceAcrossMultipleRecipients(): void
    {
        $creator = $this->createUser('Anna');
        $first = $this->createUser('Georg');
        $second = $this->createUser('Maria');

        $newsletter = Newsletter::create([
            'project_id' => null,
            'title' => 'Probeninfo',
            'content_html' => '<p>Inhalt</p>',
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

        $twig = Twig::create(dirname(__DIR__, 2) . '/templates');
        $mailRenderer = new CountingBrandingNewsletterMailRenderer($twig);

        $service = new NewsletterService(
            new NewsletterRecipientService(),
            new Mailer(new NullLogger()),
            new HtmlSanitizer(),
            new MailQueueService(),
            new NullLogger(),
            new NewsletterPlaceholderService(new NameFormatterService()),
            $mailRenderer
        );

        $sentCount = $service->send($newsletter, (int) $creator->id, 'https://chor.example');

        $this->assertSame(2, $sentCount);
        $this->assertSame(
            1,
            $mailRenderer->brandingResolutions,
            'MailBranding::resolve() darf beim Versand an mehrere Empfänger nur einmal laufen.'
        );
    }

    /**
     * Die Kennzeile über der Überschrift zeigt den Projektnamen, wenn der Newsletter einem
     * Projekt zugeordnet ist.
     */
    public function testQueuedMailEyebrowShowsProjectNameWhenNewsletterHasProject(): void
    {
        $creator = $this->createUser('Anna');
        $recipient = $this->createUser('Georg');

        $project = Project::create([
            'name' => 'Projektchor ' . bin2hex(random_bytes(4)),
            'description' => 'Fixture-Projekt für den Kennzeilen-Test',
        ]);

        $newsletter = Newsletter::create([
            'project_id' => $project->id,
            'title' => 'Konzertankündigung',
            'content_html' => '<p>Inhalt</p>',
            'status' => Newsletter::STATUS_DRAFT,
            'created_by' => $creator->id,
        ]);

        NewsletterRecipientSource::create([
            'newsletter_id' => $newsletter->id,
            'source_type' => NewsletterRecipientSource::TYPE_USER,
            'reference_id' => $recipient->id,
        ]);

        $this->service()->send($newsletter, (int) $creator->id, 'https://chor.example');

        $bodyHtml = (string) MailQueue::query()
            ->where('mail_type', 'newsletter')
            ->where('recipient_email', $recipient->email)
            ->first()
            ->body_html;

        $this->assertStringContainsString(
            'padding-bottom:8px">' . $project->name . '</div>',
            $bodyHtml,
            'Kennzeile muss den Projektnamen zeigen.'
        );
    }

    /**
     * Ohne zugeordnetes Projekt zeigt die Kennzeile über der Überschrift schlicht "Newsletter".
     */
    public function testQueuedMailEyebrowFallsBackToNewsletterLabelWithoutProject(): void
    {
        $creator = $this->createUser('Anna');
        $recipient = $this->createUser('Georg');

        $newsletter = Newsletter::create([
            'project_id' => null,
            'title' => 'Konzertankündigung',
            'content_html' => '<p>Inhalt</p>',
            'status' => Newsletter::STATUS_DRAFT,
            'created_by' => $creator->id,
        ]);

        NewsletterRecipientSource::create([
            'newsletter_id' => $newsletter->id,
            'source_type' => NewsletterRecipientSource::TYPE_USER,
            'reference_id' => $recipient->id,
        ]);

        $this->service()->send($newsletter, (int) $creator->id, 'https://chor.example');

        $bodyHtml = (string) MailQueue::query()
            ->where('mail_type', 'newsletter')
            ->where('recipient_email', $recipient->email)
            ->first()
            ->body_html;

        $this->assertStringContainsString(
            'padding-bottom:8px">Newsletter</div>',
            $bodyHtml,
            'Kennzeile muss ohne Projekt auf den Ersatztext "Newsletter" zurückfallen.'
        );
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
