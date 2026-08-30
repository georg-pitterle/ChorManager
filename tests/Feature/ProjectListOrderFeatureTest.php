<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Queries\ProjectQuery;
use App\Services\NameFormatterService;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Alle Projektlisten sollen dieselbe Reihenfolge zeigen: zuletzt gestartet oben,
 * Projekte ohne Startdatum zuletzt, bei gleichem Datum alphabetisch. Vorher
 * sortierte getAllProjects() nach Startdatum und getAccessibleProjects() nach
 * Namen - dasselbe Mitglied sah die Liste je nach Seite in anderer Ordnung.
 */
class ProjectListOrderFeatureTest extends TestCase
{
    /** @var array<string, int> */
    private array $projectIds = [];

    private int $userId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Bootstrap::getCapsule()?->connection()->beginTransaction();

        // Zufälliges Namensfragment: die Testdatenbank bringt eigene Projekte mit,
        // die Zusicherungen filtern deshalb auf genau diese vier.
        $suffix = bin2hex(random_bytes(4));

        // Namen und Datumsreihenfolge laufen bewusst gegeneinander: alphabetisch
        // stünde "Adventkonzert" vorn, nach Startdatum steht es hinten.
        $projects = [
            'advent' => ['Adventkonzert ' . $suffix, '2026-01-10'],
            'sommer' => ['Sommerkonzert ' . $suffix, '2026-07-01'],
            'brahms' => ['Brahms-Abend ' . $suffix, '2026-07-01'],
            'ohneDatum' => ['Zukunftsprojekt ' . $suffix, null],
        ];

        foreach ($projects as $key => [$name, $startDate]) {
            $project = Project::create(['name' => $name, 'start_date' => $startDate]);
            $this->projectIds[$key] = (int) $project->id;
        }

        $user = User::create([
            'email' => 'listorder_' . $suffix . '@example.test',
            'password' => password_hash('secret', PASSWORD_BCRYPT),
            'first_name' => 'Hanna',
            'last_name' => 'Hauser',
            'is_active' => 1,
        ]);
        $this->userId = (int) $user->id;

        foreach ($this->projectIds as $projectId) {
            Capsule::table('project_users')->insert([
                'user_id' => $this->userId,
                'project_id' => $projectId,
            ]);
        }
    }

    protected function tearDown(): void
    {
        $connection = Bootstrap::getCapsule()?->connection();
        if ($connection !== null && $connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        parent::tearDown();
    }

    private function query(): ProjectQuery
    {
        return new ProjectQuery(new NameFormatterService());
    }

    /**
     * Reduziert das Ergebnis auf die hier angelegten Projekte, damit Bestandsdaten
     * der Testdatenbank die Reihenfolge nicht verfälschen.
     *
     * @return list<int>
     */
    private function ownIdsIn(Collection $projects): array
    {
        $known = array_values($this->projectIds);

        return array_values(array_filter(
            array_map(static fn ($project): int => (int) $project->id, $projects->all()),
            static fn (int $id): bool => in_array($id, $known, true)
        ));
    }

    /**
     * @return list<int>
     */
    private function expectedOrder(): array
    {
        return [
            // Juli 2026, bei gleichem Datum entscheidet der Name.
            $this->projectIds['brahms'],
            $this->projectIds['sommer'],
            // Januar 2026.
            $this->projectIds['advent'],
            // Ohne Startdatum ganz zuletzt.
            $this->projectIds['ohneDatum'],
        ];
    }

    public function testEveryProjectListUsesTheSharedOrder(): void
    {
        // Die Reihenfolge liegt am Model, damit auch Abfragen mit eigenen
        // Bedingungen sie bekommen. Wer sie abschreibt, faellt hier auf.
        $this->assertSame(
            $this->expectedOrder(),
            $this->ownIdsIn(Project::query()->chronological()->get())
        );
    }

    public function testNoControllerSortsProjectsAlphabeticallyAnymore(): void
    {
        // Acht Stellen sortierten ihre Projektliste frueher selbst nach Namen -
        // dieselbe Person sah die Projekte je nach Seite in anderer Ordnung.
        $sources = array_merge(
            glob(dirname(__DIR__, 2) . '/src/Controllers/*.php') ?: [],
            glob(dirname(__DIR__, 2) . '/src/Policies/*.php') ?: [],
            glob(dirname(__DIR__, 2) . '/src/Queries/*.php') ?: []
        );

        foreach ($sources as $path) {
            $content = (string) file_get_contents($path);

            foreach (
                [
                    "Project::orderBy('name'",
                    "Project::query()->orderBy('name'",
                    "orderBy('projects.name'",
                ] as $forbidden
            ) {
                $this->assertStringNotContainsString(
                    $forbidden,
                    $content,
                    basename($path) . ' sortiert Projekte selbst statt ueber chronological()'
                );
            }
        }
    }

    public function testAllProjectsAreOrderedByStartDate(): void
    {
        $this->assertSame(
            $this->expectedOrder(),
            $this->ownIdsIn($this->query()->getAllProjects()),
            'Die vollständige Projektliste beginnt beim zuletzt gestarteten Projekt.'
        );
    }

    public function testAccessibleProjectsUseTheSameOrderForTheBroadRight(): void
    {
        $this->assertSame(
            $this->expectedOrder(),
            $this->ownIdsIn($this->query()->getAccessibleProjects($this->userId, true)),
            'Das übergreifende Recht sieht dieselbe Reihenfolge wie die vollständige Liste.'
        );
    }

    public function testAccessibleProjectsUseTheSameOrderForOwnProjects(): void
    {
        $this->assertSame(
            $this->expectedOrder(),
            $this->ownIdsIn($this->query()->getAccessibleProjects($this->userId, false)),
            'Die eigenen Projekte folgen derselben Reihenfolge wie jede andere Projektliste.'
        );
    }

    public function testProjectsByIdsUseTheSameOrder(): void
    {
        // Absichtlich verdrehte Eingabereihenfolge: die Abfrage sortiert selbst.
        $shuffled = [
            $this->projectIds['ohneDatum'],
            $this->projectIds['advent'],
            $this->projectIds['sommer'],
            $this->projectIds['brahms'],
        ];

        $this->assertSame(
            $this->expectedOrder(),
            $this->ownIdsIn($this->query()->getProjectsByIds($shuffled)),
            'Auch die Auswahl über eine Id-Liste folgt der gemeinsamen Reihenfolge.'
        );
    }

    public function testProjectsByIdsWithoutIdsStaysEmpty(): void
    {
        $this->assertCount(
            0,
            $this->query()->getProjectsByIds([]),
            'Eine leere Id-Liste heißt "keine Projekte" und braucht keine Abfrage.'
        );
    }
}
