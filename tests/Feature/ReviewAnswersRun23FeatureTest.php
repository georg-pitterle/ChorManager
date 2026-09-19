<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\NewsletterController;
use App\Models\MailQueue;
use App\Models\Newsletter;
use App\Models\Project;
use App\Models\User;
use App\Navigation\NavigationBuilder;
use App\Navigation\NavigationContext;
use App\Services\HtmlSanitizer;
use App\Services\MailDeliveryService;
use App\Services\Mailer;
use App\Services\MailQueueAdminService;
use App\Services\MailQueueService;
use App\Services\NameFormatterService;
use App\Services\NewsletterLockingService;
use App\Services\NewsletterMailRenderer;
use App\Services\NewsletterPlaceholderService;
use App\Services\NewsletterRecipientService;
use App\Services\NewsletterService;
use App\Util\PasswordHasher;
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Antworten auf die Rückfragen aus dem Review-Lauf 23.
 *
 * 1) Der fertige Mailtext bleibt nach dem Versand in `mail_queue.body_html`
 *    stehen - bei einer Passwort-Zurücksetzung und bei einer Einladung samt
 *    dem Einmal-Link darin. Die Warteschlangen-Verwaltung zeigt ihn an, womit
 *    dieses eine Recht ausreicht, um jedes Konto zu übernehmen.
 *
 * 2) Die Listenabfrage der Warteschlange las ganze Zeilen samt `body_html`
 *    (longtext) und `payload_json`, obwohl die Liste beides nicht anzeigt.
 *
 * 3) Die Bearbeitungssperre eines Newsletters wurde nur beim Öffnen der Seite
 *    gesetzt und lief nach 30 Minuten ab. Der Editor fragte zwar nach, aber
 *    nur lesend - wer länger schrieb, verlor sie still.
 */
final class ReviewAnswersRun23FeatureTest extends TestCase
{
    use TestHttpHelpers;
    use TwigViewStubs;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $connection = Capsule::connection();
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        $_SESSION = [];
        parent::tearDown();
    }

    // ---------------------------------------------------------------- Punkt 1

    /**
     * @param array{success: bool, skipped: bool} $result
     */
    private function stubMailer(array $result): Mailer
    {
        return new class ($result) extends Mailer {
            /** @var array{success: bool, skipped: bool} */
            private array $result;

            /** @param array{success: bool, skipped: bool} $result */
            public function __construct(array $result)
            {
                $this->result = $result;
            }

            public function sendHtmlMailDetailed(string $to, string $subject, string $htmlBody): array
            {
                return $this->result + ['provider_name' => 'smtp', 'provider_message_id' => 'id-1'];
            }

            public function sendHtmlMail(string $to, string $subject, string $htmlBody): bool
            {
                return (bool) $this->result['success'];
            }
        };
    }

    private function queueEntry(string $mailType, string $status = 'queued'): MailQueue
    {
        return MailQueue::create([
            'mail_type' => $mailType,
            'recipient_email' => 'run23_' . bin2hex(random_bytes(4)) . '@example.test',
            'subject' => 'Lauf 23',
            'body_html' => '<p>Link: https://example.test/reset/geheim</p>',
            'payload_json' => ['run' => 23],
            'status' => $status,
            'attempts' => 0,
            'max_attempts' => 3,
            'is_retryable' => false,
            'next_attempt_at' => Carbon::now()->subMinute(),
        ]);
    }

    public function testASentResetMailKeepsNoLinkInTheQueue(): void
    {
        $entry = $this->queueEntry('password_reset');

        (new MailDeliveryService($this->stubMailer(['success' => true, 'skipped' => false])))
            ->sendEntry($entry);

        $entry->refresh();

        $this->assertSame('sent', $entry->status);
        $this->assertNull($entry->body_html, 'Der Einmal-Link darf nach dem Versand nicht stehenbleiben.');
        $this->assertNotSame('', (string) $entry->recipient_email, 'Die Fehlersuche braucht den Empfänger noch.');
        $this->assertSame('Lauf 23', $entry->subject, 'Der Betreff bleibt stehen.');
    }

    public function testASentInvitationKeepsNoLinkInTheQueue(): void
    {
        $entry = $this->queueEntry('invitation');

        (new MailDeliveryService($this->stubMailer(['success' => true, 'skipped' => false])))
            ->sendEntry($entry);

        $this->assertNull($entry->refresh()->body_html);
    }

    /**
     * Gegenprobe: Ein Newsletter trägt kein Geheimnis, und sein Text wird in der
     * Verwaltung gebraucht, um einen gemeldeten Versand nachzuvollziehen.
     */
    public function testASentNewsletterKeepsItsBody(): void
    {
        $entry = $this->queueEntry('newsletter');

        (new MailDeliveryService($this->stubMailer(['success' => true, 'skipped' => false])))
            ->sendEntry($entry);

        $this->assertNotNull($entry->refresh()->body_html);
    }

    /**
     * Zweite Gegenprobe: Solange nicht zugestellt wurde, bleibt der Text stehen -
     * ein erneuter Anlauf braucht ihn.
     */
    public function testAnUnsentResetMailKeepsItsBody(): void
    {
        $entry = $this->queueEntry('password_reset');

        (new MailDeliveryService($this->stubMailer(['success' => false, 'skipped' => false])))
            ->sendEntry($entry);

        $entry->refresh();

        $this->assertNotSame('sent', $entry->status);
        $this->assertNotNull($entry->body_html, 'Ohne Zustellung wird der Text für den nächsten Anlauf gebraucht.');
    }

    // ---------------------------------------------------------------- Punkt 2

    public function testTheQueueListDoesNotLoadTheMailBodies(): void
    {
        $this->queueEntry('newsletter');

        $entries = (new MailQueueAdminService())->listEntries(['per_page' => 5]);

        $this->assertGreaterThan(0, $entries->count());

        $loaded = array_keys($entries->first()->getAttributes());

        $this->assertNotContains('body_html', $loaded, 'Die Liste zeigt den Mailtext nicht an.');
        $this->assertNotContains('payload_json', $loaded, 'Die Liste zeigt die Nutzdaten nicht an.');

        // Was die Liste tatsächlich darstellt, muss geladen sein.
        foreach (['id', 'mail_type', 'recipient_email', 'status', 'attempts', 'created_at'] as $column) {
            $this->assertContains($column, $loaded, "Die Liste braucht {$column}.");
        }
    }

    /**
     * Die Einzelansicht zeigt den Mailtext weiterhin - dort ist er der Zweck.
     */
    public function testTheSingleEntryStillCarriesItsBody(): void
    {
        $entry = $this->queueEntry('newsletter');

        $loaded = (new MailQueueAdminService())->getEntry((int) $entry->id);

        $this->assertNotNull($loaded);
        $this->assertNotNull($loaded->body_html);
    }

    // ---------------------------------------------------------------- Punkt 3

    private function createUser(): User
    {
        $suffix = bin2hex(random_bytes(6));

        return User::create([
            'email' => "run23_{$suffix}@example.test",
            'password' => PasswordHasher::hash('secret'),
            'first_name' => 'Lauf',
            'last_name' => 'Dreiundzwanzig',
            'is_active' => 1,
        ]);
    }

    private function createDraft(User $creator): Newsletter
    {
        $project = Project::create(['name' => 'Lauf-23-Projekt ' . bin2hex(random_bytes(4))]);

        return Newsletter::create([
            'project_id' => $project->id,
            'title' => 'Entwurf',
            'content_html' => '<p>Inhalt</p>',
            'status' => Newsletter::STATUS_DRAFT,
            'created_by' => $creator->id,
        ]);
    }

    private function newsletterController(): NewsletterController
    {
        $twig = Twig::create(dirname(__DIR__, 2) . '/templates');
        $environment = $twig->getEnvironment();
        $environment->addFilter(new TwigFilter(
            'person_name',
            static fn(mixed $person): string => (new NameFormatterService())->formatPerson($person)
        ));
        $environment->addGlobal('session', $_SESSION);
        $environment->addGlobal('app_settings', []);
        $environment->addGlobal('current_path', '/newsletters');
        $this->registerMailBadgeStub($environment);
        $environment->addFunction(new TwigFunction('asset_path', static fn(string $path): string => $path));
        $environment->addFunction(new TwigFunction(
            'navigation',
            static function (string $activeNav = ''): array {
                $context = NavigationContext::fromSession($_SESSION, [], '/newsletters', $activeNav);

                return (new NavigationBuilder())->build($context);
            }
        ));

        return new NewsletterController(
            $twig,
            new NewsletterService(
                new NewsletterRecipientService(),
                new Mailer(new NullLogger()),
                new HtmlSanitizer(),
                new MailQueueService(),
                new NullLogger(),
                new NewsletterPlaceholderService(new NameFormatterService()),
                new NewsletterMailRenderer($twig)
            ),
            new NewsletterLockingService(),
            new NewsletterRecipientService(),
            new HtmlSanitizer(),
            new NullLogger(),
            new NameFormatterService(),
            new NewsletterPlaceholderService(new NameFormatterService()),
            new MailQueueService(),
            new NewsletterMailRenderer($twig)
        );
    }

    public function testTheOwnerCanRenewItsLockBeforeItExpires(): void
    {
        $owner = $this->createUser();
        $draft = $this->createDraft($owner);

        // Sperre 25 Minuten alt: noch gültig, aber in fünf Minuten verfallen.
        $draft->update([
            'locked_by' => $owner->id,
            'locked_at' => Carbon::now()->subMinutes(25),
        ]);

        $_SESSION['user_id'] = (int) $owner->id;
        $_SESSION['can_manage_newsletters'] = true;

        $response = $this->newsletterController()->renewLock(
            $this->makeRequest('POST', '/newsletters/' . $draft->id . '/renew-lock')
                ->withAttribute('id', (string) $draft->id),
            $this->makeResponse()
        );

        $payload = json_decode((string) $response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertIsArray($payload);
        $this->assertTrue($payload['renewed']);

        $stored = Newsletter::findOrFail($draft->id);
        $this->assertSame((int) $owner->id, (int) $stored->locked_by);
        $this->assertLessThan(
            60,
            Carbon::now()->diffInSeconds($stored->locked_at),
            'Die Sperre muss auf jetzt stehen, sonst verlängert sie nichts.'
        );
    }

    /**
     * Gegenprobe: Das Verlängern darf eine fremde, gültige Sperre nicht an sich
     * reissen - sonst wäre der Endpunkt ein Weg, jeden Entwurf zu übernehmen.
     */
    public function testRenewingDoesNotStealSomeoneElsesLock(): void
    {
        $owner = $this->createUser();
        $other = $this->createUser();
        $draft = $this->createDraft($owner);

        (new NewsletterLockingService())->acquireLock($draft, (int) $owner->id);

        $_SESSION['user_id'] = (int) $other->id;
        $_SESSION['can_manage_newsletters'] = true;

        $response = $this->newsletterController()->renewLock(
            $this->makeRequest('POST', '/newsletters/' . $draft->id . '/renew-lock')
                ->withAttribute('id', (string) $draft->id),
            $this->makeResponse()
        );

        $payload = json_decode((string) $response->getBody(), true);

        $this->assertSame(409, $response->getStatusCode());
        $this->assertIsArray($payload);
        $this->assertFalse($payload['renewed']);
        $this->assertSame(
            (int) $owner->id,
            (int) Newsletter::findOrFail($draft->id)->locked_by,
            'Die fremde Sperre bleibt, wo sie war.'
        );
    }

    /**
     * Der Editor muss den Endpunkt auch tatsächlich aufrufen - sonst verlängert
     * niemand etwas, und die Frist läuft wie bisher ab.
     */
    public function testTheEditorRenewsTheLockOnItsOwn(): void
    {
        $script = (string) file_get_contents(dirname(__DIR__, 2) . '/public/js/newsletters-edit.js');

        // Die vollstaendige Adresse samt abschliessendem Backtick, nicht nur der
        // Wortstamm: Sonst haelt die Zusicherung auch einen Tippfehler wie
        // "/renew-lock-x" noch fuer richtig.
        $this->assertStringContainsString(
            'fetch(`/newsletters/${newsletterId}/renew-lock`',
            $script,
            'Der Editor muss den Verlaengerungs-Endpunkt unter genau dieser Adresse aufrufen.'
        );
        $this->assertMatchesRegularExpression(
            '/renew-lock`[\s\S]{0,200}?method:\s*"POST"/',
            $script,
            'Verlaengern ist ein Schreibzugriff und geht per POST.'
        );

        // Das Intervall muss unter der serverseitigen Frist von 30 Minuten
        // liegen, sonst verlaengert es zu spaet.
        $this->assertMatchesRegularExpression('/renew-lock[\s\S]{0,400}?\}, 600000\);/', $script);
    }
}
