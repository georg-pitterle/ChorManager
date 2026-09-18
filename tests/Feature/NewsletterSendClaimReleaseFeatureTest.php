<?php

declare(strict_types=1);

namespace Tests\Feature;

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
use App\Util\PasswordHasher;
use Dotenv\Dotenv;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Views\Twig;

/**
 * Der Anspruch auf den Entwurf muss auch dann zurückfallen, wenn der Versand an
 * etwas scheitert, das keine `Exception` ist.
 *
 * `send()` setzt den Newsletter vor dem Einreihen auf "versendet", damit ein
 * Doppelklick ihn nicht zweimal verschickt. Ein `TypeError` aus einer Vorlage
 * oder einem Platzhalter ist ein `Error`, keine `Exception` - blieb er
 * ungefangen, stand der Newsletter dauerhaft als versendet da, ohne dass je
 * eine Mail eingereiht wurde. Über die Oberfläche liess er sich danach weder
 * senden noch bearbeiten.
 */
final class NewsletterSendClaimReleaseFeatureTest extends TestCase
{
    private static ?Capsule $capsule = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (self::$capsule !== null) {
            return;
        }

        $envPath = dirname(__DIR__, 2) . '/.env';
        if (file_exists($envPath)) {
            Dotenv::createImmutable(dirname(__DIR__, 2))->safeLoad();
        }

        $capsule = new Capsule();
        $capsule->addConnection([
            'driver' => 'mysql',
            'host' => $_ENV['DB_HOST'] ?? $_SERVER['DB_HOST'] ?? 'db',
            'database' => $_ENV['DB_DATABASE'] ?? $_SERVER['DB_DATABASE'] ?? 'db',
            'username' => $_ENV['DB_USERNAME'] ?? $_SERVER['DB_USERNAME'] ?? 'db',
            'password' => $_ENV['DB_PASSWORD'] ?? $_SERVER['DB_PASSWORD'] ?? 'db',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
        ]);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        self::$capsule = $capsule;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $_ENV['DISABLE_MAIL_SEND'] = $_SERVER['DISABLE_MAIL_SEND'] = 'true';

        self::$capsule?->connection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $connection = self::$capsule?->connection();
        if ($connection !== null && $connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        parent::tearDown();
    }

    private function makeServiceWithFailingRenderer(): NewsletterService
    {
        $twig = Twig::create(dirname(__DIR__, 2) . '/templates');

        $renderer = new class ($twig) extends NewsletterMailRenderer {
            public function renderHtml(
                Newsletter $newsletter,
                string $subject,
                string $contentHtml,
                string $baseUrl,
                bool $includeBrowseLink = true
            ): string {
                throw new \Error('Rendering ist an einem Fehler ausserhalb der Exception-Hierarchie gescheitert.');
            }
        };

        return new NewsletterService(
            new NewsletterRecipientService(),
            new Mailer(new NullLogger()),
            new HtmlSanitizer(),
            new MailQueueService(),
            new NullLogger(),
            new NewsletterPlaceholderService(new NameFormatterService()),
            $renderer
        );
    }

    private function createUser(): User
    {
        $suffix = bin2hex(random_bytes(6));

        return User::create([
            'email' => "claim_release_{$suffix}@example.test",
            'password' => PasswordHasher::hash('secret'),
            'first_name' => 'Test',
            'last_name' => 'User',
            'is_active' => 1,
        ]);
    }

    public function testDraftIsReleasedWhenDeliveryFailsWithAnError(): void
    {
        $project = Project::create(['name' => 'Claim Release ' . bin2hex(random_bytes(4))]);
        $member = $this->createUser();
        $project->users()->attach($member->id);

        $newsletter = Newsletter::create([
            'project_id' => $project->id,
            'title' => 'Entwurf mit scheiterndem Versand',
            'content_html' => '<p>Hallo Chor!</p>',
            'status' => Newsletter::STATUS_DRAFT,
            'created_by' => $member->id,
        ]);

        NewsletterRecipientSource::create([
            'newsletter_id' => $newsletter->id,
            'source_type' => NewsletterRecipientSource::TYPE_PROJECT_MEMBERS,
            'reference_id' => $project->id,
        ]);

        $thrown = null;
        try {
            $this->makeServiceWithFailingRenderer()->send($newsletter, (int) $member->id, 'https://chor.example');
        } catch (\Throwable $exception) {
            $thrown = $exception;
        }

        $this->assertNotNull($thrown, 'Ein gescheiterter Versand muss nach aussen sichtbar bleiben.');

        $stored = Newsletter::query()->find($newsletter->id);
        $this->assertNotNull($stored);
        $this->assertSame(
            Newsletter::STATUS_DRAFT,
            $stored->status,
            'Nach einem gescheiterten Versand muss der Newsletter wieder Entwurf sein.'
        );
        $this->assertNull($stored->sent_at);
    }
}
