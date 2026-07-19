<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\AttendanceController;
use App\Controllers\EvaluationController;
use App\Models\Attendance;
use App\Models\Event;
use App\Models\Project;
use App\Models\User;
use App\Queries\ProjectQuery;
use App\Services\AttendanceScopeService;
use Carbon\Carbon;
use Dotenv\Dotenv;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Slim\Views\Twig;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * Real behavioral coverage for the `events.attendance_required` enforcement
 * introduced in Task 9: AttendanceController::show()/save() and
 * EvaluationController::index() must fully ignore events for which
 * attendance is not required — no writes must happen for them, they must
 * not appear in the attendance event picker, and they must not inflate the
 * evaluation totals or per-member attendance counts.
 */
class AttendanceRequiredFeatureTest extends TestCase
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

    public function testAttendanceSaveRejectedWhenNotRequiredWritesNoAttendanceRows(): void
    {
        $event = Event::create([
            'title' => 'Fest ohne Anwesenheitsliste',
            'starts_at' => Carbon::now()->addDays(3)->setTime(18, 0),
            'ends_at' => Carbon::now()->addDays(3)->setTime(23, 0),
            'type' => 'Sonstiges',
            'attendance_required' => false,
        ]);

        $_SESSION['user_id'] = 1;
        $_SESSION['can_manage_users'] = true;

        $controller = new AttendanceController($this->createTwig(), new AttendanceScopeService());

        $request = $this->makeRequest('POST', '/attendance/' . $event->id, [
            'attendance' => ['1' => 'present'],
        ]);
        $response = $controller->save($request, $this->makeResponse(), [
            'event_id' => (string) $event->id,
        ]);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(
            0,
            Attendance::where('event_id', $event->id)->count(),
            'save() must reject the request AND persist zero attendance rows when attendance_required is false'
        );
    }

    public function testAttendanceEventListExcludesNotRequiredEvents(): void
    {
        $requiredEvent = Event::create([
            'title' => 'Probe mit Anwesenheitspflicht Task9',
            'starts_at' => Carbon::now()->addDays(2)->setTime(19, 0),
            'ends_at' => Carbon::now()->addDays(2)->setTime(21, 0),
            'type' => 'Probe',
            'attendance_required' => true,
        ]);

        $notRequiredEvent = Event::create([
            'title' => 'Fest ohne Anwesenheitspflicht Task9',
            'starts_at' => Carbon::now()->addDays(4)->setTime(18, 0),
            'ends_at' => Carbon::now()->addDays(4)->setTime(23, 0),
            'type' => 'Sonstiges',
            'attendance_required' => false,
        ]);

        $_SESSION['user_id'] = 1;
        $_SESSION['can_manage_users'] = true;

        $controller = new AttendanceController($this->createTwig(), new AttendanceScopeService());

        $request = $this->makeRequest('GET', '/attendance/' . $requiredEvent->id);
        $response = $controller->show($request, $this->makeResponse(), [
            'event_id' => (string) $requiredEvent->id,
        ]);

        $body = (string) $response->getBody();

        $this->assertStringContainsString(
            $requiredEvent->title,
            $body,
            'the attendance_required=true event must appear in the event picker/current event'
        );
        $this->assertStringNotContainsString(
            $notRequiredEvent->title,
            $body,
            'the attendance_required=false event must never appear in the rendered attendance view'
        );
    }

    public function testEvaluationCountsOnlyRequiredEvents(): void
    {
        $project = Project::create([
            'name' => 'Auswertungs-Testprojekt Task9',
            'description' => 'Fixture project for attendance_required evaluation coverage',
        ]);

        $member = User::create([
            'first_name' => 'Anwesenheits',
            'last_name' => 'Testmitglied-Task9',
            'email' => 'attendance-required-task9@example.test',
            'password' => password_hash('test123', PASSWORD_DEFAULT),
            'is_active' => true,
        ]);

        self::$capsule?->table('project_users')->insert([
            'project_id' => $project->id,
            'user_id' => $member->id,
        ]);

        $requiredEvent = Event::create([
            'title' => 'Projektprobe mit Anwesenheitspflicht Task9',
            'project_id' => $project->id,
            'starts_at' => Carbon::now()->subDays(1)->setTime(19, 0),
            'ends_at' => Carbon::now()->subDays(1)->setTime(21, 0),
            'type' => 'Probe',
            'attendance_required' => true,
        ]);

        $notRequiredEvent = Event::create([
            'title' => 'Projektfest ohne Anwesenheitspflicht Task9',
            'project_id' => $project->id,
            'starts_at' => Carbon::now()->subDays(2)->setTime(18, 0),
            'ends_at' => Carbon::now()->subDays(2)->setTime(23, 0),
            'type' => 'Sonstiges',
            'attendance_required' => false,
        ]);

        Attendance::create([
            'event_id' => $requiredEvent->id,
            'user_id' => $member->id,
            'status' => 'present',
        ]);
        // Attendance row on the non-required event: if the controller ever
        // regressed and stopped filtering by attendance_required, this row
        // would double the present-count and push the percentage to 200%.
        Attendance::create([
            'event_id' => $notRequiredEvent->id,
            'user_id' => $member->id,
            'status' => 'present',
        ]);

        $_SESSION['user_id'] = $member->id;
        $_SESSION['can_manage_users'] = true;

        $controller = new EvaluationController($this->createTwig(), new ProjectQuery());

        $request = $this->makeRequest('GET', '/evaluations', [], ['project_id' => (string) $project->id]);
        $response = $controller->index($request, $this->makeResponse());

        $body = (string) $response->getBody();

        $this->assertStringContainsString(
            'Termine im Projekt: 1',
            $body,
            'totalEvents must count only the attendance_required=true event, not both project events'
        );

        [$presentCount, $percentage] = $this->extractMemberPresentAndPercentage($body, $member);

        $this->assertSame(
            1,
            $presentCount,
            'the attendance row from the non-required event must not be counted toward present_count'
        );
        $this->assertSame(
            100.0,
            $percentage,
            'percentage must be computed against totalEvents=1, not 2, and must not exceed 100%'
        );
    }

    /**
     * @return array{0: int, 1: float}
     */
    private function extractMemberPresentAndPercentage(string $body, User $member): array
    {
        $sortName = strtolower($member->last_name . ', ' . $member->first_name);
        $pattern = '/data-sort-name="' . preg_quote($sortName, '/') . '"[^>]*'
            . 'data-sort-present="(\d+)"[^>]*data-sort-percentage="([\d.]+)"/s';

        $this->assertMatchesRegularExpression($pattern, $body, 'evaluation row for member not found');
        preg_match($pattern, $body, $matches);

        return [(int) $matches[1], (float) $matches[2]];
    }

    private function createTwig(): Twig
    {
        $twig = new Twig(new FilesystemLoader(dirname(__DIR__, 2) . '/templates'));
        $environment = $twig->getEnvironment();
        $environment->addGlobal('session', $_SESSION);
        $environment->addGlobal('current_path', '/attendance');
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
