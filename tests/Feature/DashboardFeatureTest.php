<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\DashboardController;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Newsletter;
use App\Models\NewsletterArchive;
use App\Models\Project;
use App\Models\User;
use App\Models\VoiceGroup;
use App\Navigation\NavigationBuilder;
use App\Navigation\NavigationContext;
use App\Policies\TaskPolicy;
use App\Services\MailQueueAdminService;
use App\Services\Storage\StorageUsageNode;
use App\Services\Storage\StorageUsageProvider;
use App\Services\Storage\StorageUsageService;
use App\Util\ByteFormatter;
use App\Util\PasswordHasher;
use Carbon\Carbon;
use Psr\Log\NullLogger;
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
    use AudienceFixtures;

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
            'password' => PasswordHasher::hash('secret'),
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
        $environment->addFilter(new TwigFilter(
            'format_bytes',
            static fn (mixed $bytes): string => ByteFormatter::format((int) $bytes)
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
            new TaskPolicy($_SESSION),
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
            new TaskPolicy($_SESSION),
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
        $this->assertStringContainsString('Handlungsbedarf', $template);
        $this->assertStringContainsString('Als Nächstes', $template);
        $this->assertStringContainsString('Dein Bereich', $template);
        $this->assertStringNotContainsString('Schnellzugriff', $template);
        $this->assertStringNotContainsString('Projektkontext', $template);

        $this->assertStringContainsString('nicht zugestellt', $template);
        $this->assertStringContainsString('href="/admin/mail-queue"', $template);
        $this->assertStringContainsString(
            'data-newsletter-modal-url="/newsletters/{{ latest_sent_newsletter.id }}/preview?modal=1"',
            $template
        );
        $this->assertStringContainsString('id="newsletterActionModal"', $template);
        $this->assertStringContainsString('<script src="/js/newsletters.js"></script>', $template);
        $this->assertStringContainsString('<script src="/js/registrations.js"></script>', $template);
    }

    public function testDashboardTemplateContainsPermissionGuards(): void
    {
        $template = file_get_contents(dirname(__DIR__) . '/../templates/dashboard/index.twig');

        $this->assertIsString($template);

        $this->assertStringContainsString(
            '{% set _attendance_visible = session.can_manage_own_voice_group or session.can_manage_attendance_all %}',
            $template
        );
        $this->assertStringContainsString('{% if session.can_manage_users %}', $template);
        $this->assertStringContainsString('{% set _dead = dead_mail_count is not null and dead_mail_count > 0 %}', $template);
    }

    public function testDashboardControllerGatesProjectContextQueriesBehindTaskPermission(): void
    {
        $controller = file_get_contents(dirname(__DIR__) . '/../src/Controllers/DashboardController.php');

        $this->assertIsString($controller);
        $this->assertStringContainsString('if ($tasksModuleEnabled && $canManageTasks) {', $controller);
        $this->assertStringContainsString("(bool) (\$this->settings['modules']['tasks'] ?? false)", $controller);
    }

    /**
     * Das Recht auf Aufgaben hat eine Quelle: `TaskPolicy`. Das Dashboard las
     * denselben Sitzungsschlüssel zuvor selbst aus - gleiches Ergebnis, aber
     * zwei Stellen, die beim nächsten Eingriff auseinanderlaufen.
     */
    public function testDashboardControllerAsksThePolicyForTheTaskPermission(): void
    {
        $controller = file_get_contents(dirname(__DIR__) . '/../src/Controllers/DashboardController.php');

        $this->assertIsString($controller);
        $this->assertStringContainsString('$this->taskPolicy->canManageTasks()', $controller);
        $this->assertStringNotContainsString("\$_SESSION['can_manage_tasks']", $controller);
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

    /**
     * Farbe trägt Bedeutung (DESIGN.md): Navigationsknöpfe sind grau, Rot heißt Absage oder
     * Löschen. Das Dashboard hatte sechs Farbvarianten ohne Bedeutung und einen Verlauf.
     */
    public function testDashboardUsesNoColouredNavigationButtonsAndNoGradient(): void
    {
        $template = (string) file_get_contents(dirname(__DIR__) . '/../templates/dashboard/index.twig');
        $css = (string) file_get_contents(dirname(__DIR__) . '/../public/css/style.css');

        foreach (['success', 'danger', 'warning', 'info', 'dark', 'primary'] as $colour) {
            $this->assertStringNotContainsString('btn-outline-' . $colour, $template);
        }
        $this->assertStringNotContainsString('btn-danger', $template);
        $this->assertStringNotContainsString('dashboard-panel--critical', $css);
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function renderDashboardFor(array $settings, ?MailQueueAdminService $mail = null): string
    {
        $controller = new DashboardController(
            $this->createDashboardTwig($settings),
            $mail ?? new MailQueueAdminService(),
            new TaskPolicy($_SESSION),
            $settings
        );

        return (string) $controller->index($this->makeRequest('GET', '/dashboard'), $this->makeResponse())->getBody();
    }

    /**
     * @return array{0: User, 1: VoiceGroup}
     */
    private function createMemberInVoiceGroup(): array
    {
        $group = VoiceGroup::create(['name' => 'Dashboard-' . bin2hex(random_bytes(4))]);
        $user = $this->createUser();
        Bootstrap::getCapsule()?->table('user_voice_groups')->insert([
            'user_id' => $user->id,
            'voice_group_id' => $group->id,
        ]);

        return [$user, $group];
    }

    private function createGroupEvent(
        VoiceGroup $group,
        string $title,
        int $inDays,
        bool $registration = true
    ): Event {
        $event = Event::create([
            'title' => $title,
            'starts_at' => Carbon::now()->addDays($inDays)->setTime(19, 0),
            'ends_at' => Carbon::now()->addDays($inDays)->setTime(21, 0),
            'type' => 'Probe',
            'location' => 'Probenraum Süd',
            'registration_enabled' => $registration,
            'registration_deadline' => $registration ? Carbon::now()->addDays($inDays)->subHours(2) : null,
        ]);
        $this->giveAudience('event_id', (int) $event->id, ['voice_group' => [(int) $group->id]]);

        return $event;
    }

    /**
     * Die Startseite beantwortet "Was kommt als Nächstes?": die nächsten drei Termine, die das
     * Mitglied sehen darf - mit Zeit und Ort, und bei offener Anmeldung mit den Antwortknöpfen.
     */
    public function testNextEventsListsTheNextThreeVisibleEventsWithAnswerButtons(): void
    {
        [$user, $group] = $this->createMemberInVoiceGroup();
        $tag = bin2hex(random_bytes(3));
        $first = $this->createGroupEvent($group, "Erste Probe {$tag}", 2);
        $this->createGroupEvent($group, "Zweite Probe {$tag}", 3);
        $this->createGroupEvent($group, "Dritte Probe {$tag}", 4);
        $this->createGroupEvent($group, "Vierte Probe {$tag}", 5);
        $otherGroup = VoiceGroup::create(['name' => 'Fremd-' . bin2hex(random_bytes(4))]);
        $this->createGroupEvent($otherGroup, "Fremde Probe {$tag}", 1);

        $_SESSION = ['user_id' => (int) $user->id];
        $body = $this->renderDashboardFor(['modules' => ['registration' => true]]);

        $this->assertStringContainsString('Als Nächstes', $body);
        $this->assertStringContainsString("Erste Probe {$tag}", $body);
        $this->assertStringContainsString("Zweite Probe {$tag}", $body);
        $this->assertStringContainsString("Dritte Probe {$tag}", $body);
        $this->assertStringNotContainsString("Vierte Probe {$tag}", $body);
        $this->assertStringNotContainsString("Fremde Probe {$tag}", $body);
        $this->assertStringContainsString('19:00', $body);
        $this->assertStringContainsString('Probenraum Süd', $body);
        $this->assertStringContainsString('action="/registrations/' . $first->id . '"', $body);
        $this->assertStringContainsString('registration-self-form', $body);
        $this->assertStringContainsString('Zusage', $body);
        $this->assertStringContainsString('Absage', $body);
    }

    public function testNextEventsShowsOwnAnswerAndPendingCountAsOneSentence(): void
    {
        [$user, $group] = $this->createMemberInVoiceGroup();
        $tag = bin2hex(random_bytes(3));
        $answered = $this->createGroupEvent($group, "Beantwortet {$tag}", 2);
        $this->createGroupEvent($group, "Offen {$tag}", 3);
        EventRegistration::create([
            'event_id' => $answered->id,
            'user_id' => $user->id,
            'status' => EventRegistration::STATUS_YES,
            'updated_by' => $user->id,
        ]);

        $_SESSION = ['user_id' => (int) $user->id];
        $body = $this->renderDashboardFor(['modules' => ['registration' => true]]);

        $this->assertStringContainsString('Deine Antwort: Zusage', $body);
        $this->assertStringContainsString('Handlungsbedarf', $body);
        $this->assertMatchesRegularExpression('/\d+ Termine? wart(et|en) auf deine Antwort/', $body);
        $this->assertStringNotContainsString('Noch nicht eingetragen:', $body);
    }

    public function testNextEventsHasNoAnswerButtonsWithoutOpenRegistrationOrModule(): void
    {
        [$user, $group] = $this->createMemberInVoiceGroup();
        $tag = bin2hex(random_bytes(3));
        $this->createGroupEvent($group, "Ohne Anmeldung {$tag}", 2, false);

        $_SESSION = ['user_id' => (int) $user->id];
        $withModule = $this->renderDashboardFor(['modules' => ['registration' => true]]);
        $withoutModule = $this->renderDashboardFor(['modules' => ['registration' => false]]);

        foreach ([$withModule, $withoutModule] as $body) {
            $this->assertStringContainsString("Ohne Anmeldung {$tag}", $body);
            $this->assertStringNotContainsString('registration-self-form', $body);
        }
    }

    public function testPendingSentenceIsExactAndCarriesTheTotalForLiveUpdates(): void
    {
        [$user, $group] = $this->createMemberInVoiceGroup();
        $baseline = (new \App\Services\PendingRegistrationSummaryService())->forUser((int) $user->id);
        $this->createGroupEvent($group, 'Einziger offener Termin', 2);

        $_SESSION = ['user_id' => (int) $user->id];
        $body = $this->renderDashboardFor(['modules' => ['registration' => true]]);

        $expected = ($baseline['pending'] ?? 0) + 1;
        $label = $expected === 1 ? 'Termin wartet' : 'Termine warten';
        $this->assertStringContainsString("{$expected} {$label} auf deine Antwort.", $body);
        $this->assertStringContainsString('data-pending-total="' . $expected . '"', $body);
        $this->assertStringContainsString('data-pending-cards="1"', $body);
        $this->assertStringContainsString('/js/registrations.js', $body);
    }

    public function testRegistrationModuleOffShowsNoPendingSentenceAndNoRegistrationScript(): void
    {
        [$user, $group] = $this->createMemberInVoiceGroup();
        $this->createGroupEvent($group, 'Termin ohne Modul', 2);

        $_SESSION = ['user_id' => (int) $user->id];
        $body = $this->renderDashboardFor(['modules' => ['registration' => false]]);

        $this->assertStringNotContainsString('Handlungsbedarf', $body);
        $this->assertStringNotContainsString('/js/registrations.js', $body);
    }

    /**
     * Ein Termin, der gerade läuft, steht noch oben - in der Probe soll die Probe sichtbar sein.
     * Ein heute schon beendeter Termin ist keine Neuigkeit mehr.
     */
    public function testRunningEventIsListedAndFinishedEventIsNot(): void
    {
        [$user, $group] = $this->createMemberInVoiceGroup();
        $tag = bin2hex(random_bytes(3));
        $running = Event::create([
            'title' => "Laufende Probe {$tag}",
            'starts_at' => Carbon::now()->startOfDay(),
            'ends_at' => Carbon::now()->addHours(2),
            'type' => 'Probe',
        ]);
        $finished = Event::create([
            'title' => "Beendete Probe {$tag}",
            'starts_at' => Carbon::now()->subHours(3),
            'ends_at' => Carbon::now()->subHour(),
            'type' => 'Probe',
        ]);
        $this->giveAudience('event_id', (int) $running->id, ['voice_group' => [(int) $group->id]]);
        $this->giveAudience('event_id', (int) $finished->id, ['voice_group' => [(int) $group->id]]);

        $_SESSION = ['user_id' => (int) $user->id];
        $body = $this->renderDashboardFor(['modules' => []]);

        $this->assertStringContainsString("Laufende Probe {$tag}", $body);
        $this->assertStringNotContainsString("Beendete Probe {$tag}", $body);
    }

    /**
     * Die Anwesenheitsliste des heutigen Termins ist nur mit dem Recht für alle Stimmgruppen
     * ein sicheres Ziel; wer nur die eigene Stimmgruppe verwaltet, landet auf der Übersicht.
     */
    public function testAttendanceEntryLinksToTodaysEventOnlyWithTheAllRight(): void
    {
        [$user, $group] = $this->createMemberInVoiceGroup();
        $today = Event::create([
            'title' => 'Heutige Probe ' . bin2hex(random_bytes(3)),
            'starts_at' => Carbon::now()->startOfDay(),
            'ends_at' => Carbon::now()->addHours(2),
            'type' => 'Probe',
        ]);
        $this->giveAudience('event_id', (int) $today->id, ['voice_group' => [(int) $group->id]]);

        $_SESSION = ['user_id' => (int) $user->id, 'can_manage_attendance_all' => true];
        $all = $this->renderDashboardFor(['modules' => []]);
        $_SESSION = ['user_id' => (int) $user->id, 'can_manage_own_voice_group' => true];
        $own = $this->renderDashboardFor(['modules' => []]);

        $this->assertStringContainsString('Anwesenheit erfassen', $all);
        $this->assertStringContainsString('href="/attendance/' . $today->id . '"', $all);
        $this->assertStringContainsString('Heute: ' . $today->title, $all);
        $this->assertStringContainsString('href="/attendance"', $own);
        $this->assertStringNotContainsString('href="/attendance/' . $today->id . '"', $own);
    }

    /**
     * Die Aufgaben-Zeile hängt am Modul, am Recht und an einem Projekt - fehlt eines, erscheint
     * sie nicht, und die Seite hat keinen toten Verweis auf Aufgaben.
     */
    public function testTasksAreaNeedsModuleRightAndProject(): void
    {
        $tag = bin2hex(random_bytes(3));
        Project::create([
            'name' => "Laufendes Projekt {$tag}",
            'start_date' => Carbon::now()->subDay()->toDateString(),
            'end_date' => Carbon::now()->addDays(30)->toDateString(),
        ]);
        $user = (int) $this->createUser()->id;

        $_SESSION = ['user_id' => $user, 'can_manage_tasks' => true];
        $visible = $this->renderDashboardFor(['modules' => ['tasks' => true]]);
        $moduleOff = $this->renderDashboardFor(['modules' => ['tasks' => false]]);
        $_SESSION = ['user_id' => $user, 'can_manage_tasks' => false];
        $noRight = $this->renderDashboardFor(['modules' => ['tasks' => true]]);

        $this->assertStringContainsString("Laufendes Projekt: Laufendes Projekt {$tag}", $visible);
        $this->assertStringNotContainsString("Laufendes Projekt {$tag}", $moduleOff);
        $this->assertStringNotContainsString("Laufendes Projekt {$tag}", $noRight);
    }

    public function testMemberWithoutAnyRightSeesNoAreaSection(): void
    {
        $_SESSION = ['user_id' => (int) $this->createUser()->id];

        $body = $this->renderDashboardFor(['modules' => []]);

        $this->assertStringNotContainsString('Dein Bereich', $body);
        $this->assertStringNotContainsString('aktive Mitglieder', $body);
    }

    public function testNewsletterManagerSeesLatestSentNewsletterWithoutProjectAndDraftCount(): void
    {
        $manager = $this->createUser();
        Newsletter::create([
            'project_id' => null,
            'title' => 'Projektloser Rundbrief',
            'content_html' => '<p>Inhalt</p>',
            'status' => Newsletter::STATUS_SENT,
            'created_by' => $manager->id,
            'sent_at' => date('Y-m-d H:i:s'),
        ]);
        foreach ([1, 2] as $number) {
            Newsletter::create([
                'title' => "Entwurf {$number}",
                'content_html' => '<p>Inhalt</p>',
                'status' => Newsletter::STATUS_DRAFT,
                'created_by' => $manager->id,
            ]);
        }
        $drafts = Newsletter::where('status', Newsletter::STATUS_DRAFT)->count();

        $_SESSION = ['user_id' => (int) $manager->id, 'can_manage_newsletters' => true];
        $body = $this->renderDashboardFor(['modules' => ['newsletter' => true]]);

        $this->assertStringContainsString("{$drafts} Entwürfe warten auf den Versand.", $body);
        $this->assertStringContainsString('href="/newsletters"', $body);
        // Kein loses Trennzeichen, wenn der Newsletter zu keinem Projekt gehört.
        $this->assertDoesNotMatchRegularExpression('/am \d\d\.\d\d\.\d{4} \d\d:\d\d\s*·\s*<\/p>/', $body);
    }

    public function testDeadLettersAppearOnlyWhenThereAreSome(): void
    {
        $_SESSION = ['user_id' => (int) $this->createUser()->id, 'can_manage_mail_queue' => true];

        $none = new class extends MailQueueAdminService {
            public function countDeadLetters(): int
            {
                return 0;
            }
        };
        $two = new class extends MailQueueAdminService {
            public function countDeadLetters(): int
            {
                return 2;
            }
        };

        $quiet = $this->renderDashboardFor(['modules' => []], $none);
        $this->assertStringNotContainsString('nicht zugestellt', $quiet);
        $this->assertStringNotContainsString('dashboard-todo', $quiet);
        $this->assertStringNotContainsString('Dead-Letter', $quiet);

        $alarm = $this->renderDashboardFor(['modules' => []], $two);
        $this->assertStringContainsString('2 E-Mails nicht zugestellt', $alarm);
        $this->assertStringContainsString('dashboard-todo__item--danger', $alarm);
        $this->assertStringNotContainsString('Dead-Letter', $alarm);
    }

    /**
     * Newsletter lesen ist eine Kernaufgabe der Mitglieder: Wer ihn nicht verwalten darf,
     * sieht den neuesten Newsletter, der ihm selbst zugestellt wurde - und keinen fremden.
     */
    public function testMemberSeesLatestNewsletterFromOwnArchiveOnly(): void
    {
        $member = $this->createUser();
        $other = $this->createUser();
        $tag = bin2hex(random_bytes(3));
        $mine = Newsletter::create([
            'title' => "Mein Rundbrief {$tag}",
            'content_html' => '<p>Inhalt</p>',
            'status' => Newsletter::STATUS_SENT,
            'created_by' => $other->id,
            'sent_at' => date('Y-m-d H:i:s', time() - 7200),
        ]);
        $foreign = Newsletter::create([
            'title' => "Fremder Rundbrief {$tag}",
            'content_html' => '<p>Inhalt</p>',
            'status' => Newsletter::STATUS_SENT,
            'created_by' => $other->id,
            'sent_at' => date('Y-m-d H:i:s'),
        ]);
        NewsletterArchive::create([
            'newsletter_id' => $mine->id,
            'user_id' => $member->id,
            'email' => $member->email,
            'sent_at' => date('Y-m-d H:i:s', time() - 7200),
        ]);
        NewsletterArchive::create([
            'newsletter_id' => $foreign->id,
            'user_id' => $other->id,
            'email' => $other->email,
            'sent_at' => date('Y-m-d H:i:s'),
        ]);

        $_SESSION = ['user_id' => (int) $member->id, 'can_manage_newsletters' => false];
        $body = $this->renderDashboardFor(['modules' => ['newsletter' => true]]);

        $this->assertStringContainsString("Mein Rundbrief {$tag}", $body);
        $this->assertStringNotContainsString("Fremder Rundbrief {$tag}", $body);
        $this->assertStringContainsString('/newsletters/' . $mine->id . '/preview', $body);
    }

    public function testMemberWithoutNewsletterModuleSeesNoNewsletterAndNoEmptyCards(): void
    {
        $member = $this->createUser();
        $_SESSION = ['user_id' => (int) $member->id];

        $body = $this->renderDashboardFor(['modules' => ['newsletter' => false]]);

        $this->assertStringNotContainsString('Newsletter', $body);
        $this->assertStringNotContainsString('Kommunikationskarten', $body);
        $this->assertStringNotContainsString('dashboard-panel--empty', $body);
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
        $this->assertStringContainsString('registration_summary.pending', $template);
        $this->assertStringContainsString('auf deine Antwort', $template);
        $this->assertStringContainsString('href="/registrations"', $template);
    }

    private function storageService(int $bytes, bool $fails = false): StorageUsageService
    {
        $provider = new class ($bytes, $fails) implements StorageUsageProvider {
            public function __construct(private readonly int $bytes, private readonly bool $fails)
            {
            }

            public function key(): string
            {
                return 'database';
            }

            public function label(): string
            {
                return 'Datenbank';
            }

            public function usage(): StorageUsageNode
            {
                if ($this->fails) {
                    throw new \RuntimeException('kaputt');
                }

                return new StorageUsageNode('database', 'Datenbank', $this->bytes);
            }
        };

        $this->storageCache = sys_get_temp_dir() . '/dashboard-storage-' . bin2hex(random_bytes(6)) . '.json';

        return new StorageUsageService([$provider], $this->storageCache, new NullLogger());
    }

    private string $storageCache = '';

    private function renderDashboard(?StorageUsageService $storage): string
    {
        $settings = ['modules' => []];
        $controller = new DashboardController(
            $this->createDashboardTwig($settings),
            new MailQueueAdminService(),
            new TaskPolicy($_SESSION),
            $settings,
            $storage
        );

        try {
            return (string) $controller->index($this->makeRequest('GET', '/dashboard'), $this->makeResponse())->getBody();
        } finally {
            if ($this->storageCache !== '') {
                @unlink($this->storageCache);
            }
        }
    }

    public function testStorageTileShowsTotalAndLinkForTheStorageRight(): void
    {
        $_SESSION = ['user_id' => (int) $this->createUser()->id, 'can_manage_storage' => true];

        $body = $this->renderDashboard($this->storageService(5 * 1048576));

        $this->assertStringContainsString('Speicherplatz', $body);
        $this->assertStringContainsString('5,0 MB', $body);
        $this->assertStringContainsString('href="/storage"', $body);
        $this->assertStringContainsString('Stand:', $body);
    }

    public function testStorageTileIsHiddenWithoutTheRight(): void
    {
        $_SESSION = ['user_id' => (int) $this->createUser()->id, 'can_manage_storage' => false];

        $body = $this->renderDashboard($this->storageService(5 * 1048576));

        $this->assertStringNotContainsString('href="/storage"', $body);
    }

    public function testFailedStorageAreaShowsUnavailableButDashboardRenders(): void
    {
        $_SESSION = ['user_id' => (int) $this->createUser()->id, 'can_manage_storage' => true];

        $body = $this->renderDashboard($this->storageService(0, true));

        $this->assertStringContainsString('nicht ermittelbar', $body);
        $this->assertStringContainsString('Dein Bereich', $body);
    }

    public function testDashboardTemplateUsesNeutralCommunicationEmptyState(): void
    {
        $template = file_get_contents(dirname(__DIR__) . '/../templates/dashboard/index.twig');

        $this->assertIsString($template);
        $this->assertStringNotContainsString('Sobald Newsletter-Rechte oder Mail-Queue-Zugriff', $template);
        $this->assertStringNotContainsString('Keine Schnellzugriffe verfügbar', $template);
    }
}
