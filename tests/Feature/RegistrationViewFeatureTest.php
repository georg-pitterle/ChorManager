<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\RegistrationController;
use App\Models\Event;
use App\Models\EventAudienceSource;
use App\Models\EventRegistration;
use App\Models\Project;
use App\Models\User;
use App\Models\VoiceGroup;
use App\Navigation\NavigationBuilder;
use App\Navigation\NavigationContext;
use App\Services\AttendanceScopeService;
use Carbon\Carbon;
use Dotenv\Dotenv;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Views\Twig;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * Real behavioral coverage for RegistrationController::index()/detail() on
 * top of a real test database: correctness of the yes/no/maybe/open
 * counters against the eligible population (project members and active
 * users only), the voice-group grouping in detail(), and the
 * project-bound-event eligibility restriction.
 */
class RegistrationViewFeatureTest extends TestCase
{
    use TestHttpHelpers;
    use TwigViewStubs;

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
        $_SESSION = [];

        self::$capsule?->connection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $connection = self::$capsule?->connection();
        if ($connection !== null && $connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        $_SESSION = [];

        parent::tearDown();
    }

    public function testIndexRendersThreeSegmentedStatusButtonsForOpenRow(): void
    {
        $fixture = $this->createProjectBoundFixture();

        $_SESSION['user_id'] = (int) $fixture['maybe']->id;

        $controller = new RegistrationController($this->createTwig(), new AttendanceScopeService(), new NullLogger(), new \App\Services\NameFormatterService());
        $request = $this->makeRequest('GET', '/registrations');
        $response = $this->makeResponse();

        $result = $controller->index($request, $response);
        $body = (string) $result->getBody();

        // All three quick-action status buttons (incl. the newly added
        // "maybe") must post to the own-registration endpoint via a single
        // segmented btn-group form.
        $this->assertMatchesRegularExpression('/name="status"[^>]*value="yes"/s', $body);
        $this->assertMatchesRegularExpression('/name="status"[^>]*value="maybe"/s', $body);
        $this->assertMatchesRegularExpression('/name="status"[^>]*value="no"/s', $body);

        // The current user answered "maybe", so that button must be shown as
        // active (filled), the others as outline.
        $this->assertMatchesRegularExpression('/value="maybe"[^>]*class="btn btn-warning"/s', $body);
        $this->assertMatchesRegularExpression('/value="yes"[^>]*class="btn btn-outline-success"/s', $body);
    }

    public function testIndexOpenCounterMatchesEligiblePopulationAndNeverGoesNegative(): void
    {
        $fixture = $this->createProjectBoundFixture();

        $_SESSION['user_id'] = (int) $fixture['yes']->id;

        $controller = new RegistrationController($this->createTwig(), new AttendanceScopeService(), new NullLogger(), new \App\Services\NameFormatterService());
        $request = $this->makeRequest('GET', '/registrations');
        $response = $this->makeResponse();

        $result = $controller->index($request, $response);
        $body = (string) $result->getBody();

        $counts = $this->extractIndexCounts($body, (int) $fixture['event']->id);

        // Pre-fix, this would have been 4 - 3 - 1 - 1 = -1, because the
        // inactive project member's and the removed-from-project user's
        // "yes" registrations were counted toward yes_count via an
        // unfiltered withCount(), while eligible_count correctly excluded
        // them — producing a negative "Offen" badge.
        $this->assertSame(1, $counts['yes'], 'yes_count must only count active project members');
        $this->assertSame(1, $counts['no']);
        $this->assertSame(1, $counts['maybe']);
        $this->assertSame(1, $counts['open']);
        $this->assertGreaterThanOrEqual(0, $counts['open'], 'Offen counter must never go negative');

        $eligibleCount = $counts['yes'] + $counts['no'] + $counts['maybe'] + $counts['open'];
        $this->assertSame(4, $eligibleCount, 'yes+no+maybe+open must sum to the eligible count');
    }

    public function testDetailRendersProxyStatusAsSegmentedButtonsForManagers(): void
    {
        $fixture = $this->createProjectBoundFixture();

        // Manager who is not an eligible participant themselves, so every
        // rendered grid row is a proxy row (not the own-registration row).
        $manager = $this->createUser('reg-manager', 'Verwalter');
        $_SESSION['user_id'] = (int) $manager->id;
        $_SESSION['can_manage_users'] = true;

        $controller = new RegistrationController($this->createTwig(), new AttendanceScopeService(), new NullLogger(), new \App\Services\NameFormatterService());
        $request = $this->makeRequest('GET', '/registrations/' . $fixture['event']->id);
        $response = $this->makeResponse();

        $result = $controller->detail($request, $response, ['event_id' => (string) $fixture['event']->id]);
        $body = (string) $result->getBody();

        $yesId = (int) $fixture['yes']->id;

        // The old select-dropdown proxy control must be gone entirely.
        $this->assertStringNotContainsString('registration-proxy-select', $body);

        // Proxy rows now use the same segmented btn-check group as attendance,
        // posting the per-user proxy field, with the stored status pre-checked.
        $this->assertStringContainsString('name="registration[' . $yesId . ']"', $body);
        $this->assertStringContainsString('attendance-status-group', $body);
        $this->assertMatchesRegularExpression(
            '/id="reg_' . $yesId . '_yes"[^>]*checked/s',
            $body
        );
        $this->assertDoesNotMatchRegularExpression(
            '/id="reg_' . $yesId . '_no"[^>]*checked/s',
            $body
        );
    }

    public function testDetailRendersVoiceGroupsCountsAndOwnRegistration(): void
    {
        $fixture = $this->createProjectBoundFixture();

        $voiceGroup = VoiceGroup::create(['name' => 'Testalt Anmeldung']);
        self::$capsule?->table('user_voice_groups')->insert([
            'user_id' => $fixture['yes']->id,
            'voice_group_id' => $voiceGroup->id,
        ]);

        $_SESSION['user_id'] = (int) $fixture['yes']->id;
        $_SESSION['can_manage_users'] = false;

        $controller = new RegistrationController($this->createTwig(), new AttendanceScopeService(), new NullLogger(), new \App\Services\NameFormatterService());
        $request = $this->makeRequest('GET', '/registrations/' . $fixture['event']->id);
        $response = $this->makeResponse();

        $result = $controller->detail($request, $response, ['event_id' => (string) $fixture['event']->id]);
        $body = (string) $result->getBody();

        $this->assertStringContainsString($fixture['event']->title, $body);
        $this->assertStringContainsString('Testalt Anmeldung', $body);
        $this->assertStringContainsString('Ohne Stimmgruppe', $body);

        $this->assertStringContainsString('1 Zusagen', $body);
        $this->assertStringContainsString('1 Absagen', $body);
        $this->assertStringContainsString('1 Vielleicht', $body);
        $this->assertStringContainsString('1 Offen', $body);
        $this->assertStringContainsString('Rücklauf: 3 von 4 (75 %)', $body);

        // The excluded (inactive / removed-from-project) users must not
        // leak into the voice-group listing.
        $this->assertStringNotContainsString($fixture['inactive']->last_name, $body);
        $this->assertStringNotContainsString($fixture['nonMember']->last_name, $body);

        // Own registration (status "yes") must be pre-selected, the other
        // two radio options must not be.
        $this->assertMatchesRegularExpression('/id="status-yes"[^>]*checked/s', $body);
        $this->assertDoesNotMatchRegularExpression('/id="status-no"[^>]*checked/s', $body);
        $this->assertDoesNotMatchRegularExpression('/id="status-maybe"[^>]*checked/s', $body);
    }

    public function testProjectBoundEventRestrictsEligibleUsersToProjectMembers(): void
    {
        $fixture = $this->createProjectBoundFixture();

        $generalEvent = Event::create([
            'title' => 'Allgemeiner Anmeldetermin',
            'starts_at' => Carbon::now()->addDays(9),
            'ends_at' => Carbon::now()->addDays(9)->addHours(2),
            'type' => 'Probe',
            'registration_enabled' => true,
        ]);

        $_SESSION['user_id'] = (int) $fixture['yes']->id;
        $_SESSION['can_manage_users'] = false;

        $controller = new RegistrationController($this->createTwig(), new AttendanceScopeService(), new NullLogger(), new \App\Services\NameFormatterService());

        $projectEventResult = $controller->detail(
            $this->makeRequest('GET', '/registrations/' . $fixture['event']->id),
            $this->makeResponse(),
            ['event_id' => (string) $fixture['event']->id]
        );
        $projectEventBody = (string) $projectEventResult->getBody();

        $generalEventResult = $controller->detail(
            $this->makeRequest('GET', '/registrations/' . $generalEvent->id),
            $this->makeResponse(),
            ['event_id' => (string) $generalEvent->id]
        );
        $generalEventBody = (string) $generalEventResult->getBody();

        $this->assertStringContainsString('Rücklauf: 3 von 4 (75 %)', $projectEventBody);

        $activeUserCount = User::where('is_active', 1)->count();
        $this->assertGreaterThan(4, $activeUserCount, 'test expects more active users than the project has members');
        $this->assertStringContainsString(
            'Rücklauf: 0 von ' . $activeUserCount . ' (0 %)',
            $generalEventBody
        );

        // The user removed from the project (still active, but not a
        // member) must show up as eligible for the project-unbound event.
        $this->assertStringContainsString($fixture['nonMember']->last_name, $generalEventBody);
        $this->assertStringNotContainsString($fixture['nonMember']->last_name, $projectEventBody);
    }

    /**
     * @return array{
     *     project: Project,
     *     event: Event,
     *     yes: User,
     *     no: User,
     *     maybe: User,
     *     open: User,
     *     inactive: User,
     *     nonMember: User
     * }
     */
    private function createProjectBoundFixture(): array
    {
        $project = Project::create([
            'name' => 'Registrierungs-Testprojekt',
            'description' => 'Fixture project for RegistrationController tests',
        ]);

        $yes = $this->createUser('reg-yes', 'Antwortete-Zusage');
        $no = $this->createUser('reg-no', 'Antwortete-Absage');
        $maybe = $this->createUser('reg-maybe', 'Antwortete-Vielleicht');
        $open = $this->createUser('reg-open', 'Antwortete-Nicht');
        $inactive = $this->createUser('reg-inactive', 'Inaktives-Mitglied', false);
        $nonMember = $this->createUser('reg-nonmember', 'Entferntes-Mitglied');

        foreach ([$yes, $no, $maybe, $open, $inactive] as $member) {
            self::$capsule?->table('project_users')->insert([
                'project_id' => $project->id,
                'user_id' => $member->id,
            ]);
        }
        // $nonMember is deliberately NOT added to project_users: it
        // simulates a user who registered while still a project member
        // and was later removed from the project.

        $event = Event::create([
            'title' => 'Projektprobe mit Anmeldung',
            'project_id' => $project->id,
            'starts_at' => Carbon::now()->addDays(10),
            'ends_at' => Carbon::now()->addDays(10)->addHours(2),
            'type' => 'Probe',
            'registration_enabled' => true,
        ]);

        EventAudienceSource::create([
            'event_id' => $event->id,
            'source_type' => EventAudienceSource::TYPE_PROJECT_MEMBERS,
            'reference_id' => (int) $project->id,
        ]);

        EventRegistration::create([
            'event_id' => $event->id,
            'user_id' => $yes->id,
            'status' => EventRegistration::STATUS_YES,
            'updated_by' => $yes->id,
        ]);
        EventRegistration::create([
            'event_id' => $event->id,
            'user_id' => $no->id,
            'status' => EventRegistration::STATUS_NO,
            'updated_by' => $no->id,
        ]);
        EventRegistration::create([
            'event_id' => $event->id,
            'user_id' => $maybe->id,
            'status' => EventRegistration::STATUS_MAYBE,
            'updated_by' => $maybe->id,
        ]);
        // Registration from a user who is no longer eligible: became
        // inactive after registering.
        EventRegistration::create([
            'event_id' => $event->id,
            'user_id' => $inactive->id,
            'status' => EventRegistration::STATUS_YES,
            'updated_by' => $inactive->id,
        ]);
        // Registration from a user who is no longer eligible: removed
        // from the project after registering.
        EventRegistration::create([
            'event_id' => $event->id,
            'user_id' => $nonMember->id,
            'status' => EventRegistration::STATUS_YES,
            'updated_by' => $nonMember->id,
        ]);

        return [
            'project' => $project,
            'event' => $event,
            'yes' => $yes,
            'no' => $no,
            'maybe' => $maybe,
            'open' => $open,
            'inactive' => $inactive,
            'nonMember' => $nonMember,
        ];
    }

    private function createUser(string $emailSuffix, string $lastName, bool $isActive = true): User
    {
        return User::create([
            'first_name' => 'Reg',
            'last_name' => $lastName,
            'email' => $emailSuffix . '@example.test',
            'password' => password_hash('test123', PASSWORD_DEFAULT),
            'is_active' => $isActive,
        ]);
    }

    /**
     * @return array{yes: int, no: int, maybe: int, open: int}
     */
    private function extractIndexCounts(string $body, int $eventId): array
    {
        $pattern = '/href="\/registrations\/' . $eventId . '".*?'
            . 'registration-badge-yes"><span data-count="yes">(\d+)<\/span> Zusagen<\/span>\s*'
            . '<span class="badge registration-badge-no"><span data-count="no">(\d+)<\/span> Absagen<\/span>\s*'
            . '<span class="badge registration-badge-maybe"><span data-count="maybe">(\d+)<\/span> Vielleicht<\/span>\s*'
            . '<span class="badge registration-badge-open"><span data-count="open">(\d+)<\/span> Offen<\/span>/s';

        $this->assertMatchesRegularExpression($pattern, $body, 'registration card for event not found');
        preg_match($pattern, $body, $matches);

        return [
            'yes' => (int) $matches[1],
            'no' => (int) $matches[2],
            'maybe' => (int) $matches[3],
            'open' => (int) $matches[4],
        ];
    }

    private function createTwig(): Twig
    {
        $twig = new Twig(new FilesystemLoader(dirname(__DIR__, 2) . '/templates'));
        $environment = $twig->getEnvironment();
        $environment->addFilter(new \Twig\TwigFilter(
            'person_name',
            static fn (mixed $person): string => (new \App\Services\NameFormatterService())->formatPerson($person)
        ));
        $environment->addGlobal('session', $_SESSION);
        $this->registerMailBadgeStub($environment);
        $environment->addGlobal('current_path', '/registrations');
        $environment->addGlobal('app_settings', []);
        $environment->addFunction(new TwigFunction(
            'asset_path',
            static function (string $path): string {
                return $path;
            }
        ));
        $environment->addFunction(new TwigFunction(
            'navigation',
            static function (string $activeNav = ''): array {
                $context = NavigationContext::fromSession($_SESSION, [], '/registrations', $activeNav);

                return (new NavigationBuilder())->build($context);
            }
        ));

        return $twig;
    }
}
