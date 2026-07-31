<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Project;
use App\Queries\ProjectQuery;
use App\Services\NameFormatterService;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Auswertungen sollen ohne project_id-Parameter das aktuell laufende Projekt
 * vorauswaehlen (start_date <= heute <= end_date) und erst danach auf die
 * zuletzt gewaehlte Auswahl (users.last_project_id) zurueckfallen.
 */
class EvaluationDefaultProjectFeatureTest extends TestCase
{
    use EventScopeFixtures;

    private ProjectQuery $projectQuery;

    protected function setUp(): void
    {
        Bootstrap::setupTestDatabase();
        $this->beginFixtureTransaction();

        $this->projectQuery = new ProjectQuery(new NameFormatterService('last_first'));
    }

    protected function tearDown(): void
    {
        $this->rollBackFixtureTransaction();
        parent::tearDown();
    }

    public function testReturnsRunningProjectFromAccessibleProjects(): void
    {
        $past = $this->createProject('-12 months', '-6 months');
        $running = $this->createProject('-1 month', '+1 month');
        $future = $this->createProject('+2 months', '+8 months');

        $accessible = [(int) $past->id, (int) $running->id, (int) $future->id];

        $this->assertSame(
            (int) $running->id,
            $this->projectQuery->findCurrentProjectId($accessible)
        );
    }

    public function testReturnsRunningProjectEndingFirstWhenSeveralRun(): void
    {
        $endsLater = $this->createProject('-2 months', '+6 months');
        $endsSooner = $this->createProject('-1 month', '+1 month');

        $accessible = [(int) $endsLater->id, (int) $endsSooner->id];

        $this->assertSame(
            (int) $endsSooner->id,
            $this->projectQuery->findCurrentProjectId($accessible)
        );
    }

    public function testIgnoresRunningProjectOutsideAccessibleProjects(): void
    {
        $running = $this->createProject('-1 month', '+1 month');
        $accessibleOnly = $this->createProject('-12 months', '-6 months');

        $this->assertSame(
            0,
            $this->projectQuery->findCurrentProjectId([(int) $accessibleOnly->id])
        );
        $this->assertNotSame(0, (int) $running->id);
    }

    public function testReturnsZeroWithoutRunningProjectOrAccessibleProjects(): void
    {
        $past = $this->createProject('-12 months', '-6 months');

        $this->assertSame(0, $this->projectQuery->findCurrentProjectId([(int) $past->id]));
        $this->assertSame(0, $this->projectQuery->findCurrentProjectId([]));
    }

    public function testProjectsWithoutDatesAreNeverTreatedAsRunning(): void
    {
        $open = Project::create([
            'name' => 'Ohne Zeitraum ' . bin2hex(random_bytes(4)),
            'start_date' => null,
            'end_date' => null,
        ]);

        $this->assertSame(0, $this->projectQuery->findCurrentProjectId([(int) $open->id]));
    }

    private function createProject(string $startModifier, string $endModifier): Project
    {
        return Project::create([
            'name' => 'Auswertungs-Projekt ' . bin2hex(random_bytes(4)),
            'start_date' => Carbon::now()->modify($startModifier)->toDateString(),
            'end_date' => Carbon::now()->modify($endModifier)->toDateString(),
        ]);
    }

    public function testEvaluationControllerPrefersRunningProjectOverLastSelection(): void
    {
        $controller = file_get_contents(dirname(__DIR__) . '/../src/Controllers/EvaluationController.php');
        $this->assertIsString($controller);

        // Beide Auswertungs-Seiten muessen dieselbe Vorauswahl-Logik nutzen.
        $this->assertSame(
            2,
            substr_count($controller, '$projectId = $this->resolveDefaultProjectId($accessibleProjectIds, $userId);')
        );
        $this->assertStringNotContainsString('$projectId = $user->last_project_id;', $controller);

        $resolverStart = strpos($controller, 'private function resolveDefaultProjectId(');
        $this->assertIsInt($resolverStart);

        $resolver = substr($controller, $resolverStart);
        $currentPos = strpos($resolver, 'findCurrentProjectId($accessibleProjectIds)');
        $lastPos = strpos($resolver, 'last_project_id');

        $this->assertIsInt($currentPos);
        $this->assertIsInt($lastPos);
        $this->assertLessThan($lastPos, $currentPos, 'Laufendes Projekt muss vor last_project_id greifen.');
    }
}
