<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\EvaluationController;
use App\Models\Attendance;
use App\Models\Event;
use App\Models\Project;
use App\Models\User;
use App\Models\VoiceGroup;
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
 * Anwesenheitsquoten als Ansporn statt als Pranger.
 *
 * Alle Mitglieder sehen die Seite. Was andere Personen betrifft, erscheint für
 * sie nur im Positiven ("Immer dabei", Stimmgruppen-Vergleich); der eigene
 * Stand bleibt privat. Die vollständige namentliche Tabelle mit
 * Unentschuldigt-Zählern bekommt nur, wer Anwesenheit verwaltet - und zwar im
 * Umfang dieses Rechts. Ausgeblendet wird auf dem Server: Was das Mitglied
 * nicht sehen soll, steht gar nicht erst in den Vorlagedaten.
 */
final class EvaluationMemberViewFeatureTest extends TestCase
{
    use AudienceFixtures;
    use TestHttpHelpers;
    use TwigViewStubs;

    /** @var array<int, array{0: string, 1: array<string, mixed>}> */
    private array $renderCalls = [];
    private string $suffix;
    private Project $project;
    private VoiceGroup $soprano;
    private VoiceGroup $alto;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();
        $_SESSION = [];

        $this->suffix = bin2hex(random_bytes(4));
        $this->project = Project::create(['name' => 'Ansporn ' . $this->suffix, 'start_date' => '2026-09-01']);
        $this->soprano = VoiceGroup::create(['name' => 'Ansporn-Sopran ' . $this->suffix]);
        $this->alto = VoiceGroup::create(['name' => 'Ansporn-Alt ' . $this->suffix]);
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

    private function member(string $lastName, ?VoiceGroup $group, ?Project $project = null): User
    {
        $user = User::create([
            'first_name' => 'Ansporn',
            'last_name' => $lastName . ' ' . $this->suffix,
            'email' => 'ansporn-' . strtolower($lastName) . '-' . $this->suffix . '@example.test',
            'password' => PasswordHasher::hash('test123'),
            'is_active' => true,
        ]);
        Capsule::table('project_users')->insert([
            'project_id' => ($project ?? $this->project)->id,
            'user_id' => $user->id,
        ]);
        if ($group !== null) {
            Capsule::table('user_voice_groups')->insert(['user_id' => $user->id, 'voice_group_id' => $group->id]);
        }

        return $user;
    }

    /**
     * Legt vergangene Pflichttermine an, ältester zuerst, und trägt je Person
     * die Status in derselben Reihenfolge ein. null heißt: keine Erfassung.
     *
     * @param array<int, list<?string>> $statusesByUser
     */
    private function events(int $count, array $statusesByUser, ?Project $project = null): void
    {
        $project ??= $this->project;
        for ($i = 0; $i < $count; $i++) {
            $startsAt = Carbon::now()->subDays(($count - $i) * 7);
            $event = Event::create([
                'title' => 'Ansporn-Probe ' . $i,
                'starts_at' => $startsAt,
                'ends_at' => (clone $startsAt)->addHours(2),
                'type' => 'Probe',
                'registration_enabled' => false,
                'attendance_required' => true,
            ]);
            $this->giveAudience('event_id', (int) $event->id, ['project' => [(int) $project->id]]);

            foreach ($statusesByUser as $userId => $statuses) {
                $status = $statuses[$i] ?? null;
                if ($status !== null) {
                    Attendance::create(['event_id' => $event->id, 'user_id' => $userId, 'status' => $status]);
                }
            }
        }
    }

    private function controller(?Twig $twig = null): EvaluationController
    {
        if ($twig === null) {
            $twig = $this->createStub(Twig::class);
            $twig->method('render')->willReturnCallback(
                function (ResponseInterface $response, string $template, array $data = []): ResponseInterface {
                    $this->renderCalls[] = [$template, $data];
                    return $response;
                }
            );
        }

        $nameFormatter = new NameFormatterService();

        return new EvaluationController($twig, new ProjectQuery($nameFormatter), $nameFormatter);
    }

    /** @return array<string, mixed> */
    private function renderAs(array $session, ?Project $project = null): array
    {
        $_SESSION = $session;
        $this->renderCalls = [];
        $this->controller()->index(
            $this->makeRequest('GET', '/evaluations', [], ['project_id' => (string) ($project ?? $this->project)->id]),
            $this->makeResponse()
        );

        return end($this->renderCalls)[1];
    }

    /** @param list<array<string, mixed>> $rows */
    private function userIds(array $rows): array
    {
        return array_map(static fn (array $row): int => (int) $row['user_id'], $rows);
    }

    public function testPlainMemberGetsNoNamedDetailsOfOthers(): void
    {
        $self = $this->member('Selbst', $this->soprano);
        $other = $this->member('Andere', $this->alto);
        $this->events(4, [
            $self->id => ['present', 'present', 'excused', 'present'],
            $other->id => ['unexcused', 'unexcused', 'present', 'unexcused'],
        ]);

        $data = $this->renderAs(['user_id' => (int) $self->id]);

        $this->assertFalse($data['can_view_details']);
        $this->assertSame([], $data['stats'], 'Ohne Verwaltungsrecht keine namentliche Tabelle.');
        $this->assertSame([], $data['voice_group_summary'], 'Der Zähler "unter X %" gehört zur Verwaltung.');
        $this->assertNull($data['empty_reason'], 'Die Seite hat trotzdem etwas zu zeigen.');
        $this->assertSame([], $data['always_there'], 'Niemand liegt bei 90 % oder darüber.');
    }

    public function testOwnStandingCarriesQuotaReliabilityAndStreak(): void
    {
        $self = $this->member('Selbst', $this->soprano);
        // Ältester zuerst: Die Serie zählt vom jüngsten Termin rückwärts.
        $this->events(6, [$self->id => ['present', 'excused', null, 'present', 'present', 'present']]);

        $own = $this->renderAs(['user_id' => (int) $self->id])['own_standing'];

        $this->assertSame(4, $own['present_count']);
        $this->assertSame(1, $own['excused_count']);
        $this->assertSame(6, $own['total_events']);
        $this->assertSame(66.7, $own['percentage']);
        $this->assertSame(83.3, $own['reliability'], 'Verlässlich heißt: anwesend oder entschuldigt.');
        $this->assertSame(3, $own['streak'], 'Drei jüngste Termine in Folge dabei; der Termin ohne Erfassung bricht die Serie.');
        $this->assertFalse($own['is_always_there']);
        // (4 + n) / (6 + n) >= 90 % ergibt n = 14.
        $this->assertSame(14, $own['events_to_always_there']);
        $this->assertSame($this->soprano->name, $own['voice_group']);
    }

    public function testOwnStandingIsNullForPersonOutsideTheProject(): void
    {
        $member = $this->member('Mitglied', $this->soprano);
        $this->events(3, [$member->id => ['present', 'present', 'present']]);
        $outsider = User::create([
            'first_name' => 'Vorstand',
            'last_name' => 'Außen ' . $this->suffix,
            'email' => 'ansporn-outside-' . $this->suffix . '@example.test',
            'password' => PasswordHasher::hash('test123'),
            'is_active' => true,
        ]);

        $data = $this->renderAs(['user_id' => (int) $outsider->id, 'can_manage_attendance_all' => true]);

        $this->assertNull($data['own_standing']);
        $this->assertTrue($data['can_view_details']);
        $this->assertSame([(int) $member->id], $this->userIds($data['stats']));
    }

    public function testAlwaysThereListsOnlyHighAttendanceAlphabeticallyFromTheThirdEvent(): void
    {
        $zeta = $this->member('Zeta', $this->soprano);
        $alpha = $this->member('Alpha', $this->alto);
        $low = $this->member('Mitte', $this->alto);

        $young = Project::create(['name' => 'Junges Projekt ' . $this->suffix]);
        Capsule::table('project_users')->insert([
            ['project_id' => $young->id, 'user_id' => $zeta->id],
            ['project_id' => $young->id, 'user_id' => $low->id],
        ]);
        $this->events(2, [$zeta->id => ['present', 'present']], $young);
        $early = $this->renderAs(['user_id' => (int) $low->id], $young);
        $this->assertFalse($early['always_there_ready'], 'Nach zwei Terminen stünden sonst alle drin.');
        $this->assertSame([], $early['always_there']);

        $this->events(10, [
            $zeta->id => array_fill(0, 10, 'present'),
            $alpha->id => array_merge(array_fill(0, 9, 'present'), ['excused']),
            $low->id => array_merge(array_fill(0, 8, 'present'), ['unexcused', 'unexcused']),
        ]);

        $data = $this->renderAs(['user_id' => (int) $low->id]);

        $this->assertTrue($data['always_there_ready']);
        $this->assertSame([(int) $alpha->id, (int) $zeta->id], $this->userIds($data['always_there']));
        $this->assertSame(['user_id', 'first_name', 'last_name', 'is_self'], array_keys($data['always_there'][0]));
        $this->assertFalse($data['always_there'][0]['is_self']);
    }

    public function testVoiceGroupRaceRanksMainGroupsWithoutLowCounters(): void
    {
        $self = $this->member('Selbst', $this->soprano);
        // Weitere Sopranstimmen mit derselben Quote: Der Durchschnitt der Gruppe
        // bleibt 50 %, ruht aber auf AttendanceStanding::MIN_RACE_QUOTAS Quoten
        // und lässt sich damit auf keine einzelne zurückrechnen.
        $sopranoB = $this->member('SopranB', $this->soprano);
        $sopranoC = $this->member('SopranC', $this->soprano);
        $altoA = $this->member('AltA', $this->alto);
        $altoB = $this->member('AltB', $this->alto);
        $altoC = $this->member('AltC', $this->alto);
        $loose = $this->member('Lose', null);
        $this->events(4, [
            $self->id => ['present', 'present', 'unexcused', 'unexcused'],
            $sopranoB->id => ['present', 'unexcused', 'present', 'unexcused'],
            $sopranoC->id => ['unexcused', 'present', 'unexcused', 'present'],
            $altoA->id => ['present', 'present', 'present', 'present'],
            $altoB->id => ['present', 'present', 'present', 'unexcused'],
            $altoC->id => ['present', 'present', 'unexcused', 'unexcused'],
            $loose->id => ['present', 'present', 'present', 'present'],
        ]);

        $race = $this->renderAs(['user_id' => (int) $self->id])['voice_group_race'];

        // Chor-Reihenfolge bleibt (Sopran vor Alt); die Führung wird markiert, nicht sortiert.
        $this->assertSame(
            [$this->soprano->name, $this->alto->name],
            array_column($race, 'name'),
            'Ohne Stimmgruppe tritt niemand an.'
        );
        $this->assertFalse($race[0]['is_leader']);
        $this->assertSame(25.0, $race[0]['gap'], '50 % gegen 75 %.');
        $this->assertTrue($race[0]['is_own']);
        $this->assertTrue($race[1]['is_leader']);
        $this->assertSame(0.0, $race[1]['gap']);
        $this->assertFalse($race[1]['is_own']);
        $this->assertArrayNotHasKey('below_low', $race[0]);
    }

    public function testOwnVoiceGroupManagerSeesTheTableOnlyForTheOwnGroup(): void
    {
        $lead = $this->member('Leitung', $this->soprano);
        $sister = $this->member('Schwester', $this->soprano);
        $cousin = $this->member('Cousine', $this->soprano);
        $stranger = $this->member('Fremd', $this->alto);
        $this->events(3, [
            $lead->id => ['present', 'present', 'present'],
            $sister->id => ['unexcused', 'present', 'present'],
            $cousin->id => ['present', 'unexcused', 'present'],
            $stranger->id => ['unexcused', 'unexcused', 'unexcused'],
        ]);

        $data = $this->renderAs([
            'user_id' => (int) $lead->id,
            'can_manage_own_voice_group' => true,
            'voice_group_ids' => [(int) $this->soprano->id],
        ]);

        $this->assertTrue($data['can_view_details']);
        $this->assertEqualsCanonicalizing(
            [(int) $lead->id, (int) $sister->id, (int) $cousin->id],
            $this->userIds($data['stats'])
        );
        $this->assertSame([$this->soprano->name], array_column($data['voice_group_summary'], 'name'));
        // Der Vergleich bleibt chorweit - aber nur mit den Gruppen, deren
        // Durchschnitt auf mindestens AttendanceStanding::MIN_RACE_QUOTAS Quoten
        // ruht. Die fremde Stimmgruppe hat hier nur eine und tritt nicht an.
        $this->assertSame([$this->soprano->name], array_column($data['voice_group_race'], 'name'));
    }

    public function testComparisonWithTheOwnPreviousProject(): void
    {
        $previous = Project::create(['name' => 'Vorprojekt ' . $this->suffix, 'start_date' => '2026-03-01']);
        $self = $this->member('Selbst', $this->soprano);
        Capsule::table('project_users')->insert(['project_id' => $previous->id, 'user_id' => $self->id]);

        $this->events(4, [$self->id => ['present', 'unexcused', 'unexcused', 'present']], $previous);
        $this->events(4, [$self->id => ['present', 'present', 'present', 'unexcused']]);

        $own = $this->renderAs(['user_id' => (int) $self->id])['own_standing'];

        $this->assertSame('Vorprojekt ' . $this->suffix, $own['previous']['project_name']);
        $this->assertSame(50.0, $own['previous']['percentage']);
        $this->assertSame(25.0, $own['previous']['delta']);
    }

    public function testNoComparisonWithoutAPreviousProjectOfThreeEvents(): void
    {
        $previous = Project::create(['name' => 'Kurzprojekt ' . $this->suffix, 'start_date' => '2026-03-01']);
        $self = $this->member('Selbst', $this->soprano);
        Capsule::table('project_users')->insert(['project_id' => $previous->id, 'user_id' => $self->id]);
        $this->events(2, [$self->id => ['present', 'present']], $previous);
        $this->events(3, [$self->id => ['present', 'present', 'present']]);

        $own = $this->renderAs(['user_id' => (int) $self->id])['own_standing'];

        $this->assertNull($own['previous']);
        $this->assertTrue($own['is_always_there']);
        $this->assertSame(0, $own['events_to_always_there']);
    }

    public function testRenderedMemberPageShowsNoOtherNamesBelowTheThreshold(): void
    {
        $self = $this->member('Selbst', $this->soprano);
        $star = $this->member('Stern', $this->alto);
        $shy = $this->member('Selten', $this->alto);
        $this->events(4, [
            $self->id => ['present', 'excused', 'present', 'present'],
            $star->id => ['present', 'present', 'present', 'present'],
            $shy->id => ['unexcused', 'unexcused', 'present', 'unexcused'],
        ]);

        $_SESSION = ['user_id' => (int) $self->id];
        $body = (string) $this->controller($this->createAppTwig('/evaluations'))->index(
            $this->makeRequest('GET', '/evaluations', [], ['project_id' => (string) $this->project->id]),
            $this->makeResponse()
        )->getBody();

        $this->assertStringContainsString('Dein Stand', $body);
        $this->assertStringContainsString('75,0&nbsp;%', $body);
        $this->assertStringContainsString('Immer dabei', $body);
        $this->assertStringContainsString('Stern ' . $this->suffix, $body);
        $this->assertStringNotContainsString('Selten ' . $this->suffix, $body);
        $this->assertStringNotContainsString('Unentschuldigt', $body);
        $this->assertStringNotContainsString('id="evaluationsTable"', $body);
        $this->assertStringNotContainsString('bi-exclamation-triangle', $body);
    }


    /**
     * Ruht der Durchschnitt einer Stimmgruppe auf einer einzigen Quote, dann
     * *ist* er diese Quote - und der Vergleich zeigte jedem Mitglied den Stand
     * einer Person, die über die Besetzung namentlich zu bestimmen ist. Genau
     * das soll die Seite nicht: Fremdes erscheint nur im Positiven, der eigene
     * Stand bleibt privat.
     */
    public function testRaceDoesNotPublishTheQuotaOfALoneVoiceGroupMember(): void
    {
        $lonely = $this->member('Allein', $this->soprano);
        $altoA = $this->member('AltA', $this->alto);
        $altoB = $this->member('AltB', $this->alto);
        $altoC = $this->member('AltC', $this->alto);
        $this->events(4, [
            $lonely->id => ['unexcused', 'unexcused', 'unexcused', 'present'],
            $altoA->id => ['present', 'present', 'present', 'present'],
            $altoB->id => ['present', 'present', 'present', 'unexcused'],
            $altoC->id => ['present', 'present', 'unexcused', 'unexcused'],
        ]);

        $race = $this->renderAs(['user_id' => (int) $altoA->id])['voice_group_race'];

        $this->assertSame([$this->alto->name], array_column($race, 'name'));
        $this->assertNotContains(25.0, array_column($race, 'average'), 'Die Quote der Einzelperson ist 25 %.');
    }

    /**
     * Dieselbe Lage am gerenderten Seitentext: Weder Gruppenname noch Quote der
     * allein gezählten Person stehen im HTML.
     */
    public function testRenderedPageHidesTheLoneGroupFromTheComparison(): void
    {
        $lonely = $this->member('Allein', $this->soprano);
        $altoA = $this->member('AltA', $this->alto);
        $altoB = $this->member('AltB', $this->alto);
        $altoC = $this->member('AltC', $this->alto);
        $this->events(4, [
            $lonely->id => ['unexcused', 'unexcused', 'unexcused', 'present'],
            $altoA->id => ['present', 'present', 'present', 'present'],
            $altoB->id => ['present', 'present', 'present', 'unexcused'],
            $altoC->id => ['present', 'present', 'unexcused', 'unexcused'],
        ]);

        $_SESSION = ['user_id' => (int) $altoA->id];
        $body = (string) $this->controller($this->createAppTwig('/evaluations'))->index(
            $this->makeRequest('GET', '/evaluations', [], ['project_id' => (string) $this->project->id]),
            $this->makeResponse()
        )->getBody();

        $this->assertStringContainsString('Stimmen im Vergleich', $body);
        $this->assertStringContainsString($this->alto->name, $body);
        $this->assertStringNotContainsString($this->soprano->name, $body);
        $this->assertStringNotContainsString('25,0&nbsp;%', $body);
    }
}
