<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\EvaluationController;
use App\Models\Attendance;
use App\Models\Event;
use App\Models\EventAudienceSource;
use App\Models\Project;
use App\Models\User;
use App\Queries\ProjectQuery;
use App\Services\NameFormatterService;
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * Die Anwesenheitsquote darf nur gegen bereits stattgefundene Termine rechnen.
 *
 * Vorher zählte der Nenner alle Termine des Projekts, auch die geplanten: Wer
 * bei allen drei bisherigen Proben da war, stand bei 40 angelegten Terminen mit
 * 7,5 % rot in der Liste - und zwar bis zum Projektende.
 */
final class EvaluationAttendanceQuotaFeatureTest extends TestCase
{
    use TestHttpHelpers;

    private EvaluationController $controller;
    /** @var array<int, array{0: string, 1: array<string, mixed>}> */
    private array $renderCalls = [];

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();

        $this->renderCalls = [];
        $twig = $this->createStub(Twig::class);
        $twig->method('render')->willReturnCallback(
            function (ResponseInterface $response, string $template, array $data = []): ResponseInterface {
                $this->renderCalls[] = [$template, $data];
                return $response;
            }
        );

        $nameFormatter = new NameFormatterService();
        $this->controller = new EvaluationController(
            $twig,
            new ProjectQuery($nameFormatter),
            $nameFormatter
        );

        // Volle Sicht auf alle Projekte: hier geht es um die Quotenberechnung,
        // nicht um die Projektsichtbarkeit.
        $_SESSION = ['user_id' => 0, 'can_manage_attendance_all' => true];
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

    /**
     * @return array{project: Project, member: User}
     */
    private function projectWithOneMember(string $suffix): array
    {
        $project = Project::create(['name' => 'Quotenprojekt ' . $suffix]);
        $member = User::create([
            'first_name' => 'Quote',
            'last_name' => 'Testperson ' . $suffix,
            'email' => 'quota-' . $suffix . '@example.test',
            'password' => password_hash('test123', PASSWORD_DEFAULT),
            'is_active' => true,
        ]);
        Capsule::table('project_users')->insert([
            'project_id' => $project->id,
            'user_id' => $member->id,
        ]);

        return ['project' => $project, 'member' => $member];
    }

    private function projectEvent(Project $project, Carbon $startsAt, bool $attendanceRequired = true): Event
    {
        $event = Event::create([
            'title' => 'Probe ' . $startsAt->format('d.m.Y H:i'),
            'starts_at' => $startsAt,
            'ends_at' => (clone $startsAt)->addHours(2),
            'type' => 'Probe',
            'registration_enabled' => false,
            'attendance_required' => $attendanceRequired,
        ]);
        EventAudienceSource::create([
            'event_id' => $event->id,
            'source_type' => EventAudienceSource::TYPE_PROJECT_MEMBERS,
            'reference_id' => (int) $project->id,
        ]);

        return $event;
    }

    /** @return array<string, mixed> */
    private function renderIndex(int $projectId): array
    {
        $this->renderCalls = [];
        $this->controller->index(
            $this->makeRequest('GET', '/evaluations', [], ['project_id' => (string) $projectId]),
            $this->makeResponse()
        );

        return end($this->renderCalls)[1];
    }

    public function testQuotaIgnoresEventsThatHaveNotHappenedYet(): void
    {
        $suffix = uniqid();
        ['project' => $project, 'member' => $member] = $this->projectWithOneMember($suffix);

        foreach ([9, 6, 3] as $daysAgo) {
            $event = $this->projectEvent($project, Carbon::now()->subDays($daysAgo));
            Attendance::create([
                'event_id' => $event->id,
                'user_id' => $member->id,
                'status' => 'present',
            ]);
        }

        // Die noch geplanten Termine dürfen den Nenner nicht aufblähen.
        foreach ([3, 10, 17, 24, 31] as $daysAhead) {
            $this->projectEvent($project, Carbon::now()->addDays($daysAhead));
        }

        $data = $this->renderIndex((int) $project->id);

        $this->assertSame(3, $data['total_events'], 'Nenner darf nur vergangene Pflichttermine zählen.');

        $stat = $this->statFor($data, (int) $member->id);
        $this->assertSame(3, $stat['present_count']);
        $this->assertSame(100.0, $stat['percentage'], 'Drei von drei stattgefundenen Proben sind 100 %.');
    }

    public function testAttendanceRecordedForAFutureEventDoesNotInflateTheCounters(): void
    {
        $suffix = uniqid();
        ['project' => $project, 'member' => $member] = $this->projectWithOneMember($suffix);

        $past = $this->projectEvent($project, Carbon::now()->subDays(2));
        Attendance::create(['event_id' => $past->id, 'user_id' => $member->id, 'status' => 'present']);

        // Vorab eingetragene Anwesenheit für einen künftigen Termin: zählt der
        // Zähler sie mit, während der Nenner sie ausschließt, käme 200 % heraus.
        $future = $this->projectEvent($project, Carbon::now()->addDays(2));
        Attendance::create(['event_id' => $future->id, 'user_id' => $member->id, 'status' => 'present']);

        $data = $this->renderIndex((int) $project->id);

        $this->assertSame(1, $data['total_events']);

        $stat = $this->statFor($data, (int) $member->id);
        $this->assertSame(1, $stat['present_count']);
        $this->assertSame(1, $stat['total_recorded']);
        $this->assertSame(100.0, $stat['percentage']);
    }

    public function testQuotaStaysZeroWhileNoEventHasHappenedYet(): void
    {
        $suffix = uniqid();
        ['project' => $project] = $this->projectWithOneMember($suffix);
        $this->projectEvent($project, Carbon::now()->addDays(5));

        $data = $this->renderIndex((int) $project->id);

        $this->assertSame(0, $data['total_events']);
        $this->assertSame([], $data['stats'], 'Ohne stattgefundene Termine gibt es nichts auszuwerten.');
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function statFor(array $data, int $userId): array
    {
        foreach ($data['stats'] as $stat) {
            if ((int) $stat['user_id'] === $userId) {
                return $stat;
            }
        }

        $this->fail('Kein Auswertungseintrag für Mitglied ' . $userId . ' gefunden.');
    }
}
