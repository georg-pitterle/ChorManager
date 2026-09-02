<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\DashboardController;
use App\Models\Newsletter;
use App\Models\Project;
use App\Models\User;
use App\Navigation\NavigationBuilder;
use App\Navigation\NavigationContext;
use App\Services\MailQueueAdminService;
use PHPUnit\Framework\TestCase;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

class DashboardFeatureTest extends TestCase
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

    private function createUser(): User
    {
        $suffix = bin2hex(random_bytes(6));

        return User::create([
            'email' => "dashboard_{$suffix}@example.test",
            'password' => password_hash('secret', PASSWORD_BCRYPT),
            'first_name' => 'Test',
            'last_name' => 'Person',
            'is_active' => 1,
        ]);
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function createDashboardTwig(array $settings): Twig
    {
        $twig = new Twig(new FilesystemLoader(dirname(__DIR__, 2) . '/templates'));
        $environment = $twig->getEnvironment();
        $environment->addFilter(new TwigFilter(
            'person_name',
            static fn (mixed $person): string => (new \App\Services\NameFormatterService())->formatPerson($person)
        ));
        $environment->addGlobal('settings', $settings);
        $environment->addGlobal('session', $_SESSION);
        $this->registerMailBadgeStub($environment);
        $environment->addGlobal('app_settings', []);
        $environment->addGlobal('csrf_token', 'test-token');
        $environment->addFunction(new TwigFunction(
            'asset_path',
            static fn (string $path): string => $path
        ));
        $environment->addFunction(new TwigFunction(
            'navigation',
            static function (string $activeNav = '') use ($settings): array {
                $context = NavigationContext::fromSession($_SESSION, $settings, '/dashboard', $activeNav);

                return (new NavigationBuilder())->build($context);
            }
        ));

        return $twig;
    }

    /**
     * Die Kachel darf nicht länger an der Projektmitgliedschaft hängen: Wer
     * das Recht can_manage_newsletters hat, aber in keinem Projekt Mitglied
     * ist, muss den zuletzt versendeten, projektlosen Newsletter trotzdem
     * sehen. Vor der Korrektur schränkte whereIn('project_id', ...) auf die
     * Projekte des Benutzers ein und traf dabei nie NULL-Werte.
     */
    public function testLatestSentNewsletterTileShowsProjectlessNewsletterForManagerWithoutProjectMembership(): void
    {
        $manager = $this->createUser();
        $suffix = bin2hex(random_bytes(4));
        $title = "Projektloser Versand {$suffix}";

        Newsletter::create([
            'project_id' => null,
            'title' => $title,
            'content_html' => '<p>Inhalt</p>',
            'status' => Newsletter::STATUS_SENT,
            'created_by' => $manager->id,
            'sent_at' => date('Y-m-d H:i:s'),
        ]);

        $_SESSION = [
            'user_id' => (int) $manager->id,
            'can_manage_newsletters' => true,
        ];

        $settings = ['modules' => ['newsletter' => true]];
        $controller = new DashboardController(
            $this->createDashboardTwig($settings),
            new MailQueueAdminService(),
            $settings
        );

        $response = $controller->index($this->makeRequest('GET', '/dashboard'), $this->makeResponse());
        $body = (string) $response->getBody();

        $this->assertStringContainsString($title, $body);
    }

    /**
     * Ergänzend: Auch ein Newsletter aus einem fremden Projekt, in dem der
     * Benutzer nicht Mitglied ist, muss erscheinen – die Sichtbarkeit hängt
     * allein am Recht, nicht an der Mitgliedschaft.
     */
    public function testLatestSentNewsletterTileShowsNewsletterFromForeignProject(): void
    {
        $manager = $this->createUser();
        $project = Project::create(['name' => 'Fremdes Projekt ' . bin2hex(random_bytes(4))]);
        $suffix = bin2hex(random_bytes(4));
        $title = "Versand aus fremdem Projekt {$suffix}";

        Newsletter::create([
            'project_id' => $project->id,
            'title' => $title,
            'content_html' => '<p>Inhalt</p>',
            'status' => Newsletter::STATUS_SENT,
            'created_by' => $manager->id,
            'sent_at' => date('Y-m-d H:i:s'),
        ]);

        $_SESSION = [
            'user_id' => (int) $manager->id,
            'can_manage_newsletters' => true,
        ];

        $settings = ['modules' => ['newsletter' => true]];
        $controller = new DashboardController(
            $this->createDashboardTwig($settings),
            new MailQueueAdminService(),
            $settings
        );

        $response = $controller->index($this->makeRequest('GET', '/dashboard'), $this->makeResponse());
        $body = (string) $response->getBody();

        $this->assertStringContainsString($title, $body);
    }

    public function testDashboardControllerExposesLatestViewableSentNewsletter(): void
    {
        $controller = file_get_contents(dirname(__DIR__) . '/../src/Controllers/DashboardController.php');

        $this->assertIsString($controller);
        $this->assertStringContainsString("'latest_sent_newsletter' => " . '$latestSentNewsletter', $controller);
        $this->assertStringContainsString("'dead_mail_count' => " . '$deadMailCount', $controller);
        $this->assertStringContainsString('Newsletter::STATUS_SENT', $controller);
        $this->assertStringContainsString('countDeadLetters()', $controller);
        $this->assertStringContainsString("->orderBy('sent_at', 'desc')", $controller);
        $this->assertStringContainsString("->with(['project', 'recipientSources'])", $controller);
    }

    public function testDashboardTemplateContainsStructuredSectionsAndCommunicationAnchors(): void
    {
        $template = file_get_contents(dirname(__DIR__) . '/../templates/dashboard/index.twig');

        $this->assertIsString($template);

        $this->assertStringContainsString('class="dashboard-shell"', $template);
        $this->assertStringContainsString('Schnellzugriff', $template);
        $this->assertStringContainsString('Projektkontext', $template);
        $this->assertStringContainsString('Kommunikation', $template);
        $this->assertStringContainsString('dashboard-action-grid', $template);
        $this->assertStringContainsString('dashboard-context-grid', $template);
        $this->assertStringContainsString('dashboard-communication-grid', $template);

        $this->assertStringContainsString('Zuletzt versendeter Newsletter', $template);
        $this->assertStringContainsString('Mail-Queue', $template);
        $this->assertStringContainsString('href="/admin/mail-queue"', $template);
        $this->assertStringContainsString(
            'data-newsletter-modal-url="/newsletters/{{ latest_sent_newsletter.id }}/preview?modal=1"',
            $template
        );
        $this->assertStringContainsString('id="newsletterActionModal"', $template);
        $this->assertStringContainsString('<script src="/js/newsletters.js"></script>', $template);
    }

    public function testDashboardTemplateContainsPermissionAndEmptyStateGuards(): void
    {
        $template = file_get_contents(dirname(__DIR__) . '/../templates/dashboard/index.twig');

        $this->assertIsString($template);

        $this->assertStringContainsString(
            '{% if session.can_manage_own_voice_group or session.can_manage_attendance_all %}',
            $template
        );
        $this->assertStringContainsString(
            '{% set _finance_perm = session.can_read_finances or session.can_manage_finances %}',
            $template
        );
        $this->assertStringContainsString(
            '{% set _finance_panel_visible = settings.modules.finance and _finance_perm %}',
            $template
        );
        $this->assertStringContainsString('{% if _finance_panel_visible %}', $template);
        $this->assertStringContainsString('{% if session.can_manage_users %}', $template);
        $this->assertStringContainsString('{% if session.can_manage_tasks and current_project %}', $template);
        $this->assertStringContainsString('{% if session.can_manage_tasks and upcoming_project %}', $template);
        $this->assertStringContainsString('{% if dead_mail_count is not null %}', $template);
        $this->assertStringContainsString('Keine projektbezogenen Aufgabenbereiche verfügbar.', $template);
        $this->assertStringContainsString('Aktuell stehen für dich keine Kommunikationskarten bereit.', $template);
    }

    public function testDashboardControllerGatesProjectContextQueriesBehindTaskPermission(): void
    {
        $controller = file_get_contents(dirname(__DIR__) . '/../src/Controllers/DashboardController.php');

        $this->assertIsString($controller);
        $this->assertStringContainsString('if ($tasksModuleEnabled && $canManageTasks) {', $controller);
        $this->assertStringContainsString("(bool) (\$this->settings['modules']['tasks'] ?? false)", $controller);
    }

    public function testDashboardControllerOmitsUnusedSessionDataFromViewModel(): void
    {
        $controller = file_get_contents(dirname(__DIR__) . '/../src/Controllers/DashboardController.php');

        $this->assertIsString($controller);
        $this->assertStringNotContainsString("'can_manage_users' =>", $controller);
        $this->assertStringNotContainsString("'can_manage_own_voice_group' =>", $controller);
        $this->assertStringNotContainsString("'role_level' =>", $controller);
        $this->assertStringNotContainsString("'voice_group_ids' =>", $controller);
    }

    public function testNewsletterScopingUsesPermissionInsteadOfHardcodedRoleName(): void
    {
        $dashboardController = file_get_contents(dirname(__DIR__) . '/../src/Controllers/DashboardController.php');
        $newsletterController = file_get_contents(dirname(__DIR__) . '/../src/Controllers/NewsletterController.php');

        $this->assertIsString($dashboardController);
        $this->assertIsString($newsletterController);

        $this->assertStringNotContainsString("'Admin'", $dashboardController);
        $this->assertStringNotContainsString("where('name', 'Admin')", $newsletterController);
        $this->assertStringNotContainsString("\$_SESSION['can_manage_users']", $newsletterController);
    }

    public function testDashboardTemplateShowsEvaluationsCardForAllUsers(): void
    {
        $template = file_get_contents(dirname(__DIR__) . '/../templates/dashboard/index.twig');

        $this->assertIsString($template);
        $this->assertStringContainsString('href="/evaluations"', $template);

        $matched = preg_match(
            '/\{% if session\.can_manage_users %\}(.*?)\{% endif %\}/s',
            $template,
            $matches
        );
        $this->assertSame(1, $matched, 'Admin-only block missing in dashboard template.');
        $this->assertStringNotContainsString('href="/evaluations"', $matches[1]);
    }

    public function testDashboardExposesPendingRegistrationSummary(): void
    {
        $controller = file_get_contents(dirname(__DIR__) . '/../src/Controllers/DashboardController.php');
        $this->assertIsString($controller);
        $this->assertStringContainsString('PendingRegistrationSummaryService', $controller);
        $this->assertStringContainsString("'registration_summary' => " . '$registrationSummary', $controller);
        $this->assertStringContainsString(
            "(bool) (\$this->settings['modules']['registration'] ?? false)",
            $controller
        );

        $template = file_get_contents(dirname(__DIR__) . '/../templates/dashboard/index.twig');
        $this->assertIsString($template);
        $this->assertStringContainsString('{% if registration_summary %}', $template);
        $this->assertStringContainsString('Ausstehende Anmeldungen', $template);
        $this->assertStringContainsString('Noch nicht eingetragen: {{ registration_summary.pending }}', $template);
        $this->assertStringContainsString('Zusagen: {{ registration_summary.yes }}', $template);
        $this->assertStringContainsString('Absagen: {{ registration_summary.no }}', $template);
        $this->assertStringContainsString('Vielleicht: {{ registration_summary.maybe }}', $template);
        $this->assertStringContainsString('href="/registrations"', $template);
    }

    public function testDashboardTemplateUsesNeutralCommunicationEmptyState(): void
    {
        $template = file_get_contents(dirname(__DIR__) . '/../templates/dashboard/index.twig');

        $this->assertIsString($template);
        $this->assertStringNotContainsString('Sobald Newsletter-Rechte oder Mail-Queue-Zugriff', $template);
        $this->assertStringNotContainsString('Keine Schnellzugriffe verfügbar', $template);
    }
}
