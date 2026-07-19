<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\EvaluationController;
use App\Models\Attendance;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Project;
use App\Models\User;
use App\Models\VoiceGroup;
use App\Queries\ProjectQuery;
use Carbon\Carbon;
use Dotenv\Dotenv;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Slim\Views\Twig;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * Real behavioral coverage for EvaluationController::registrations()
 * (Task 11): occupancy per voice group, response rate, and the past-event
 * attendance comparison, on top of a real test database.
 *
 * The eligibility population used for the response-rate denominator must be
 * IDENTICAL to the one already used by RegistrationController (active
 * users, restricted to project members for project-bound events) — a
 * divergent second definition here would reproduce the exact class of bug
 * fixed for RegistrationController::index() (a negative/over-100% counter
 * caused by numerator and denominator using different filters).
 */
class RegistrationEvaluationFeatureTest extends TestCase
{
    use TestHttpHelpers;

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

    public function testRegistrationEvaluationStructureExists(): void
    {
        $this->assertTrue(method_exists(EvaluationController::class, 'registrations'));
        $this->assertFileExists(dirname(__DIR__) . '/../templates/evaluations/registrations.twig');

        $routes = file_get_contents(dirname(__DIR__) . '/../src/Routes.php');
        $this->assertIsString($routes);
        $this->assertStringContainsString("'/evaluations/registrations'", $routes);

        $nav = file_get_contents(dirname(__DIR__) . '/../templates/partials/navigation/evaluations.twig');
        $this->assertIsString($nav);
        $this->assertStringContainsString('settings.modules.registration', $nav);
        $this->assertStringContainsString('href="/evaluations/registrations"', $nav);
    }

    public function testRegistrationEvaluationRouteIsFeatureGated(): void
    {
        $routes = file_get_contents(dirname(__DIR__) . '/../src/Routes.php');
        $this->assertIsString($routes);

        $gatePos = strpos($routes, "if (\$settings['modules']['registration'] ?? false) {");
        $this->assertNotFalse($gatePos);

        $routePos = strpos($routes, "'/evaluations/registrations'");
        $this->assertNotFalse($routePos);
        $this->assertGreaterThan($gatePos, $routePos);
    }

    public function testMatrixGroupsByVoiceGroupAndExcludesIneligibleRegistrationsFromNumeratorAndDenominator(): void
    {
        $fixture = $this->createUpcomingEventFixture();

        // Compute the exact voice-group column order the controller itself
        // will compute, inside the same transaction, so the assertions
        // below are independent of whatever voice groups already exist in
        // the (seeded) test database.
        $voiceGroupNames = VoiceGroup::orderBy('name')->pluck('name')->all();
        $voiceGroupNames[] = 'Ohne Stimmgruppe';
        $sopranIndex = array_search($fixture['sopran']->name, $voiceGroupNames, true);
        $altIndex = array_search($fixture['alt']->name, $voiceGroupNames, true);

        $controller = new EvaluationController($this->createTwig(), new ProjectQuery());
        $request = $this->makeRequest('GET', '/evaluations/registrations');
        $response = $controller->registrations($request, $this->makeResponse());
        $body = (string) $response->getBody();

        $cells = $this->extractRowCells($body, $fixture['event']->title, count($voiceGroupNames));

        // Sopran: one "yes" (yesUser1) and one "maybe" (maybeUser).
        $this->assertSame(1, (int) trim($cells[$sopranIndex]), 'Sopran yes-count mismatch');
        $this->assertStringContainsString('(1)', $cells[$sopranIndex], 'Sopran maybe-count must render as (1)');

        // Alt: one "yes" (yesUser2).
        $this->assertSame(1, (int) trim($cells[$altIndex]), 'Alt yes-count mismatch');

        $totalYesCell = (int) trim($cells[count($voiceGroupNames)]);
        $responseRateCell = trim($cells[count($voiceGroupNames) + 1]);

        // Pre-fix risk: if the numerator (answered/yes registrations) is
        // read from *all* registrations on the event while the denominator
        // (eligible users) is filtered to active project members, the
        // inactive user's and the removed-from-project user's "yes"
        // registrations would inflate total_yes to 4 and push the response
        // rate above 100%.
        $this->assertSame(2, $totalYesCell, 'total_yes must only count the two eligible "yes" registrations');
        $this->assertSame('75 %', $responseRateCell, 'response rate must be 3 answered / 4 eligible = 75%');

        $this->assertStringNotContainsString($fixture['inactive']->last_name, $body);
        $this->assertStringNotContainsString($fixture['nonMember']->last_name, $body);
    }

    public function testEveryRenderedRowCellsSumToTotalYesAndResponseRateNeverExceeds100(): void
    {
        $fixture = $this->createUpcomingEventFixture();

        $voiceGroupNames = VoiceGroup::orderBy('name')->pluck('name')->all();
        $voiceGroupNames[] = 'Ohne Stimmgruppe';

        $controller = new EvaluationController($this->createTwig(), new ProjectQuery());
        $request = $this->makeRequest('GET', '/evaluations/registrations');
        $response = $controller->registrations($request, $this->makeResponse());
        $body = (string) $response->getBody();

        $cells = $this->extractRowCells($body, $fixture['event']->title, count($voiceGroupNames));

        $sumOfCellYes = 0;
        for ($i = 0; $i < count($voiceGroupNames); $i++) {
            $sumOfCellYes += (int) trim($cells[$i]);
        }
        $totalYesCell = (int) trim($cells[count($voiceGroupNames)]);
        $responseRatePercent = (int) trim($cells[count($voiceGroupNames) + 1]);

        $this->assertSame($sumOfCellYes, $totalYesCell, 'sum of per-voice-group yes cells must equal total_yes');
        $this->assertLessThanOrEqual(100, $responseRatePercent, 'response rate must never exceed 100%');
        $this->assertGreaterThanOrEqual(0, $responseRatePercent, 'response rate must never be negative');
    }

    public function testIncludePastQueryParamTogglesPastEventsVisibility(): void
    {
        $pastEvent = Event::create([
            'title' => 'Vergangener Anmeldetermin Task11',
            'starts_at' => Carbon::now()->subDays(5),
            'ends_at' => Carbon::now()->subDays(5)->addHours(2),
            'type' => 'Probe',
            'registration_enabled' => true,
            'attendance_required' => false,
        ]);

        $controller = new EvaluationController($this->createTwig(), new ProjectQuery());

        $defaultResponse = $controller->registrations(
            $this->makeRequest('GET', '/evaluations/registrations'),
            $this->makeResponse()
        );
        $defaultBody = (string) $defaultResponse->getBody();
        $this->assertStringNotContainsString($pastEvent->title, $defaultBody);

        $includePastResponse = $controller->registrations(
            $this->makeRequest('GET', '/evaluations/registrations', [], ['include_past' => '1']),
            $this->makeResponse()
        );
        $includePastBody = (string) $includePastResponse->getBody();
        $this->assertStringContainsString($pastEvent->title, $includePastBody);
    }

    public function testAttendanceComparisonOnlyAppearsForPastAttendanceRequiredEvents(): void
    {
        $suffix = uniqid();
        $attendee1 = $this->createUser('reg-eval-att1-' . $suffix, 'Auswertung-Anwesend-Eins');
        $attendee2 = $this->createUser('reg-eval-att2-' . $suffix, 'Auswertung-Anwesend-Zwei');
        $attendee3 = $this->createUser('reg-eval-att3-' . $suffix, 'Auswertung-Entschuldigt');

        $pastRequired = Event::create([
            'title' => 'Vergangene Probe mit Anwesenheitspflicht Task11',
            'starts_at' => Carbon::now()->subDays(3),
            'ends_at' => Carbon::now()->subDays(3)->addHours(2),
            'type' => 'Probe',
            'registration_enabled' => true,
            'attendance_required' => true,
        ]);
        Attendance::create(['event_id' => $pastRequired->id, 'user_id' => $attendee1->id, 'status' => 'present']);
        Attendance::create(['event_id' => $pastRequired->id, 'user_id' => $attendee2->id, 'status' => 'present']);
        Attendance::create(['event_id' => $pastRequired->id, 'user_id' => $attendee3->id, 'status' => 'excused']);

        $pastNotRequired = Event::create([
            'title' => 'Vergangenes Fest ohne Anwesenheitspflicht Task11',
            'starts_at' => Carbon::now()->subDays(4),
            'ends_at' => Carbon::now()->subDays(4)->addHours(2),
            'type' => 'Sonstiges',
            'registration_enabled' => true,
            'attendance_required' => false,
        ]);

        $futureRequired = Event::create([
            'title' => 'Kommende Probe mit Anwesenheitspflicht Task11',
            'starts_at' => Carbon::now()->addDays(6),
            'ends_at' => Carbon::now()->addDays(6)->addHours(2),
            'type' => 'Probe',
            'registration_enabled' => true,
            'attendance_required' => true,
        ]);

        $controller = new EvaluationController($this->createTwig(), new ProjectQuery());
        $response = $controller->registrations(
            $this->makeRequest('GET', '/evaluations/registrations', [], ['include_past' => '1']),
            $this->makeResponse()
        );
        $body = (string) $response->getBody();

        $voiceGroupNames = VoiceGroup::orderBy('name')->pluck('name')->all();
        $voiceGroupNames[] = 'Ohne Stimmgruppe';
        $attendanceIndex = count($voiceGroupNames) + 2;

        $requiredCells = $this->extractRowCells($body, $pastRequired->title, count($voiceGroupNames));
        $this->assertSame(2, (int) trim($requiredCells[$attendanceIndex]), 'past+required event must show present count');

        $notRequiredCells = $this->extractRowCells($body, $pastNotRequired->title, count($voiceGroupNames));
        $this->assertStringContainsString('&mdash;', $notRequiredCells[$attendanceIndex], 'past but not-required event must show dash');

        $futureCells = $this->extractRowCells($body, $futureRequired->title, count($voiceGroupNames));
        $this->assertStringContainsString('&mdash;', $futureCells[$attendanceIndex], 'future event must never show attendance comparison');
    }

    /**
     * @return array{
     *     project: Project,
     *     event: Event,
     *     sopran: VoiceGroup,
     *     alt: VoiceGroup,
     *     yes1: User,
     *     yes2: User,
     *     maybeUser: User,
     *     openUser: User,
     *     inactive: User,
     *     nonMember: User
     * }
     */
    private function createUpcomingEventFixture(): array
    {
        $suffix = uniqid();

        $project = Project::create([
            'name' => 'Auswertungs-Testprojekt Task11 ' . $suffix,
            'description' => 'Fixture project for registration evaluation tests',
        ]);

        $sopran = VoiceGroup::create(['name' => 'Sopran Test11 ' . $suffix]);
        $alt = VoiceGroup::create(['name' => 'Alt Test11 ' . $suffix]);

        $yes1 = $this->createUser('reg-eval-yes1-' . $suffix, 'Auswertung-Zusage-Eins');
        $yes2 = $this->createUser('reg-eval-yes2-' . $suffix, 'Auswertung-Zusage-Zwei');
        $maybeUser = $this->createUser('reg-eval-maybe-' . $suffix, 'Auswertung-Vielleicht');
        $openUser = $this->createUser('reg-eval-open-' . $suffix, 'Auswertung-Offen');
        $inactive = $this->createUser('reg-eval-inactive-' . $suffix, 'Auswertung-Inaktiv', false);
        $nonMember = $this->createUser('reg-eval-nonmember-' . $suffix, 'Auswertung-Entfernt');

        foreach ([$yes1, $yes2, $maybeUser, $openUser, $inactive] as $member) {
            self::$capsule?->table('project_users')->insert([
                'project_id' => $project->id,
                'user_id' => $member->id,
            ]);
        }
        // $nonMember is deliberately NOT a project member: simulates a user
        // who registered while still a member and was later removed.

        self::$capsule?->table('user_voice_groups')->insert([
            ['user_id' => $yes1->id, 'voice_group_id' => $sopran->id],
            ['user_id' => $maybeUser->id, 'voice_group_id' => $sopran->id],
            ['user_id' => $yes2->id, 'voice_group_id' => $alt->id],
        ]);

        $event = Event::create([
            'title' => 'Projektprobe Auswertung Task11 ' . $suffix,
            'project_id' => $project->id,
            'starts_at' => Carbon::now()->addDays(10),
            'ends_at' => Carbon::now()->addDays(10)->addHours(2),
            'type' => 'Probe',
            'registration_enabled' => true,
            'attendance_required' => false,
        ]);

        EventRegistration::create([
            'event_id' => $event->id,
            'user_id' => $yes1->id,
            'status' => EventRegistration::STATUS_YES,
            'updated_by' => $yes1->id,
        ]);
        EventRegistration::create([
            'event_id' => $event->id,
            'user_id' => $yes2->id,
            'status' => EventRegistration::STATUS_YES,
            'updated_by' => $yes2->id,
        ]);
        EventRegistration::create([
            'event_id' => $event->id,
            'user_id' => $maybeUser->id,
            'status' => EventRegistration::STATUS_MAYBE,
            'updated_by' => $maybeUser->id,
        ]);
        // Ineligible registrations that must NOT be counted anywhere:
        EventRegistration::create([
            'event_id' => $event->id,
            'user_id' => $inactive->id,
            'status' => EventRegistration::STATUS_YES,
            'updated_by' => $inactive->id,
        ]);
        EventRegistration::create([
            'event_id' => $event->id,
            'user_id' => $nonMember->id,
            'status' => EventRegistration::STATUS_YES,
            'updated_by' => $nonMember->id,
        ]);

        return [
            'project' => $project,
            'event' => $event,
            'sopran' => $sopran,
            'alt' => $alt,
            'yes1' => $yes1,
            'yes2' => $yes2,
            'maybeUser' => $maybeUser,
            'openUser' => $openUser,
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
     * Extracts the inner HTML of every `<td class="text-center">` cell in
     * the matrix row for the given event title, in column order (voice
     * groups, then total_yes, then response_rate, then attendance
     * comparison).
     *
     * @return string[]
     */
    private function extractRowCells(string $body, string $eventTitle, int $voiceGroupColumnCount): array
    {
        $pattern = '#<tr>\s*<td>\s*<a href="/registrations/\d+"[^>]*>\s*'
            . preg_quote($eventTitle, '/') . '\s*</a>.*?</tr>#s';

        $this->assertMatchesRegularExpression($pattern, $body, 'row for event not found: ' . $eventTitle);
        preg_match($pattern, $body, $rowMatch);

        preg_match_all('#<td class="text-center[^"]*"[^>]*>(.*?)</td>#s', $rowMatch[0], $cellMatches);

        $this->assertCount(
            $voiceGroupColumnCount + 3,
            $cellMatches[1],
            'expected voice-group columns + total_yes + response_rate + attendance_comparison'
        );

        return $cellMatches[1];
    }

    private function createTwig(): Twig
    {
        $twig = new Twig(new FilesystemLoader(dirname(__DIR__, 2) . '/templates'));
        $environment = $twig->getEnvironment();
        $environment->addGlobal('session', $_SESSION);
        $environment->addGlobal('current_path', '/evaluations/registrations');
        $environment->addGlobal('app_settings', []);
        $environment->addFunction(new TwigFunction(
            'asset_path',
            static function (string $path): string {
                return $path;
            }
        ));
        $environment->addFunction(new TwigFunction(
            'nav_active',
            static function (
                string $path,
                ?string $activeNav = null,
                array $pathPrefixes = [],
                array $navKeys = [],
                array $excludePrefixes = []
            ): bool {
                foreach ($excludePrefixes as $excludePrefix) {
                    if ($excludePrefix !== '' && str_starts_with($path, $excludePrefix)) {
                        return false;
                    }
                }

                if ($activeNav !== null && $activeNav !== '' && in_array($activeNav, $navKeys, true)) {
                    return true;
                }

                foreach ($pathPrefixes as $prefix) {
                    if ($prefix === '/' && $path === '/') {
                        return true;
                    }

                    if ($prefix === '/') {
                        continue;
                    }

                    if ($prefix !== '' && str_starts_with($path, $prefix)) {
                        return true;
                    }
                }

                return false;
            }
        ));

        return $twig;
    }
}
