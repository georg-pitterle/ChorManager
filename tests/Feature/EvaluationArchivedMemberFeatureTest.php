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
 * Archivierte Projektmitglieder stehen in der Anwesenheitstabelle, gekennzeichnet.
 *
 * Die Besetzungsansicht desselben Projekts zeigt sie seit Lauf 87 mit - einen Klick
 * daneben fehlten sie in der Anwesenheitstabelle ganz. Wer jemanden archivierte, sah
 * dieselbe Projektbesetzung auf der einen Seite mit und auf der anderen ohne diese
 * Person, und die erfasste Anwesenheit eines ganzen Projekts verschwand rückwirkend.
 *
 * Die Kennzahlen daneben - Durchschnitt je Stimmgruppe, Stimmgruppen-Vergleich,
 * "immer dabei" - bleiben beim heutigen Chor: Sie beantworten "Steht die Besetzung?",
 * und darauf hat jemand, der nicht mehr mitsingt, keine Antwort.
 */
final class EvaluationArchivedMemberFeatureTest extends TestCase
{
    use AudienceFixtures;
    use TestHttpHelpers;
    use TwigViewStubs;
    use BuildsControllers;

    private EvaluationController $controller;
    /** @var array<int, array{0: string, 1: array<string, mixed>}> */
    private array $renderCalls = [];

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();
        $_SESSION = [];

        $this->renderCalls = [];
        $twig = $this->createStub(Twig::class);
        $twig->method('render')->willReturnCallback(
            function (ResponseInterface $response, string $template, array $data = []): ResponseInterface {
                $this->renderCalls[] = [$template, $data];
                return $response;
            }
        );

        $this->controller = $this->evaluationController([
            'view' => $twig,
            'projectQuery' => new ProjectQuery(new NameFormatterService()),
        ]);

        $evaluator = $this->member('Auswertende', 'Person');
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

    private function member(string $firstName, string $lastName, bool $active = true): User
    {
        return User::create([
            'first_name' => $firstName,
            'last_name' => $lastName . ' ' . bin2hex(random_bytes(3)),
            'email' => 'archived-eval-' . bin2hex(random_bytes(6)) . '@example.test',
            'password' => PasswordHasher::hash('test123'),
            'is_active' => $active ? 1 : 0,
        ]);
    }

    private function assignToProject(Project $project, User $user): void
    {
        Capsule::table('project_users')->insert([
            'project_id' => (int) $project->id,
            'user_id' => (int) $user->id,
        ]);
    }

    private function projectEvent(Project $project, int $daysAgo): Event
    {
        $startsAt = Carbon::now()->subDays($daysAgo)->setTime(19, 0);
        $event = Event::create([
            'title' => 'Probe ' . $startsAt->format('d.m.Y H:i'),
            'starts_at' => $startsAt,
            'ends_at' => (clone $startsAt)->addHours(2),
            'registration_enabled' => false,
            'attendance_required' => true,
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

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    private function statFor(array $data, int $userId): ?array
    {
        foreach ($data['stats'] as $stat) {
            if ((int) $stat['user_id'] === $userId) {
                return $stat;
            }
        }

        return null;
    }

    public function testAnArchivedMemberKeepsItsRowAndItsRecordedAttendance(): void
    {
        $project = Project::create(['name' => 'Archivprojekt ' . bin2hex(random_bytes(3))]);
        $active = $this->member('Aktive', 'Sängerin');
        $archived = $this->member('Archivierte', 'Sängerin', false);
        $this->assignToProject($project, $active);
        $this->assignToProject($project, $archived);

        foreach ([9, 6] as $daysAgo) {
            $event = $this->projectEvent($project, $daysAgo);
            foreach ([$active, $archived] as $person) {
                Attendance::create([
                    'event_id' => (int) $event->id,
                    'user_id' => (int) $person->id,
                    'status' => Attendance::STATUS_PRESENT,
                ]);
            }
        }

        $data = $this->renderIndex((int) $project->id);

        $archivedStat = $this->statFor($data, (int) $archived->id);
        self::assertNotNull($archivedStat, 'Das archivierte Mitglied fehlt in der Tabelle.');
        self::assertFalse($archivedStat['is_active'], 'Die Zeile muss als archiviert erkennbar sein.');
        self::assertSame(2, $archivedStat['present_count']);
        self::assertSame(2, $archivedStat['total_events'], 'Der Nenner muss auch für Archivierte gelten.');
        self::assertSame(100.0, $archivedStat['percentage']);

        $activeStat = $this->statFor($data, (int) $active->id);
        self::assertNotNull($activeStat);
        self::assertTrue($activeStat['is_active']);
    }

    /**
     * Der Durchschnitt je Stimmgruppe und der Stimmgruppen-Vergleich zählen nur den
     * heutigen Chor. Zählten sie archivierte Mitglieder mit, verschiebe ein
     * Archivieren rückwirkend die Kennzahl einer ganzen Stimmgruppe - und genau das
     * war der Anlass, die Zeile überhaupt stehen zu lassen.
     */
    public function testTheVoiceGroupFiguresStayWithTheCurrentEnsemble(): void
    {
        $group = VoiceGroup::create(['name' => 'Archivgruppe ' . bin2hex(random_bytes(3))]);
        $project = Project::create(['name' => 'Kennzahlprojekt ' . bin2hex(random_bytes(3))]);

        $active = $this->member('Aktive', 'Stimme');
        $archived = $this->member('Archivierte', 'Stimme', false);
        foreach ([$active, $archived] as $person) {
            $person->voiceGroups()->attach($group->id, ['sub_voice_id' => null]);
            $this->assignToProject($project, $person);
        }

        // Zwei Termine: die aktive Person immer da, die archivierte nie.
        foreach ([9, 6] as $daysAgo) {
            $event = $this->projectEvent($project, $daysAgo);
            Attendance::create([
                'event_id' => (int) $event->id,
                'user_id' => (int) $active->id,
                'status' => Attendance::STATUS_PRESENT,
            ]);
            Attendance::create([
                'event_id' => (int) $event->id,
                'user_id' => (int) $archived->id,
                'status' => Attendance::STATUS_UNEXCUSED,
            ]);
        }

        $data = $this->renderIndex((int) $project->id);

        $summary = null;
        foreach ($data['voice_group_summary'] as $row) {
            if ($row['name'] === $group->name) {
                $summary = $row;
            }
        }

        self::assertNotNull($summary, 'Die Stimmgruppe fehlt in der Zusammenfassung.');
        self::assertSame(1, $summary['member_count'], 'Nur die aktive Person zählt als Mitglied.');
        self::assertSame(1, $summary['quota_count']);
        self::assertSame(100.0, $summary['average'], 'Die 0 % der archivierten Person darf den Schnitt nicht drücken.');

        // Gegenprobe: Die Zeile selbst steht trotzdem in der Tabelle.
        self::assertNotNull($this->statFor($data, (int) $archived->id));
        self::assertSame(0.0, $this->statFor($data, (int) $archived->id)['percentage']);
    }

    /**
     * Die Vorlage selbst: Die Zeile des archivierten Mitglieds trägt den Hinweis
     * "Archiviert" und ist grau, so wie in der Besetzung daneben. Ohne diesen Test
     * wäre der Datenweg geprüft und die Kennzeichnung nicht - und eine Zeile ohne
     * sie ist schlimmer als keine Zeile: Sie liest sich wie ein aktives Mitglied mit
     * schlechter Quote.
     */
    public function testThePageMarksTheArchivedRow(): void
    {
        $controller = $this->evaluationController([
            'view' => $this->createAppTwig('/evaluations'),
            'projectQuery' => new ProjectQuery(new NameFormatterService()),
        ]);

        $project = Project::create(['name' => 'Vorlageprojekt ' . bin2hex(random_bytes(3))]);
        $archived = $this->member('Archivierte', 'Vorlagenstimme', false);
        $this->assignToProject($project, $archived);
        $event = $this->projectEvent($project, 4);
        Attendance::create([
            'event_id' => (int) $event->id,
            'user_id' => (int) $archived->id,
            'status' => Attendance::STATUS_PRESENT,
        ]);

        $body = (string) $controller->index(
            $this->makeRequest('GET', '/evaluations', [], ['project_id' => (string) $project->id]),
            $this->makeResponse()
        )->getBody();

        self::assertStringContainsString('id="evaluationsTable"', $body);

        // Geprüfte Stelle ist die Namenszelle selbst und nicht das Wort
        // irgendwo auf der Seite: "Archiviert" steht auch im Filter der
        // Mitgliederauswahl, und ein assertStringContainsString() darauf blieb
        // grün, als die Kennzeichnung zur Probe entfernt wurde - der Test
        // bewachte nichts.
        self::assertMatchesRegularExpression(
            '/' . preg_quote((string) $archived->last_name, '/')
                . '\\s*<span class="badge[^"]*">Archiviert<\\/span>/',
            $body
        );
        self::assertStringContainsString('class="text-muted"', $body);
    }
}
