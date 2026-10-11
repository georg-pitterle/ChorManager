<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\EvaluationController;
use App\Models\Attendance;
use App\Models\Event;
use App\Models\Project;
use App\Models\User;
use App\Queries\ProjectQuery;
use App\Services\NameFormatterService;
use App\Util\PasswordHasher;
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
    use AudienceFixtures;
    use TestHttpHelpers;
    use TwigViewStubs;

    private EvaluationController $controller;
    /** @var array<int, array{0: string, 1: array<string, mixed>}> */
    private array $renderCalls = [];

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();
        // Der Controller liest die Sitzung schon im Konstruktor; einzeln
        // gestartet gäbe es sie sonst noch gar nicht.
        $_SESSION = [];

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
        //
        // Die auswertende Person wird wirklich angelegt. Vorher stand hier
        // `user_id => 0` - eine Abkürzung, die nur trug, solange
        // ProjectQuery::getAccessibleProjects() das übergreifende Recht vor der
        // Sitzungskennung prüfte und deshalb auch ohne Kennung alle Projekte
        // lieferte. Seit die Kennung zuerst geprüft wird (Antwort 2 aus dem
        // Review-Lauf 25), ist 0 genau das, was es immer war: keine Sitzung.
        $evaluator = User::create([
            'first_name' => 'Auswertung',
            'last_name' => 'Testperson ' . bin2hex(random_bytes(4)),
            'email' => 'quota-evaluator-' . bin2hex(random_bytes(6)) . '@example.test',
            'password' => PasswordHasher::hash('test123'),
            'is_active' => true,
        ]);

        $_SESSION = ['user_id' => (int) $evaluator->id, 'can_manage_attendance_all' => true];
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
            'password' => PasswordHasher::hash('test123'),
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
        $this->giveAudience('event_id', (int) $event->id, ['project' => [(int) $project->id]]);

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

    /**
     * Bezugsgroesse der Quote ist jeder stattgefundene Pflichttermin, nicht nur
     * der, fuer den jemand eine Liste gefuehrt hat. Wer bei zwei von vier Proben
     * da war, steht bei 50 % - auch wenn zwei Listen nie ausgefuellt wurden.
     * Waere die Erfassung die Basis, ergaeben dieselben zwei Haken 100 %.
     */
    public function testQuotaCountsEveryPastEventNotOnlyTheRecordedOnes(): void
    {
        $suffix = uniqid();
        ['project' => $project, 'member' => $member] = $this->projectWithOneMember($suffix);

        foreach ([12, 9] as $daysAgo) {
            $event = $this->projectEvent($project, Carbon::now()->subDays($daysAgo));
            Attendance::create([
                'event_id' => $event->id,
                'user_id' => $member->id,
                'status' => 'present',
            ]);
        }

        // Zwei weitere Proben ohne jede Erfassung.
        foreach ([6, 3] as $daysAgo) {
            $this->projectEvent($project, Carbon::now()->subDays($daysAgo));
        }

        $data = $this->renderIndex((int) $project->id);
        $stat = $this->statFor($data, (int) $member->id);

        $this->assertSame(4, $data['total_events']);
        $this->assertSame(2, $stat['total_recorded'], 'Erfasst wurden nur zwei Termine.');
        $this->assertSame(50.0, $stat['percentage'], 'Die Quote rechnet gegen alle vier Termine.');
    }

    /**
     * Ein Termin "Sopran UND Projekt" gehört zum Projekt, gilt aber nicht für
     * die Alt-Stimmen darin. Er darf ihre Quote nicht drücken.
     */
    public function testQuotaCountsOnlyEventsTheMemberBelongsTo(): void
    {
        $suffix = uniqid();
        ['project' => $project, 'member' => $alto] = $this->projectWithOneMember($suffix);
        $soprano = \App\Models\VoiceGroup::create(['name' => 'Quotensopran ' . $suffix]);

        $forAll = $this->projectEvent($project, Carbon::now()->subDays(4));
        Attendance::create(['event_id' => $forAll->id, 'user_id' => $alto->id, 'status' => 'present']);

        $sopranoOnly = Event::create([
            'title' => 'Stimmprobe Sopran ' . $suffix,
            'starts_at' => Carbon::now()->subDays(2),
            'ends_at' => Carbon::now()->subDays(2)->addHours(2),
            'type' => 'Probe',
            'registration_enabled' => false,
            'attendance_required' => true,
        ]);
        $this->giveAudience('event_id', (int) $sopranoOnly->id, [
            'voice_group' => [(int) $soprano->id],
            'project' => [(int) $project->id],
        ]);

        $stat = $this->statFor($this->renderIndex((int) $project->id), (int) $alto->id);

        $this->assertSame(1, $stat['total_events'] ?? null, 'Nur der Termin, der für die Alt-Stimme gilt, zählt.');
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
     * Die Frage vor dem Konzert lautet "Steht die Besetzung?" - je Stimmgruppe,
     * in der Reihenfolge, in der ein Chor seine Stimmen aufzählt, und wer ohne
     * Stimmgruppe ist, ganz hinten.
     */
    public function testVoiceGroupSummaryFollowsChoirOrderAndAveragesQuotas(): void
    {
        $suffix = uniqid();
        $project = Project::create(['name' => 'Quotenprojekt ' . $suffix]);
        // Kennungs-Reihenfolge ist die Chor-Reihenfolge: die zuerst angelegte
        // Gruppe steht vorn, auch wenn ihr Name alphabetisch hinten liegt.
        $high = \App\Models\VoiceGroup::create(['name' => 'Zhoch ' . $suffix]);
        $low = \App\Models\VoiceGroup::create(['name' => 'Atief ' . $suffix]);

        $full = $this->projectMember($project, 'voll-' . $suffix, (int) $low->id);
        $half = $this->projectMember($project, 'halb-' . $suffix, (int) $high->id);
        $never = $this->projectMember($project, 'nie-' . $suffix, (int) $high->id);
        $loose = $this->projectMember($project, 'ohne-' . $suffix, null);

        foreach ([8, 4] as $index => $daysAgo) {
            $event = $this->projectEvent($project, Carbon::now()->subDays($daysAgo));
            Attendance::create(['event_id' => $event->id, 'user_id' => $full->id, 'status' => 'present']);
            Attendance::create(['event_id' => $event->id, 'user_id' => $loose->id, 'status' => 'present']);
            Attendance::create([
                'event_id' => $event->id,
                'user_id' => $half->id,
                'status' => $index === 0 ? 'present' : 'excused',
            ]);
            Attendance::create(['event_id' => $event->id, 'user_id' => $never->id, 'status' => 'unexcused']);
        }

        $data = $this->renderIndex((int) $project->id);

        $this->assertSame(
            [
                ['name' => $high->name, 'member_count' => 2, 'average' => 25.0, 'below_low' => 1],
                ['name' => $low->name, 'member_count' => 1, 'average' => 100.0, 'below_low' => 0],
                ['name' => 'Ohne Stimmgruppe', 'member_count' => 1, 'average' => 100.0, 'below_low' => 0],
            ],
            $data['voice_group_summary'],
            'Genau 50 % liegt nicht unter der Schwelle.'
        );

        $this->assertLessThan(
            $this->statFor($data, (int) $full->id)['voice_group_rank'],
            $this->statFor($data, (int) $half->id)['voice_group_rank'],
            'Sortiert wird in Chor-Reihenfolge, nicht nach dem Alphabet.'
        );
        $this->assertGreaterThan(
            $this->statFor($data, (int) $full->id)['voice_group_rank'],
            $this->statFor($data, (int) $loose->id)['voice_group_rank'],
            'Ohne Stimmgruppe steht hinten.'
        );
    }

    /**
     * Gilt für jemanden keiner der stattgefundenen Termine, gibt es keine Quote -
     * eine rote 0 % würde ein Fehlen behaupten, das es nicht gab. Der
     * Stimmgruppen-Durchschnitt darf sie deshalb auch nicht mitrechnen.
     */
    public function testMemberWithoutApplicableEventHasNoQuota(): void
    {
        $suffix = uniqid();
        ['project' => $project, 'member' => $alto] = $this->projectWithOneMember($suffix);
        $soprano = \App\Models\VoiceGroup::create(['name' => 'Quotensopran ' . $suffix]);
        $singer = $this->projectMember($project, 'sopran-' . $suffix, (int) $soprano->id);

        $sopranoOnly = Event::create([
            'title' => 'Stimmprobe Sopran ' . $suffix,
            'starts_at' => Carbon::now()->subDays(2),
            'ends_at' => Carbon::now()->subDays(2)->addHours(2),
            'type' => 'Probe',
            'registration_enabled' => false,
            'attendance_required' => true,
        ]);
        $this->giveAudience('event_id', (int) $sopranoOnly->id, [
            'voice_group' => [(int) $soprano->id],
            'project' => [(int) $project->id],
        ]);
        Attendance::create(['event_id' => $sopranoOnly->id, 'user_id' => $singer->id, 'status' => 'present']);

        $data = $this->renderIndex((int) $project->id);

        $this->assertNull($this->statFor($data, (int) $alto->id)['percentage']);
        $this->assertSame(
            ['name' => 'Ohne Stimmgruppe', 'member_count' => 1, 'average' => null, 'below_low' => 0],
            end($data['voice_group_summary'])
        );
    }

    public function testEmptyReasonNamesMissingEvents(): void
    {
        $suffix = uniqid();
        ['project' => $project] = $this->projectWithOneMember($suffix);
        $this->projectEvent($project, Carbon::now()->addDays(5));

        $data = $this->renderIndex((int) $project->id);

        $this->assertSame('no_events', $data['empty_reason']);
        $this->assertSame([], $data['voice_group_summary']);
    }

    public function testEmptyReasonNamesMissingMembers(): void
    {
        $project = Project::create(['name' => 'Leeres Quotenprojekt ' . uniqid()]);
        $this->projectEvent($project, Carbon::now()->subDays(3));

        $data = $this->renderIndex((int) $project->id);

        $this->assertSame(1, $data['total_events']);
        $this->assertSame('no_members', $data['empty_reason']);
    }

    public function testEmptyReasonIsNullWhenThereIsSomethingToShow(): void
    {
        $suffix = uniqid();
        ['project' => $project] = $this->projectWithOneMember($suffix);
        $this->projectEvent($project, Carbon::now()->subDays(3));

        $this->assertNull($this->renderIndex((int) $project->id)['empty_reason']);
    }

    /**
     * Die Vorlage selbst: ohne geltenden Termin ein Strich statt "0,0 %", die
     * Zusammenfassung mit deutschem Zahlformat und der Leerzustand mit Grund.
     */
    public function testPageRendersDashForMissingQuotaAndNamesTheEmptyReason(): void
    {
        $nameFormatter = new NameFormatterService();
        $controller = new EvaluationController(
            $this->createAppTwig('/evaluations'),
            new ProjectQuery($nameFormatter),
            $nameFormatter
        );

        $suffix = uniqid();
        ['project' => $project, 'member' => $alto] = $this->projectWithOneMember($suffix);
        $soprano = \App\Models\VoiceGroup::create(['name' => 'Quotensopran ' . $suffix]);
        $singer = $this->projectMember($project, 'sopran-' . $suffix, (int) $soprano->id);
        $sopranoOnly = Event::create([
            'title' => 'Stimmprobe Sopran ' . $suffix,
            'starts_at' => Carbon::now()->subDays(2),
            'ends_at' => Carbon::now()->subDays(2)->addHours(2),
            'type' => 'Probe',
            'registration_enabled' => false,
            'attendance_required' => true,
        ]);
        $this->giveAudience('event_id', (int) $sopranoOnly->id, [
            'voice_group' => [(int) $soprano->id],
            'project' => [(int) $project->id],
        ]);
        Attendance::create(['event_id' => $sopranoOnly->id, 'user_id' => $singer->id, 'status' => 'present']);

        $body = (string) $controller->index(
            $this->makeRequest('GET', '/evaluations', [], ['project_id' => (string) $project->id]),
            $this->makeResponse()
        )->getBody();

        $this->assertStringContainsString('1 Pflichttermin bisher', $body);
        $this->assertStringContainsString('100,0&nbsp;%', $body, 'Quote im deutschen Zahlformat.');
        $this->assertStringContainsString('keine Quote, kein Termin galt', $body);
        $this->assertDoesNotMatchRegularExpression('/(?<!\d)0,0&nbsp;%/', $body, 'Ohne geltenden Termin keine 0 %.');
        $this->assertStringContainsString('Nach Stimmgruppe', $body);

        $emptyProject = Project::create(['name' => 'Leeres Quotenprojekt ' . $suffix]);
        $this->projectEvent($emptyProject, Carbon::now()->subDays(1));
        $emptyBody = (string) $controller->index(
            $this->makeRequest('GET', '/evaluations', [], ['project_id' => (string) $emptyProject->id]),
            $this->makeResponse()
        )->getBody();

        $this->assertStringContainsString('noch keine aktiven Mitglieder zugeordnet', $emptyBody);
        $this->assertStringNotContainsString('id="evaluationsTable"', $emptyBody);
    }

    private function projectMember(Project $project, string $suffix, ?int $voiceGroupId): User
    {
        $member = User::create([
            'first_name' => 'Quote',
            'last_name' => 'Mitglied ' . $suffix,
            'email' => 'quota-member-' . $suffix . '@example.test',
            'password' => PasswordHasher::hash('test123'),
            'is_active' => true,
        ]);
        Capsule::table('project_users')->insert(['project_id' => $project->id, 'user_id' => $member->id]);
        if ($voiceGroupId !== null) {
            Capsule::table('user_voice_groups')->insert(['user_id' => $member->id, 'voice_group_id' => $voiceGroupId]);
        }

        return $member;
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
