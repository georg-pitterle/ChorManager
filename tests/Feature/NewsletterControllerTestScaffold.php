<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\NewsletterController;
use App\Models\Newsletter;
use App\Models\NewsletterArchive;
use App\Models\NewsletterRecipientSource;
use App\Models\User;
use App\Navigation\NavigationBuilder;
use App\Navigation\NavigationContext;
use App\Services\HtmlSanitizer;
use App\Services\MailQueueService;
use App\Services\Mailer;
use App\Services\NameFormatterService;
use App\Services\NewsletterLockingService;
use App\Services\NewsletterMailRenderer;
use App\Services\NewsletterPlaceholderService;
use App\Services\NewsletterRecipientService;
use App\Services\NewsletterService;
use Psr\Log\NullLogger;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Gemeinsames Gerüst für die Controller-Tests rund um Platzhalter: Datenbank je Test in einer
 * Transaktion, ein vollständig verdrahteter Controller und die immer gleichen Fixtures. Die
 * Vorschau löst Platzhalter live auf — für Empfänger mit deren eigenen Daten, für Verwaltende
 * wahlweise mit den Daten eines echten Empfängers.
 */
trait NewsletterControllerTestScaffold
{
    use TestHttpHelpers;
    use TwigViewStubs;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Bootstrap::getCapsule()?->connection()->beginTransaction();
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $connection = Bootstrap::getCapsule()?->connection();
        if ($connection !== null && $connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        $_SESSION = [];
        parent::tearDown();
    }

    private function createUser(string $firstName): User
    {
        $suffix = bin2hex(random_bytes(6));

        return User::create([
            'email' => "preview_{$suffix}@example.test",
            'password' => password_hash('secret', PASSWORD_BCRYPT),
            'first_name' => $firstName,
            'last_name' => 'Test',
            'is_active' => 1,
        ]);
    }

    private function controller(): NewsletterController
    {
        $twig = Twig::create(dirname(__DIR__, 2) . '/templates');
        $environment = $twig->getEnvironment();
        $environment->addFilter(new TwigFilter(
            'person_name',
            static fn (mixed $person): string => (new NameFormatterService())->formatPerson($person)
        ));
        $environment->addGlobal('session', $_SESSION);
        $environment->addGlobal('app_settings', []);
        $environment->addGlobal('current_path', '/newsletters');
        $this->registerMailBadgeStub($environment);
        $environment->addFunction(new TwigFunction('asset_path', static fn (string $path): string => $path));
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

    private function createNewsletter(User $creator, User $recipient): Newsletter
    {
        $newsletter = Newsletter::create([
            'project_id' => null,
            'title' => 'Probenplan',
            'content_html' => '<p>{{anrede}}</p>',
            'status' => Newsletter::STATUS_DRAFT,
            'created_by' => $creator->id,
        ]);

        NewsletterRecipientSource::create([
            'newsletter_id' => $newsletter->id,
            'source_type' => NewsletterRecipientSource::TYPE_USER,
            'reference_id' => $recipient->id,
        ]);

        return $newsletter;
    }
}
