<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\NewsletterController;
use App\Models\Newsletter;
use App\Navigation\NavigationBuilder;
use App\Navigation\NavigationContext;
use App\Models\Project;
use App\Models\User;
use App\Services\HtmlSanitizer;
use App\Services\Mailer;
use App\Services\MailQueueService;
use App\Services\NameFormatterService;
use App\Services\NewsletterLockingService;
use App\Services\NewsletterPlaceholderService;
use App\Services\NewsletterRecipientService;
use App\Services\NewsletterService;
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Die Bearbeitungssperre eines Newsletters muss auch dann halten, wenn zwei
 * Requests gleichzeitig eintreffen und beide einen Stand vor der Sperre gelesen
 * haben.
 */
final class NewsletterLockingFeatureTest extends TestCase
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

    private function createUser(): User
    {
        $suffix = bin2hex(random_bytes(6));

        return User::create([
            'email' => "lock_{$suffix}@example.test",
            'password' => password_hash('secret', PASSWORD_BCRYPT),
            'first_name' => 'Sperr',
            'last_name' => 'Test',
            'is_active' => 1,
        ]);
    }

    private function createDraft(User $creator): Newsletter
    {
        $project = Project::create(['name' => 'Sperr-Projekt ' . bin2hex(random_bytes(4))]);

        return Newsletter::create([
            'project_id' => $project->id,
            'title' => 'Gesperrter Entwurf',
            'content_html' => '<p>Inhalt</p>',
            'status' => Newsletter::STATUS_DRAFT,
            'created_by' => $creator->id,
        ]);
    }

    public function testASecondWriterCannotOverwriteAnActiveLock(): void
    {
        $userA = $this->createUser();
        $userB = $this->createUser();
        $draft = $this->createDraft($userA);

        // Beide Requests haben den Newsletter geladen, bevor irgendjemand
        // gesperrt hat - beide Instanzen sehen "nicht gesperrt".
        $forUserA = Newsletter::findOrFail($draft->id);
        $forUserB = Newsletter::findOrFail($draft->id);

        $service = new NewsletterLockingService();

        $this->assertTrue($service->acquireLock($forUserA, (int) $userA->id));
        $this->assertFalse(
            $service->acquireLock($forUserB, (int) $userB->id),
            'Eine fremde, gültige Sperre darf nicht übernommen werden.'
        );

        $stored = Newsletter::findOrFail($draft->id);
        $this->assertSame((int) $userA->id, (int) $stored->locked_by);
    }

    public function testTheOwnerCanRenewItsOwnLock(): void
    {
        $userA = $this->createUser();
        $draft = $this->createDraft($userA);

        $service = new NewsletterLockingService();

        $this->assertTrue($service->acquireLock($draft, (int) $userA->id));
        $this->assertTrue(
            $service->acquireLock($draft, (int) $userA->id),
            'Der Inhaber muss seine eigene Sperre verlängern können.'
        );
        $this->assertSame((int) $userA->id, (int) Newsletter::findOrFail($draft->id)->locked_by);
    }

    public function testAnExpiredLockIsTakenOverByTheNextWriter(): void
    {
        $userA = $this->createUser();
        $userB = $this->createUser();
        $draft = $this->createDraft($userA);

        $draft->update([
            'locked_by' => $userA->id,
            'locked_at' => Carbon::now()->subHours(2),
        ]);

        $service = new NewsletterLockingService();

        $this->assertTrue(
            $service->acquireLock(Newsletter::findOrFail($draft->id), (int) $userB->id),
            'Eine abgelaufene Sperre darf übernommen werden.'
        );
        $this->assertSame((int) $userB->id, (int) Newsletter::findOrFail($draft->id)->locked_by);
    }

    public function testReleasingALockClearsBothColumns(): void
    {
        $userA = $this->createUser();
        $draft = $this->createDraft($userA);

        $service = new NewsletterLockingService();
        $service->acquireLock($draft, (int) $userA->id);
        $service->releaseLock($draft);

        $stored = Newsletter::findOrFail($draft->id);
        $this->assertNull($stored->locked_by);
        $this->assertNull($stored->locked_at);
    }

    /**
     * Baut den Controller mit derselben vollständigen Twig-Umgebung wie die
     * übrigen Feature-Tests, weil edit() die echte Seite samt Layout und
     * Navigation rendert.
     */
    private function newsletterController(NewsletterLockingService $lockingService): NewsletterController
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
                new NewsletterPlaceholderService(new NameFormatterService())
            ),
            $lockingService,
            new NewsletterRecipientService(),
            new HtmlSanitizer(),
            new NullLogger(),
            new NameFormatterService(),
            new NewsletterPlaceholderService(new NameFormatterService()),
            new MailQueueService()
        );
    }

    /**
     * Zwischen der Prüfung und dem Setzen der Sperre liegt ein Zeitfenster: Ist
     * die Sperre in diesem Moment an jemand anderen gegangen, darf der Controller
     * die Bearbeitungsseite nicht trotzdem ausliefern.
     */
    public function testEditRefusesWhenTheLockWasTakenBetweenCheckAndAcquire(): void
    {
        $userA = $this->createUser();
        $userB = $this->createUser();
        $draft = $this->createDraft($userA);

        $lockingService = $this->createStub(NewsletterLockingService::class);
        $lockingService->method('canEdit')->willReturn(true);
        $lockingService->method('acquireLock')->willReturn(false);

        $_SESSION['user_id'] = (int) $userB->id;
        $_SESSION['can_manage_newsletters'] = true;

        $response = $this->newsletterController($lockingService)->edit(
            $this->makeRequest('GET', '/newsletters/' . $draft->id . '/edit')
                ->withAttribute('id', (string) $draft->id),
            $this->makeResponse()
        );

        $this->assertSame(423, $response->getStatusCode());
    }

    /**
     * Dasselbe Zeitfenster beim Versand: Wer die Sperre nicht bekommen hat, darf
     * den Newsletter nicht verschicken.
     */
    public function testSendRefusesWhenTheLockCannotBeAcquired(): void
    {
        $userA = $this->createUser();
        $userB = $this->createUser();
        $draft = $this->createDraft($userA);

        $lockingService = $this->createStub(NewsletterLockingService::class);
        $lockingService->method('canEdit')->willReturn(true);
        $lockingService->method('isLockedBy')->willReturn(false);
        $lockingService->method('acquireLock')->willReturn(false);

        $_SESSION['user_id'] = (int) $userB->id;
        $_SESSION['can_manage_newsletters'] = true;

        $response = $this->newsletterController($lockingService)->send(
            $this->makeRequest('POST', '/newsletters/' . $draft->id . '/send')
                ->withHeader('Accept', 'application/json')
                ->withAttribute('id', (string) $draft->id),
            $this->makeResponse()
        );

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame(
            Newsletter::STATUS_DRAFT,
            Newsletter::findOrFail($draft->id)->status,
            'Ohne Sperre darf der Versand den Status nicht verändern.'
        );
    }
}
